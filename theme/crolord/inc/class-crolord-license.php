<?php
/** crolord online license client. Canonical source: shared/class-crolord-license.php.
 * GPL-2.0-or-later. Synced into each installable component by scripts/sync_license.py.
 */
if (!defined('ABSPATH')) exit;
if (!class_exists('Crolord_License_Client', false)) {
final class Crolord_License_Client {
    const OPTION = 'crolord_license_v1';
    const CRON = 'crolord_license_refresh_v1';
    private static $components = array();
    private static $booted = false;
    private static $memo = array();

    public static function register($product, $version) {
        self::$components[$product] = $version;
        if (self::$booted) return;
        self::$booted = true;
        add_action('admin_menu', array(__CLASS__, 'menu'));
        add_action('admin_post_crolord_license', array(__CLASS__, 'handle'));
        add_action(self::CRON, array(__CLASS__, 'refresh'));
        add_action('init', array(__CLASS__, 'schedule'));
        add_action('switch_blog', array(__CLASS__, 'reset'));
        add_action('admin_notices', array(__CLASS__, 'notice'));
    }

    public static function reset() { self::$memo = array(); }
    public static function enforced() { return defined('CROLORD_LICENSE_ENFORCE') && CROLORD_LICENSE_ENFORCE === true; }
    private static function endpoint() {
        $url = defined('CROLORD_LICENSE_SERVER') ? CROLORD_LICENSE_SERVER : '';
        if (!is_string($url) || strlen($url) > 2048) return '';
        $parts = wp_parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return '';
        return untrailingslashit($url);
    }
    private static function public_key() {
        $value = defined('CROLORD_LICENSE_PUBLIC_KEY') ? CROLORD_LICENSE_PUBLIC_KEY : '';
        $bytes = is_string($value) ? base64_decode($value, true) : false;
        return is_string($bytes) && strlen($bytes) === 32 ? $bytes : '';
    }
    public static function configured() { return self::endpoint() !== '' && self::public_key() !== ''; }
    public static function site() {
        $parts = wp_parse_url(home_url('/'));
        $scheme = strtolower($parts['scheme']); $host = strtolower($parts['host']);
        $port = (int) ($parts['port'] ?? 0);
        return $scheme . '://' . $host . ($port && $port !== ($scheme === 'https' ? 443 : 80) ? ':' . $port : '') . rtrim($parts['path'] ?? '', '/');
    }
    private static function crypto() {
        if (!class_exists('ParagonIE_Sodium_Compat') && !function_exists('sodium_crypto_sign_verify_detached')) {
            $file = ABSPATH . WPINC . '/sodium_compat/autoload.php';
            if (is_file($file)) require_once $file;
        }
        return function_exists('sodium_crypto_sign_verify_detached') || class_exists('ParagonIE_Sodium_Compat');
    }
    private static function decode($value) {
        if (!is_string($value) || $value === '' || !preg_match('/^[A-Za-z0-9_-]+$/D', $value)) return false;
        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    }
    private static function seal($value) {
        if (!self::crypto()) return false;
        try {
            $nonce = random_bytes(24); $key = hash('sha256', 'crolord-license|' . wp_salt('secure_auth'), true);
            $box = function_exists('sodium_crypto_secretbox') ? sodium_crypto_secretbox($value, $nonce, $key) : ParagonIE_Sodium_Compat::crypto_secretbox($value, $nonce, $key);
            return base64_encode($nonce . $box);
        } catch (Throwable $e) { return false; }
    }
    private static function open($value) {
        if (!is_string($value) || !self::crypto()) return false;
        $bytes = base64_decode($value, true);
        if (!is_string($bytes) || strlen($bytes) < 41) return false;
        try {
            $key = hash('sha256', 'crolord-license|' . wp_salt('secure_auth'), true);
            return function_exists('sodium_crypto_secretbox_open') ? sodium_crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), $key) : ParagonIE_Sodium_Compat::crypto_secretbox_open(substr($bytes, 24), substr($bytes, 0, 24), $key);
        } catch (Throwable $e) { return false; }
    }
    /** Verify issuer signature before reading claims; the server's JSON alone grants nothing. */
    public static function verify($envelope, $installation, $key_hash, $nonce = null) {
        if (!self::configured() || !self::crypto() || !is_array($envelope)) return false;
        $raw = self::decode($envelope['payload'] ?? ''); $sig = self::decode($envelope['signature'] ?? '');
        if (!is_string($raw) || strlen($raw) > 8192 || !is_string($sig) || strlen($sig) !== 64) return false;
        try {
            $ok = function_exists('sodium_crypto_sign_verify_detached') ? sodium_crypto_sign_verify_detached($sig, $raw, self::public_key()) : ParagonIE_Sodium_Compat::crypto_sign_verify_detached($sig, $raw, self::public_key());
        } catch (Throwable $e) { return false; }
        if (!$ok) return false;
        $p = json_decode($raw, true);
        if (!is_array($p) || ($p['schema'] ?? '') !== 'crolord-license.v1' || ($p['site'] ?? '') !== self::site() || ($p['installation'] ?? '') !== $installation || ($p['key_hash'] ?? '') !== $key_hash || ($nonce !== null && ($p['nonce'] ?? '') !== $nonce)) return false;
        foreach (array('issued_at', 'expires_at', 'offline_until', 'renew_after') as $key) if (!is_int($p[$key] ?? null)) return false;
        if ($p['issued_at'] > time() + 300 || ($nonce !== null && $p['issued_at'] < time() - 300) || !is_array($p['products'] ?? null) || !is_string($p['status'] ?? null)) return false;
        if ($p['status'] === 'active' && ($p['expires_at'] < $p['issued_at'] || $p['offline_until'] < $p['expires_at'] || $p['offline_until'] > $p['issued_at'] + 259200 || $p['renew_after'] > $p['expires_at'])) return false;
        foreach ($p['products'] as $product) if (!is_string($product)) return false;
        return $p;
    }
    public static function state() {
        $blog = get_current_blog_id();
        if (array_key_exists($blog, self::$memo)) return self::$memo[$blog];
        $saved = get_option(self::OPTION, array());
        $p = is_array($saved) ? self::verify($saved['lease'] ?? null, $saved['installation'] ?? '', $saved['key_hash'] ?? '') : false;
        return self::$memo[$blog] = $p;
    }
    public static function allowed($product) {
        if (!self::enforced()) return true;
        $p = self::state();
        return $p && $p['status'] === 'active' && $p['offline_until'] >= time() && in_array($product, $p['products'], true);
    }
    public static function error($product) {
        return self::allowed($product) ? null : new WP_Error('crolord_license_required', '此功能的站点授权尚未激活或已到期，请联系站点管理员。', array('status' => 403));
    }
    public static function ajax_guard($product) {
        $error = self::error($product);
        if ($error) wp_send_json_error(array('code' => $error->get_error_code(), 'message' => $error->get_error_message()), 403);
    }
    public static function write_guard($product) {
        $error = self::error($product);
        if ($error) wp_die(esc_html($error->get_error_message()), '', array('response' => 403));
    }
    private static function exchange($operation, $key, $installation) {
        if (!self::configured()) return new WP_Error('license_config', '请先配置 HTTPS 授权服务地址和发行公钥。');
        if (!is_string($key) || !preg_match('/^CRL-[A-Za-z0-9_-]{43}$/D', $key)) return new WP_Error('license_format', '授权码格式无效。');
        $nonce = bin2hex(random_bytes(16));
        $body = array('schema' => 'crolord-license.v1', 'license_key' => $key, 'site' => self::site(), 'installation' => $installation, 'nonce' => $nonce);
        $response = wp_safe_remote_post(self::endpoint() . '/v1/' . $operation, array('timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 16384, 'headers' => array('Content-Type' => 'application/json', 'Accept' => 'application/json'), 'body' => wp_json_encode($body)));
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('license_network', '授权服务暂时未连接；已验证的授权在有效宽限期内继续使用。');
        $lease = json_decode(wp_remote_retrieve_body($response), true);
        $p = self::verify($lease, $installation, hash('sha256', $key), $nonce);
        if (!$p) return new WP_Error('license_signature', '授权响应未通过签名或站点校验，原授权未被覆盖。');
        return array('lease' => $lease, 'claims' => $p);
    }
    private static function persist($saved) {
        $old = get_option(self::OPTION, array());
        if ($old !== $saved && !update_option(self::OPTION, $saved, false)) return new WP_Error('license_storage', '授权状态未能保存，请重试。');
        self::reset(); self::schedule();
        return true;
    }
    public static function activate($key) {
        $old = get_option(self::OPTION, array());
        if (!is_array($old)) $old = array();
        $installation = is_array($old) && preg_match('/^[a-f0-9]{32}$/D', $old['installation'] ?? '') ? $old['installation'] : bin2hex(random_bytes(16));
        $encrypted = self::seal($key);
        if (!$encrypted) return new WP_Error('license_crypto', '当前 PHP 环境无法加密保存授权码。');
        // Persist the installation ID before network I/O, so a lost response can be retried.
        if (!isset($old['installation'])) {
            $old['installation'] = $installation;
            $stored = self::persist($old); if (is_wp_error($stored)) return $stored;
        }
        $result = self::exchange('activate', $key, $installation);
        if (is_wp_error($result)) return $result;
        if ($result['claims']['status'] !== 'active') return new WP_Error('license_denied', '授权未激活：' . $result['claims']['status']);
        return self::persist(array('installation' => $installation, 'key_hash' => hash('sha256', $key), 'encrypted_key' => $encrypted, 'lease' => $result['lease']));
    }
    public static function refresh() {
        $saved = get_option(self::OPTION, array());
        $key = self::open($saved['encrypted_key'] ?? '');
        if (!$key) { self::schedule(); return new WP_Error('license_key_missing', '授权码不存在或站点加密盐已变更，请重新激活。'); }
        $result = self::exchange('refresh', $key, $saved['installation']);
        if (is_wp_error($result)) { self::schedule(); return $result; }
        $saved['lease'] = $result['lease'];
        return self::persist($saved);
    }
    public static function deactivate() {
        $saved = get_option(self::OPTION, array()); $key = self::open($saved['encrypted_key'] ?? '');
        if (!$key) return new WP_Error('license_key_missing', '无法读取授权码，请在授权服务端释放该站点。');
        $result = self::exchange('deactivate', $key, $saved['installation']);
        if (is_wp_error($result)) return $result;
        if (!in_array($result['claims']['status'], array('deactivated', 'activation_missing'), true)) return new WP_Error('license_release', '站点授权尚未释放，请稍后重试。');
        if (!delete_option(self::OPTION)) return new WP_Error('license_storage', '服务端已释放，但本地状态未能清除，请重试。');
        wp_clear_scheduled_hook(self::CRON); self::reset();
        return true;
    }
    public static function schedule() {
        if (!self::configured()) return;
        $saved = get_option(self::OPTION, array());
        if (!empty($saved['encrypted_key']) && !wp_next_scheduled(self::CRON)) wp_schedule_single_event(time() + 21600 + wp_rand(0, 300), self::CRON);
    }
    public static function menu() { add_options_page('crolord 在线授权', 'crolord 授权', 'manage_options', 'crolord-license', array(__CLASS__, 'page')); }
    public static function notice() {
        if (!self::enforced() || !current_user_can('manage_options')) return;
        foreach (array_keys(self::$components) as $product) if (!self::allowed($product)) {
            echo '<div class="notice notice-warning"><p>crolord 部分高级功能尚未取得有效授权。<a href="' . esc_url(admin_url('options-general.php?page=crolord-license')) . '">查看站点授权</a></p></div>';
            break;
        }
    }
    public static function handle() {
        if (!current_user_can('manage_options')) wp_die('权限不足。', '', array('response' => 403));
        check_admin_referer('crolord_license');
        $operation = isset($_POST['operation']) && is_string($_POST['operation']) ? $_POST['operation'] : '';
        $key = isset($_POST['license_key']) && is_string($_POST['license_key']) ? trim(wp_unslash($_POST['license_key'])) : '';
        if ($operation === 'activate') $result = self::activate($key);
        elseif ($operation === 'refresh') $result = self::refresh();
        elseif ($operation === 'deactivate') $result = self::deactivate();
        else $result = new WP_Error('license_action', '无效操作。');
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()), 'crolord 授权', array('response' => 400, 'back_link' => true));
        wp_safe_redirect(admin_url('options-general.php?page=crolord-license')); exit;
    }
    public static function page() {
        if (!current_user_can('manage_options')) return;
        $p = self::state(); $active = $p && $p['status'] === 'active' && $p['offline_until'] >= time();
        echo '<div class="wrap"><h1>crolord 在线授权</h1><p>站点：<code>' . esc_html(self::site()) . '</code></p>';
        echo '<p>服务配置：' . (self::configured() ? '已配置' : '未配置') . ' · 授权限制：' . (self::enforced() ? '已开启' : '未开启（兼容现有站点）') . '</p>';
        echo '<p>授权状态：' . esc_html($p ? ($active && time() > $p['expires_at'] ? '网络宽限期' : ($active ? '有效' : $p['status'])) : '未激活或校验失败') . '</p>';
        if ($p) echo '<p>离线使用截止：' . esc_html(wp_date('Y-m-d H:i:s T', $p['offline_until'])) . ' · 最近验证：' . esc_html(wp_date('Y-m-d H:i:s T', $p['issued_at'])) . '</p>';
        echo '<table class="widefat striped"><thead><tr><th>组件</th><th>版本</th><th>授权</th></tr></thead><tbody>';
        foreach (self::$components as $product => $version) echo '<tr><td>' . esc_html($product) . '</td><td>' . esc_html($version) . '</td><td>' . (self::allowed($product) ? (self::enforced() ? '已授权' : '兼容模式') : '待授权') . '</td></tr>';
        echo '</tbody></table><p>授权码加密保存在本站数据库，不显示完整码。正常浏览不发起授权请求；到期后保留已有内容展示，暂停受保护的高级操作。</p>';
        if (!self::configured()) echo '<p>请按仓库 docs/licensing.md 在 wp-config.php 配置服务地址与发行公钥，再开启授权限制。</p>';
        foreach (array('activate' => '激活 / 更换授权', 'refresh' => '立即验证', 'deactivate' => '释放本站授权') as $op => $label) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="margin-top:16px"><input type="hidden" name="action" value="crolord_license"><input type="hidden" name="operation" value="' . esc_attr($op) . '">';
            wp_nonce_field('crolord_license');
            if ($op === 'activate') echo '<label>授权码 <input type="password" class="regular-text" name="license_key" value="" autocomplete="new-password" required></label> ';
            submit_button($label, $op === 'activate' ? 'primary' : 'secondary', 'submit', false); echo '</form>';
        }
        echo '</div>';
    }
}
}
