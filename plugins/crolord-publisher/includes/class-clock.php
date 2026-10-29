<?php
if (!defined('ABSPATH')) { exit; }

/** An alarm in the existing Echoes service complements WP-Cron without busy browser polling. */
final class Crolord_Publisher_Clock {
    private static $dirty = false;
    public static function boot() {
        add_action('wp_ajax_crolord_publisher_clock', array(__CLASS__, 'callback'));
        add_action('wp_ajax_nopriv_crolord_publisher_clock', array(__CLASS__, 'callback'));
        add_action('save_post_post', array(__CLASS__, 'changed'), 100, 0);
        add_action('crolord_publisher_tick', array(__CLASS__, 'changed'), 100, 0);
        add_action('crolord_publisher_due', array(__CLASS__, 'changed'), 100, 0);
        add_action('crolord_publisher_slug', array(__CLASS__, 'changed'), 100, 0);
        add_action('crolord_publisher_prepare', array(__CLASS__, 'changed'), 100, 0);
        add_action('shutdown', array(__CLASS__, 'sync'), 100);
        add_action('admin_init', function () {
            if (current_user_can('manage_options') && !wp_doing_ajax() && !get_transient('crolord_publisher_clock_sync')) { self::changed(); }
        });
    }
    public static function changed() { self::$dirty = true; }
    private static function key() { return hash_hmac('sha256', 'publisher-clock-v1|' . home_url('/'), wp_salt('nonce')); }
    public static function next_due() {
        global $wpdb;
        $next = array(); $table = Crolord_Publisher_Store::table();
        $value = $wpdb->get_var("SELECT MIN(available) FROM $table WHERE runner='php' AND platform<>'wechat' AND state IN ('queued','scheduled','external_pending')");
        if ($value !== null) { $next[] = max(time(), (int) $value); }
        $stale = $wpdb->get_var("SELECT MIN(touched) FROM $table WHERE runner='php' AND platform<>'wechat' AND state IN ('running','publishing')");
        if ($stale !== null) { $next[] = max(time(), (int) $stale + 301); }
        $future = $wpdb->get_var("SELECT MIN(post_date_gmt) FROM $wpdb->posts WHERE post_type='post' AND post_status='future'");
        if ($future) { $next[] = max(time(), (int) strtotime($future . ' UTC')); }
        foreach ((array) _get_cron_array() as $timestamp => $events) {
            foreach (array('crolord_publisher_slug', 'crolord_publisher_prepare', 'crolord_publisher_xhs_resume') as $hook) {
                if (!empty($events[$hook])) { $next[] = max(time(), (int) $timestamp); }
            }
        }
        return $next ? min($next) : 0;
    }
    public static function sync($probe = false) {
        if (!self::$dirty || !class_exists('Crolord_Echoes_Account')) { return; }
        self::$dirty = false;
        $url = admin_url('admin-ajax.php');
        if (strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)) !== 'https') { return; }
        $next = self::next_due();
        if ($probe) { $next = $next ? min($next, time() + 2) : time() + 2; }
        $result = Crolord_Echoes_Account::request('publisher_clock', array('url' => $url, 'key' => self::key(), 'next_due' => $next), 3);
        if (!is_wp_error($result)) {
            update_option('crolord_publisher_clock', array_intersect_key($result, array_flip(array('enabled', 'next_due', 'last_ok', 'failures'))), false);
            set_transient('crolord_publisher_clock_sync', 1, 300);
        }
    }
    public static function callback() {
        $stamp = isset($_POST['stamp']) && is_string($_POST['stamp']) ? $_POST['stamp'] : '';
        $signature = isset($_POST['signature']) && is_string($_POST['signature']) ? $_POST['signature'] : '';
        if (!ctype_digit($stamp) || abs(time() - (int) $stamp) > 90 || !hash_equals(hash_hmac('sha256', 'publisher-clock|' . $stamp, hex2bin(self::key())), $signature)) { wp_die('', '', array('response' => 403)); }
        ignore_user_abort(true);
        if (function_exists('set_time_limit')) { @set_time_limit(120); }
        global $wpdb;
        $due = $wpdb->get_col($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE post_type='post' AND post_status='future' AND post_date_gmt<=%s ORDER BY post_date_gmt LIMIT 10", gmdate('Y-m-d H:i:s')));
        foreach ($due as $id) { check_and_publish_future_post((int) $id); }
        $count = 0;
        foreach ((array) _get_cron_array() as $timestamp => $events) {
            if ((int) $timestamp > time()) { break; }
            foreach (array('crolord_publisher_slug', 'crolord_publisher_prepare', 'crolord_publisher_xhs_resume') as $hook) {
                foreach ($events[$hook] ?? array() as $event) {
                    if ($count++ >= 3) { break 3; }
                    wp_unschedule_event((int) $timestamp, $hook, $event['args']);
                    do_action_ref_array($hook, $event['args']);
                }
            }
        }
        // Existing leases, account bindings and final-POST checkpoints remain authoritative.
        Crolord_Publisher::tick();
        $next = self::next_due();
        update_option('crolord_publisher_clock', array('enabled' => true, 'next_due' => $next, 'last_ok' => time(), 'failures' => 0), false);
        self::$dirty = false;
        wp_send_json_success(array('next_due' => $next));
    }
}
