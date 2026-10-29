<?php
/**
 * Plugin Name: Publisher
 * Description: 写在这里，也分享给远方。
 * Version: 1.24.6
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: issac
 * License: GPL-2.0-or-later
 * Text Domain: crolord-publisher
 */
if (!defined('ABSPATH')) { exit; }
define('CROLORD_PUBLISHER_VERSION', '1.24.6');
define('CROLORD_PUBLISHER_FILE', __FILE__);
// Load transports only when a hook or an explicit operation needs the class.
// Callback names remain registered normally, including REST, AJAX and WP-Cron.
define('CROLORD_PUBLISHER_DB_VERSION', '1.24.5');
spl_autoload_register(function ($class) {
    static $files = array(
        'crolord_publisher' => 'class-publisher.php',
        'crolord_publisher_admin' => 'class-admin.php',
        'crolord_publisher_automatic' => 'class-automatic.php',
        'crolord_publisher_buffer' => 'class-buffer.php',
        'crolord_publisher_clock' => 'class-clock.php',
        'crolord_publisher_diagnostics' => 'class-diagnostics.php',
        'crolord_publisher_media' => 'class-media.php',
        'crolord_publisher_media_error' => 'class-media.php',
        'crolord_publisher_oauth' => 'class-oauth.php',
        'crolord_publisher_platforms' => 'class-platforms.php',
        'crolord_publisher_poetic_slug' => 'class-poetic-slug.php',
        'crolord_publisher_preparation' => 'class-preparation.php',
        'crolord_publisher_share_metadata' => 'class-share-metadata.php',
        'crolord_publisher_store' => 'class-store.php',
        'crolord_publisher_wechat_history' => 'class-wechat-history.php',
        'crolord_publisher_weibo' => 'class-weibo.php',
        'crolord_publisher_weibo_admin' => 'class-weibo-admin.php',
        'crolord_publisher_weibo_api' => 'class-weibo-api.php',
        'crolord_publisher_weibo_api_error' => 'class-weibo-api.php',
        'crolord_publisher_weibo_browser' => 'class-weibo-browser.php',
        'crolord_publisher_weibo_connection_error' => 'class-weibo-session.php',
        'crolord_publisher_weibo_link_tail' => 'class-weibo-link-tail.php',
        'crolord_publisher_weibo_native' => 'class-weibo-native.php',
        'crolord_publisher_weibo_session' => 'class-weibo-session.php',
        'crolord_publisher_xhs' => 'class-xiaohongshu.php',
        'crolord_publisher_xhs_admin' => 'class-xiaohongshu-admin.php',
        'crolord_publisher_xhs_http' => 'class-xiaohongshu-http.php',
        'crolord_publisher_xhs_queue' => 'trait-xiaohongshu-queue.php',
        'crolord_publisher_xhs_request_error' => 'class-xiaohongshu-http.php',
        'crolord_publisher_xhs_session' => 'class-xiaohongshu-session.php',
    );
    $name = strtolower($class);
    if (isset($files[$name])) require_once __DIR__ . '/includes/' . $files[$name];
});
register_activation_hook(__FILE__, array('Crolord_Publisher', 'install'));
register_deactivation_hook(__FILE__, function () { wp_unschedule_hook('crolord_publisher_slug'); wp_unschedule_hook('crolord_publisher_prepare'); wp_clear_scheduled_hook('crolord_publisher_tick'); wp_unschedule_hook('crolord_publisher_due'); wp_unschedule_hook('crolord_publisher_xhs_resume'); wp_clear_scheduled_hook('crolord_publisher_duoplus_tick'); wp_clear_scheduled_hook('crolord_publisher_duoplus_watchdog'); });
add_action('plugins_loaded', array('Crolord_Publisher', 'boot'));
