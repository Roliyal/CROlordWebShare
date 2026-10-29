<?php
if (!defined('ABSPATH')) { exit; }
final class Crolord_Publisher_Store {
    private static $read_failed = false;
    public static function read_failed() { return self::$read_failed; }
    public static function locked($work) {
        global $wpdb;
        $name = 'crolord_publisher_accounts_lock';
        $lease = (time() + 90) . '|' . wp_generate_uuid4();
        $held = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM $wpdb->options WHERE option_name=%s", $name));
        if ($held && (int) $held < time()) { $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", $name, $held)); }
        $created = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $wpdb->options (option_name,option_value,autoload) VALUES (%s,%s,'no')", $name, $lease));
        if ($created !== 1) { return new WP_Error('busy', '账号连接正在更新，请稍后再试。'); }
        wp_cache_delete('crolord_publisher_accounts', 'options');
        wp_cache_delete('crolord_publisher_buffer', 'options');
        wp_cache_delete('crolord_publisher_weibo_web', 'options');
        wp_cache_delete('crolord_publisher_weibo_api', 'options');
        wp_cache_delete('crolord_publisher_weibo_browser', 'options');
        wp_cache_delete('crolord_publisher_xiaohongshu', 'options');
        try { return $work(); }
        finally { $wpdb->query($wpdb->prepare("DELETE FROM $wpdb->options WHERE option_name=%s AND option_value=%s", $name, $lease)); }
    }
    public static function table() { global $wpdb; return $wpdb->prefix . 'crolord_publisher_jobs'; }
    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $table = self::table();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            post_id bigint(20) unsigned NOT NULL,
            platform varchar(16) NOT NULL,
            runner varchar(16) NOT NULL DEFAULT 'php',
            state varchar(24) NOT NULL DEFAULT 'queued',
            payload longtext NOT NULL,
            progress longtext NOT NULL,
            remote_ids longtext NOT NULL,
            error_text text NOT NULL,
            lease varchar(40) NOT NULL DEFAULT '',
            touched bigint(20) unsigned NOT NULL DEFAULT 0,
            available bigint(20) unsigned NOT NULL DEFAULT 0,
            attempts int unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY post_platform (post_id,platform),
            KEY queue_scan (state,available),
            KEY runner_scan (runner,state,available)
        ) " . $wpdb->get_charset_collate() . ';');
        // Upgrade unsent v1.0 X jobs without creating new posts or new queue rows.
        $last = 0;
        do {
            $rows = $wpdb->get_results($wpdb->prepare("SELECT id,payload,remote_ids FROM $table WHERE platform='x' AND state IN ('queued','scheduled','failed','cancelled') AND id>%d ORDER BY id LIMIT 100", $last), ARRAY_A);
            foreach ($rows as $row) {
                $last = (int) $row['id']; $payload = json_decode($row['payload'], true);
                if (!is_array($payload) || !empty($payload['format'])) { continue; }
                if (json_decode($row['remote_ids'], true)) { $wpdb->update($table, array('state'=>'succeeded','lease'=>''), array('id'=>$last)); continue; }
                $payload['images'] = array_slice($payload['images'], 0, 4); $payload['format'] = 2;
                $wpdb->update($table, array('payload'=>wp_json_encode($payload)), array('id'=>$last));
            }
        } while (count($rows) === 100);
        update_option('crolord_publisher_db', (defined('CROLORD_PUBLISHER_DB_VERSION') ? CROLORD_PUBLISHER_DB_VERSION : CROLORD_PUBLISHER_VERSION), false);
    }
    public static function get($id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE id=%d', $id), ARRAY_A);
    }
    public static function jobs($post_id = 0) {
        global $wpdb;
        $where = $post_id ? $wpdb->prepare(' WHERE post_id=%d', $post_id) : '';
        $rows = $wpdb->get_results('SELECT * FROM ' . self::table() . $where . ' ORDER BY id DESC LIMIT 50', ARRAY_A);
        if (!is_array($rows)) { self::$read_failed = true; return array(); }
        return $rows;
    }
    public static function latest_jobs() {
        global $wpdb;
        $table = self::table();
        $rows = $wpdb->get_results("SELECT * FROM $table WHERE post_id=(SELECT post_id FROM (SELECT post_id FROM $table ORDER BY id DESC LIMIT 1) AS latest_article) ORDER BY id DESC", ARRAY_A);
        if (!is_array($rows)) { self::$read_failed = true; return array(); }
        return $rows;
    }
    public static function enqueue($post_id, $platform, $payload, $manual) {
        global $wpdb;
        if ($platform === 'wechat') { throw new RuntimeException(Crolord_Publisher_Wechat_History::unavailable()->get_error_message()); }
        // The database constraint is the final authority, even with concurrent saves.
        $valid = Crolord_Publisher_Platforms::validate($platform, $payload);
        $available = max(time(), (int) ($payload['not_before'] ?? 0));
        $state = is_wp_error($valid) ? 'failed' : ($available > time() ? 'scheduled' : Crolord_Publisher::waiting_state($platform,$payload));
        $error = is_wp_error($valid) ? $valid->get_error_message() : '';
        if (is_wp_error($valid)) { $available = time(); }
        $runner=$platform==='weibo'&&Crolord_Publisher_Weibo_Browser::route($payload)?'browser':'php';
        $result = $wpdb->query($wpdb->prepare('INSERT IGNORE INTO ' . self::table() . " (post_id,platform,runner,state,payload,progress,remote_ids,error_text,touched,available) VALUES (%d,%s,%s,%s,%s,'{}','[]',%s,%d,%d)", $post_id, $platform, $runner, $state, wp_json_encode($payload), $error, time(), $available));
        if (false === $result) { throw new RuntimeException('无法保存发布任务，请检查数据库写入权限。文章本身已保存。'); }
        return 1 === $result ? (int) $wpdb->insert_id : 0;
    }
    public static function claim($target = 0) {
        global $wpdb;
        $table = self::table();
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='queued' WHERE runner='php' AND platform<>'wechat' AND state='scheduled' AND available<=%d", time()));
        // An interrupted final POST has an unknown outcome. Never silently repeat it.
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='unknown',error_text='工作进程中断，平台可能已接收；请核对平台后再处理。',lease='' WHERE runner='php' AND platform<>'wechat' AND state='publishing' AND touched<%d", time() - 300));
        $wpdb->query($wpdb->prepare("UPDATE $table SET state='queued',lease='' WHERE runner='php' AND platform<>'wechat' AND state='running' AND touched<%d", time() - 300));
        $filter = $target ? $wpdb->prepare(' AND id=%d', $target) : '';
        $id = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE runner='php' AND platform<>'wechat' AND state IN ('queued','external_pending') AND available<=%d" . $filter . ' ORDER BY id LIMIT 1', time()));
        if (!$id) { return null; }
        $lease = wp_generate_uuid4();
        $changed = $wpdb->query($wpdb->prepare("UPDATE $table SET state='running',lease=%s,touched=%d,attempts=attempts+1 WHERE id=%d AND runner='php' AND platform<>'wechat' AND state IN ('queued','external_pending')", $lease, time(), $id));
        return 1 === $changed ? self::get($id) : null;
    }
    public static function write($job, $values) {
        global $wpdb;
        $values['touched'] = time();
        $result = $wpdb->update(self::table(), $values, array('id' => $job['id'], 'lease' => $job['lease']));
        if (false === $result || !$result) { throw new RuntimeException('任务检查点保存失败，已停止继续发送。'); }
    }
    public static function retry($id) {
        global $wpdb;
        $job = self::get($id);
        if (!$job || $job['platform'] === 'wechat' || $job['state'] !== 'failed' || (int) $job['available'] > time()) { return 0; }
        $payload = json_decode($job['payload'], true);
        $available = max(time(), (int) ($payload['not_before'] ?? 0));
        return $wpdb->query($wpdb->prepare('UPDATE ' . self::table() . " SET state=%s,error_text='',lease='',available=%d,touched=%d WHERE id=%d AND state='failed' AND available<=%d", $available > time() ? 'scheduled' : 'queued', $available, time(), $id, time()));
    }
    public static function encrypt($plain) {
        if (!function_exists('openssl_encrypt')) { throw new RuntimeException('需要 PHP OpenSSL 扩展以加密授权。'); }
        $iv = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, $iv, $tag);
        if (false === $cipher) { throw new RuntimeException('授权加密失败。'); }
        return base64_encode($iv . $tag . $cipher);
    }
    public static function decrypt($cipher) {
        $raw = base64_decode($cipher, true);
        if (!$raw || strlen($raw) < 29 || !function_exists('openssl_decrypt')) { return ''; }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', hash('sha256', wp_salt('auth'), true), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return false === $plain ? '' : $plain;
    }
}
