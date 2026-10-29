<?php
if (!defined('ABSPATH')) { exit; }

/** Only local, bounded diagnostic fields; never retain a provider body or challenge token. */
final class Crolord_Publisher_XHS_Request_Error extends RuntimeException {
    private $diagnostic;
    public function __construct($message, $diagnostic) { parent::__construct($message); $this->diagnostic = $diagnostic; }
    public function diagnostic() { return $this->diagnostic; }
    public function needs_attention() { return in_array($this->diagnostic['kind'], array('verification', 'login', 'cookie', 'signature', 'restricted', 'permission', 'rate', 'denied'), true); }
}

/** Creator web protocol. Protocol references and MIT notices are in licenses/. */
final class Crolord_Publisher_XHS_HTTP {
    const UA = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    private static function stage($url) {
        $p = wp_parse_url($url); $path = $p['path'] ?? '';
        if ($path === '/api/galaxy/user/info') { return '账号核验'; }
        if ($path === '/api/media/v1/upload/web/permit') { return '申请媒体上传'; }
        if ($path === '/web_api/sns/v2/note') { return '发表笔记'; }
        if ($path === '/api/galaxy/v2/creator/note/user/posted') { return '创作中心核对笔记'; }
        return ($p['host'] ?? '') === 'ros-upload.xiaohongshu.com' ? '媒体上传' : '核对笔记';
    }
    private static function code($data) {
        $code = is_array($data) && is_scalar($data['code'] ?? null) ? (string) $data['code'] : '';
        return preg_match('/^-?[0-9]{1,9}$/D', $code) ? $code : '';
    }
    private static function kind($http, $code) {
        // Challenge statuses take priority over any unrelated JSON body.
        if (in_array($http, array(461, 471), true)) { return 'verification'; }
        if ($http === 406) { return 'signature'; }
        if ($code === '-100' || $http === 401) { return 'login'; }
        if ($code === '-101') { return 'cookie'; }
        if ($code === '300015') { return 'signature'; }
        if ($code === '300012') { return 'restricted'; }
        if ($code === '-104') { return 'permission'; }
        if ($http === 429) { return 'rate'; }
        return $http === 403 ? 'denied' : 'protocol';
    }
    private static function failure($kind, $stage, $http = 0, $code = '', $publishing = false, $safe = array()) {
        $detail = $stage . ($http ? ' · HTTP ' . $http : '') . ($code !== '' ? ' · 平台代码 ' . $code : '');
        $messages = array(
            'verification' => '小红书拦截了当前服务器请求。仅凭 HTTP 461／471 不能确认 Cookie 已失效；请先检查创作中心本人状态，若平台要求验证则由本人完成。电脑端通过不代表服务器端也已解除限制，请勿连续重试。',
            'login' => '小红书登录已失效，请在创作中心重新登录，再复制新的 Cookie 并验证连接。',
            'cookie' => '小红书未接受当前登录 Cookie，可能缺少登录项或会话不匹配。请复制已登录创作中心同一条请求的完整 Cookie 值，再验证连接。',
            'signature' => '小红书拒绝当前网页签名，需要核对接口协议；重新登录不一定能解决，请勿反复重试。',
            'restricted' => '小红书限制了当前网络请求。请先确认创作中心可正常使用；服务器与电脑的出口环境不同，重新复制 Cookie 不保证能解除服务器限制。',
            'permission' => '小红书拒绝当前账号的接口权限；这不等同于登录过期，请先在创作中心检查账号状态。',
            'rate' => '小红书限制了当前请求频率，已停止本次请求；请稍后手动检查连接。',
            'denied' => '小红书拒绝当前服务器请求，现有响应不足以确认原因；请检查创作中心会话与访问限制，未将其认定为必须人工验证。',
            'network' => '小红书连接未完成，请检查服务器到平台的网络后再手动检查。',
            'protocol' => '小红书未返回预期数据，需要核对创作中心会话与接口兼容性。',
            'receipt_redirect' => '公开笔记页发生跳转，尚不能读取详情；这不代表发表失败或创作中心会话失效。已有笔记 ID 保留，仅核对同一篇内容。',
        );
        if ($kind === 'verification' && $publishing) { $messages[$kind] = '小红书拦截了本次发表请求。账号读取和图片上传通过，不代表发表接口可用；这不能单独认定 Cookie 已失效。请先核对创作中心是否已有本篇笔记。'; }
        $message = $detail . '：' . $messages[$kind];
        if ($publishing) { $message .= ' 本次发表结果待核对，请先检查创作中心；不会自动重发。'; }
        return new Crolord_Publisher_XHS_Request_Error($message, array_merge(array('kind' => $kind, 'stage' => $stage, 'http' => $http, 'code' => $code, 'reconnect' => in_array($kind, array('verification', 'login', 'cookie'), true)), array_intersect_key($safe, array_flip(array('at', 'plugin_version', 'verify_type', 'challenge_present', 'sign_family', 'sign_svn', 'common_present', 'rap_present', 'request_profile')))));
    }
    private static function trace_key() { return 'crolord_publisher_xhs_trace_' . substr(Crolord_Publisher_XHS::binding(), 0, 24); }
    /** Deliberately omit all header values, response bodies, Cookie data, tokens, URLs and note text. */
    private static function trace($url, $method, $body, $headers, $response, $publishing) {
        $safe = array('at' => time(), 'plugin_version' => CROLORD_PUBLISHER_VERSION, 'stage' => self::stage($url), 'method' => $method, 'http' => is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response), 'code' => '', 'verify_type' => '', 'challenge_present' => false, 'sign_family' => 'none', 'sign_svn' => '', 'common_present' => false, 'rap_present' => false);
        $headers = array_change_key_case($headers, CASE_LOWER); $xs = (string) ($headers['x-s'] ?? '');
        $safe['sign_family'] = strpos($xs, 'XYW_') === 0 ? 'XYW' : (strpos($xs, 'XYS_') === 0 ? 'XYS' : ($xs ? 'other' : 'none'));
        if ($safe['sign_family'] === 'XYW' && strlen($xs) < 4096) { $envelope = json_decode(base64_decode(substr($xs, 4), true) ?: '', true, 8); $svn = $envelope['signSvn'] ?? ''; if (is_string($svn) && preg_match('/^[0-9]{1,3}$/D', $svn)) { $safe['sign_svn'] = $svn; } }
        $safe['common_present'] = !empty($headers['x-s-common']); $safe['rap_present'] = !empty($headers['x-rap-param']);
        if (!is_wp_error($response)) {
            $safe['code'] = self::code(json_decode(wp_remote_retrieve_body($response), true, 16, JSON_BIGINT_AS_STRING));
            foreach (array('verifytype', 'verify-type', 'x-verify-type') as $name) { $v = wp_remote_retrieve_header($response, $name); if (is_scalar($v) && preg_match('/^[0-9]{1,4}$/D', (string) $v)) { $safe['verify_type'] = (string) $v; break; } }
            $safe['challenge_present'] = (bool) wp_remote_retrieve_header($response, 'verifyuuid');
        }
        if ($publishing) {
            $safe['request_profile'] = Crolord_Publisher_XHS::REQUEST_PROFILE;
            $data = is_string($body) ? json_decode($body, true, 24) : null; $images = $data['image_info']['images'] ?? array();
            $safe['image_count'] = is_array($images) ? count($images) : 0;
            $safe['dimensions_complete'] = is_array($images) && $images && count(array_filter($images, function ($v) { return is_array($v) && (int) ($v['width'] ?? 0) > 0 && (int) ($v['height'] ?? 0) > 0; })) === count($images);
            $safe['post_loc_present'] = is_array($data['common'] ?? null) && array_key_exists('post_loc', $data['common']);
        }
        try { $all = self::diagnostics(); $all[$safe['stage']] = $safe; set_transient(self::trace_key(), $all, DAY_IN_SECONDS); } catch (Throwable $e) { /* Optional diagnostics cannot discard a successful note ID. */ }
        return $safe;
    }
    public static function diagnostics() {
        $data = get_transient(self::trace_key()); if (!is_array($data)) { return array(); }
        $data = array_intersect_key($data, array_flip(array('账号核验', '申请媒体上传', '媒体上传', '发表笔记', '核对笔记', '创作中心核对笔记')));
        return array_filter($data, 'is_array');
    }
    private static function verification_key($hash, $config) {
        return 'crolord_xhs_verify_' . hash('sha256', Crolord_Publisher_XHS::binding($config) . '|' . $hash);
    }
    /** Preserve a real platform challenge, never manufacture a proof of passing it. */
    private static function remember_verification($body, $headers, $response, $scope) {
        $http = (int) wp_remote_retrieve_response_code($response);
        $uuid = wp_remote_retrieve_header($response, 'verifyuuid');
        $type = wp_remote_retrieve_header($response, 'verifytype');
        if (!is_string($scope) || !preg_match('/^[0-9a-f]{32}$/D', $scope) || !in_array($http, array(461, 471), true) || !is_string($uuid) || !preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $uuid) || !is_scalar($type) || (string) $type !== '124' || !is_string($body)) { return false; }
        $c = Crolord_Publisher_XHS::config();
        $headers = array_change_key_case($headers, CASE_LOWER);
        $cookie = Crolord_Publisher_XHS::cookie($c);
        if (!$c['verified'] || !$c['uid'] || !hash_equals($cookie, (string) ($headers['cookie'] ?? ''))) { return false; }
        $record = array('uuid' => $uuid, 'http' => $http, 'type' => '124', 'at' => time(), 'request' => hash('sha256', $body), 'cookie' => hash('sha256', $cookie));
        return (bool) set_transient(self::verification_key($scope, $c), Crolord_Publisher_Store::encrypt(self::json($record)), 600);
    }
    /** The caller must be the authenticated admin UI; no credentials or ticket are returned by AJAX. */
    public static function verification_url($job) {
        if (($job['platform'] ?? '') !== 'xiaohongshu' || ($job['state'] ?? '') !== 'unknown' || !current_user_can('manage_options') || !current_user_can('crolord_publish_social') || !current_user_can('edit_post', (int) ($job['post_id'] ?? 0)) || json_decode($job['remote_ids'] ?? '[]', true) !== array()) { return ''; }
        $p = json_decode($job['payload'] ?? '', true); $progress = json_decode($job['progress'] ?? '', true); $w = $progress['xhs'] ?? array(); $hash = $w['request_sha256'] ?? '';
        $c = Crolord_Publisher_XHS::config(); $scope = $w['verification_scope'] ?? '';
        if (!is_string($scope) || !preg_match('/^[0-9a-f]{32}$/D', $scope) || !is_string($hash) || !preg_match('/^[0-9a-f]{64}$/D', $hash) || empty($w['publish_started']) || ($p['account'] ?? '') !== $c['uid'] || !hash_equals(Crolord_Publisher_XHS::binding($c), (string) ($p['xhs_binding'] ?? ''))) { return ''; }
        try {
            $cipher = get_transient(self::verification_key($scope, $c));
            $r = is_string($cipher) ? json_decode(Crolord_Publisher_Store::decrypt($cipher), true) : null;
            if (!is_array($r) || !is_string($r['uuid'] ?? null) || !preg_match('/^(?:[0-9a-f]{32}|[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/iD', $r['uuid']) || ($r['type'] ?? '') !== '124' || !in_array($r['http'] ?? 0, array(461, 471), true) || !is_int($r['at'] ?? null) || $r['at'] < time() - 600 || $r['at'] < (int) $w['publish_started'] || $r['at'] > time() + 5 || !hash_equals($hash, (string) ($r['request'] ?? '')) || !hash_equals((string) ($r['cookie'] ?? ''), hash('sha256', Crolord_Publisher_XHS::cookie($c)))) { return ''; }
            return 'https://www.xiaohongshu.com/website-login/captcha?' . http_build_query(array('redirectPath' => 'https://www.xiaohongshu.com/', 'verifyUuid' => $r['uuid'], 'verifyType' => '124', 'verifyBiz' => (string) $r['http']), '', '&', PHP_QUERY_RFC3986);
        } catch (Throwable $e) { return ''; }
    }
    public static function json($data) {
        $json = wp_json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) { throw new RuntimeException('小红书请求包含无效字符，未发送。'); }
        return $json;
    }
    public static function cookies($input) {
        if (!is_string($input) || strlen($input) > 24000 || preg_match('/[\x00-\x1f\x7f]/', $input)) { throw new RuntimeException('请粘贴本人创作中心请求的单行 Cookie 值。'); }
        $result = array();
        foreach (explode(';', $input) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2 || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/D', $pair[0]) || $pair[1] === '' || strlen($pair[1]) > 8192 || preg_match('/[\s,;]/', $pair[1])) { throw new RuntimeException('Cookie 格式无效，请仅复制 Cookie 请求头的值。'); }
            if (in_array(strtolower($pair[0]), array('domain', 'path', 'expires', 'max-age', 'samesite', 'secure', 'httponly'), true)) { throw new RuntimeException('请复制 Request Headers 中的 Cookie 值，而不是 Set-Cookie 响应头。'); }
            if (isset($result[$pair[0]])) { throw new RuntimeException('Cookie 中存在同名项，请从创作中心的同一条请求重新复制。'); }
            $result[$pair[0]] = $pair[1];
        }
        if (empty($result['a1']) || strlen($result['a1']) > 256) { throw new RuntimeException('未读取到创作中心的 a1 Cookie，请先在创作中心登录本人账号。'); }
        return implode('; ', array_map(function ($key) use ($result) { return $key . '=' . $result[$key]; }, array_keys($result)));
    }
    public static function cookie_value($cookie, $key) {
        foreach (explode(';', $cookie) as $part) { $pair = explode('=', trim($part), 2); if (count($pair) === 2 && $pair[0] === $key) { return $pair[1]; } }
        return '';
    }
    public static function materials($cookie) {
        $keys = array('a1' => false, 'web_session' => false, 'creator' => false);
        foreach (array('a1', 'web_session') as $key) { $keys[$key] = self::cookie_value($cookie, $key) !== ''; }
        foreach (array('galaxy_creator_session_id', 'access-token-creator.xiaohongshu.com', 'customer-sso-sid') as $key) { $keys['creator'] = $keys['creator'] || self::cookie_value($cookie, $key) !== ''; }
        $keys['complete'] = $keys['a1'] && $keys['web_session'];
        return $keys;
    }
    /** Sign the exact bytes sent; never reserialize after signing. */
    public static function sign($path, $body, $a1, $stamp = null) {
        if (!function_exists('openssl_encrypt')) { throw new RuntimeException('PHP 需要启用现有的 OpenSSL 扩展。'); }
        $stamp = $stamp ?? (string) (int) round(microtime(true) * 1000);
        $plain = 'x1=' . md5('url=' . $path . ($body ?? '')) . ';x2=0|0|0|1|0|0|1|0|0|0|1|0|0|0|0|1|0|0|0;x3=' . $a1 . ';x4=' . $stamp . ';';
        $encrypted = openssl_encrypt(base64_encode($plain), 'aes-128-cbc', '7cc4adla5ay0701v', OPENSSL_RAW_DATA, '4uzjr7mbsibcaldp');
        if ($encrypted === false) { throw new RuntimeException('小红书请求签名失败，未发送。'); }
        $envelope = array('signSvn' => '56', 'signType' => 'x2', 'appId' => 'ugc', 'signVersion' => '1', 'payload' => bin2hex($encrypted));
        return array('x-s' => 'XYW_' . base64_encode(self::json($envelope)), 'x-t' => (string) $stamp);
    }
    public static function api($path, $method, $data, $cookie, $publishing = false, $managed = true, $verification_scope = '') {
        $routes = array('/api/galaxy/user/info' => 'GET', '/api/galaxy/v2/creator/note/user/posted' => 'GET', '/api/media/v1/upload/web/permit' => 'GET', '/web_api/sns/v2/note' => 'POST');
        if (!isset($routes[$path]) || $routes[$path] !== $method) { throw new RuntimeException('小红书接口不在允许列表，未发送。'); }
        $uri = $path;
        if ($method === 'GET' && $data) { $uri .= '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986); }
        $body = $method === 'POST' ? self::json($data) : null;
        $host = strpos($path, '/api/galaxy/') === 0 ? 'creator.xiaohongshu.com' : 'edith.xiaohongshu.com';
        $context = Crolord_Publisher_XHS_Session::context($cookie, 'https://' . $host . $uri, $managed);
        try {
        $cookie = $context['header'];
        if (self::cookie_value($cookie, 'a1') === '') { throw self::failure('cookie', self::stage($context['url'])); }
        $headers = array_merge(array('Cookie' => $cookie, 'Content-Type' => 'application/json', 'Origin' => 'https://creator.xiaohongshu.com', 'Referer' => 'https://creator.xiaohongshu.com/'), self::sign($uri, $body, self::cookie_value($cookie, 'a1')));
        $r = self::request('https://' . $host . $uri, $method, $body, $headers, $publishing, false, $verification_scope);
        $d = json_decode($r['body'], true, 64, JSON_BIGINT_AS_STRING);
        $accepted=is_array($d)&&(($d['success']??null)===true||(($d['success']??null)!==false&&in_array($d['code']??null,array(0,'0'),true)));
        if (!$accepted || !is_array($d['data'] ?? null)) {
            $code = self::code($d);
            throw self::failure(self::kind($r['http'], $code), self::stage('https://' . $host . $uri), $r['http'], $code, $publishing, $r['diagnostic'] ?? array());
        }
        Crolord_Publisher_XHS_Session::received($context, $r['headers'], $path === '/api/galaxy/user/info');
        if ($publishing && $path === '/web_api/sns/v2/note') { Crolord_Publisher_XHS_Session::published($context, $d['data']['note_id'] ?? $d['data']['id'] ?? ''); }
        return $d['data'];
        } catch (Crolord_Publisher_XHS_Request_Error $e) { Crolord_Publisher_XHS_Session::failure($context, $e); throw $e; }
    }
    public static function request($url, $method, $body, $headers, $publishing = false, $html = false, $verification_scope = '') {
        $p = wp_parse_url($url);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new RuntimeException('小红书请求地址无效，未发送。'); }
        $host = $p['host'] ?? ''; $path = $p['path'] ?? '';
        $allowed = ($host === 'creator.xiaohongshu.com' && in_array($path, array('/api/galaxy/user/info', '/api/galaxy/v2/creator/note/user/posted'), true) && $method === 'GET') || ($host === 'edith.xiaohongshu.com' && (($path === '/api/media/v1/upload/web/permit' && $method === 'GET') || ($path === '/web_api/sns/v2/note' && $method === 'POST'))) || ($host === 'www.xiaohongshu.com' && preg_match('~^/explore/[0-9a-f]{24}$~D', $path) && $method === 'GET') || ($host === 'ros-upload.xiaohongshu.com' && self::file_id(ltrim($path, '/')) && in_array($method, array('PUT', 'POST'), true));
        if (!$allowed) { throw new RuntimeException('小红书请求域名或路径不在允许列表。'); }
        if ($host === 'ros-upload.xiaohongshu.com') {
            foreach (array_keys($headers) as $name) { if (!in_array(strtolower($name), array('x-cos-security-token', 'content-type'), true)) { throw new RuntimeException('上传请求包含不应传递的会话头，已停止。'); } }
        }
        $args = array('method' => $method, 'headers' => $headers, 'timeout' => $method === 'GET' ? 20 : 50, 'redirection' => 0, 'sslverify' => true, 'user-agent' => self::UA, 'limit_response_size' => $html ? 6 * 1024 * 1024 : 1024 * 1024);
        if ($body !== null) { $args['body'] = $body; $args['data_format'] = 'body'; }
        $response = wp_remote_request($url, $args);
        if ($publishing && !is_wp_error($response) && $host === 'edith.xiaohongshu.com' && $path === '/web_api/sns/v2/note') {
            try { self::remember_verification($body, $headers, $response, $verification_scope); } catch (Throwable $e) { /* Ticket storage never changes the platform result or retries a POST. */ }
        }
        try { $safe = self::trace($url, $method, $body, $headers, $response, $publishing); } catch (Throwable $e) { $safe = array(); }
        if (is_wp_error($response)) { throw self::failure('network', self::stage($url), 0, '', $publishing, $safe); }
        $http = (int) wp_remote_retrieve_response_code($response);
        if ($http < 200 || $http >= 300) {
            $code = self::code(json_decode(wp_remote_retrieve_body($response), true, 16, JSON_BIGINT_AS_STRING));
            if ($host === 'www.xiaohongshu.com' && $http >= 300 && $http < 400) { throw self::failure('receipt_redirect', '核对笔记', $http); }
            throw self::failure(self::kind($http, $code), self::stage($url), $http, $code, $publishing, $safe);
        }
        return array('body' => wp_remote_retrieve_body($response), 'headers' => wp_remote_retrieve_headers($response), 'http' => $http, 'diagnostic' => $safe);
    }
    public static function file_id($id) {
        if (!is_string($id) || !preg_match('~^[A-Za-z0-9_-]+(?:/[A-Za-z0-9_.-]+)*$~D', $id) || strlen($id) > 512) { return false; }
        foreach (explode('/', $id) as $part) { if ($part === '.' || $part === '..') { return false; } }
        return true;
    }
    public static function identity($cookie, $managed = true) {
        $cookie = self::cookies($cookie);
        $d = self::api('/api/galaxy/user/info', 'GET', null, $cookie, false, $managed);
        $role = is_string($d['role'] ?? null) ? $d['role'] : '';
        if (($d['guest'] ?? false) === true || ($d['logged_in'] ?? null) === false || preg_match('/guest|anonymous/i', $role)) { Crolord_Publisher_XHS_Session::identity_rejected($cookie); throw new RuntimeException('小红书返回访客会话，不是本人登录账号，未保存为已连接。'); }
        $info = isset($d['basic_info']) && is_array($d['basic_info']) ? $d['basic_info'] : (isset($d['user']) && is_array($d['user']) ? $d['user'] : $d);
        $uid = $info['user_id'] ?? $info['userId'] ?? $info['id'] ?? '';
        $name = $info['userName'] ?? $info['user_name'] ?? $info['nickname'] ?? $info['nick_name'] ?? $info['name'] ?? '';
        if (!is_string($uid) || !preg_match('/^[0-9a-f]{24}$/D', $uid) || !is_string($name) || $name === '') {
            $error = self::failure('protocol', '账号核验', 200);
            Crolord_Publisher_XHS_Session::identity_problem($cookie, $error);
            throw $error;
        }
        Crolord_Publisher_XHS_Session::identity_ok($cookie, $uid);
        return array('uid' => $uid, 'name' => sanitize_text_field($name));
    }
    public static function permit($kind, $cookie) {
        $d = self::api('/api/media/v1/upload/web/permit', 'GET', array('biz_name' => 'spectrum', 'scene' => $kind, 'file_count' => 1, 'version' => 1, 'source' => 'web'), $cookie);
        $p = $d['uploadTempPermits'][0] ?? null; $id = $p['fileIds'][0] ?? ''; $token = $p['token'] ?? '';
        if (!self::file_id($id) || !is_string($token) || !$token || strlen($token) > 16000 || preg_match('/[\x00-\x20\x7f]/', $token)) { throw new RuntimeException('小红书未返回有效上传凭据，未发表。'); }
        return array('file_id' => $id, 'token' => $token);
    }
    /** Static JSON state only: no JavaScript evaluation or arbitrary URL follows. */
    public static function note_from_html($html, $id) {
        if (!preg_match('/window\.__INITIAL_STATE__\s*=\s*/', $html, $match, PREG_OFFSET_CAPTURE)) { throw new RuntimeException('笔记详情尚不可读取，保留同一 ID 待核对。'); }
        $start = $match[0][1] + strlen($match[0][0]);
        if (($html[$start] ?? '') !== '{') { throw new RuntimeException('笔记详情结构已变化，未登记成功。'); }
        $depth = 0; $quoted = false; $escaped = false; $json = ''; $closed = false;
        for ($i = $start, $n = strlen($html); $i < $n; $i++) {
            $c = $html[$i];
            if ($quoted) { $json .= $c; if ($escaped) { $escaped = false; } elseif ($c === '\\') { $escaped = true; } elseif ($c === '"') { $quoted = false; } continue; }
            if ($c === '"') { $quoted = true; $json .= $c; continue; }
            if ($c === '{' || $c === '[') { $depth++; }
            if ($c === '}' || $c === ']') { $depth--; }
            if (substr($html, $i, 9) === 'undefined' && !preg_match('/[A-Za-z0-9_$]/', $html[$i - 1] ?? '') && !preg_match('/[A-Za-z0-9_$]/', $html[$i + 9] ?? '')) { $json .= 'null'; $i += 8; continue; }
            $json .= $c; if ($depth === 0) { $closed = true; break; }
        }
        $d = $closed ? json_decode($json, true, 80, JSON_BIGINT_AS_STRING) : null;
        $map = $d['note']['noteDetailMap'] ?? $d['note']['note_detail_map'] ?? array();
        $note = $map[$id]['note'] ?? null;
        if (!is_array($note)) { throw new RuntimeException('未读取到同一笔记的完整详情，保留待核对。'); }
        return $note;
    }
    public static function receipt($id, $cookie) {
        if (!preg_match('/^[0-9a-f]{24}$/D', $id)) { throw new RuntimeException('笔记 ID 无效。'); }
        $url = 'https://www.xiaohongshu.com/explore/' . $id;
        $context = Crolord_Publisher_XHS_Session::context($cookie, $url);
        $r = self::request($url, 'GET', null, array('Cookie' => $context['header'], 'Referer' => 'https://www.xiaohongshu.com/'), false, true);
        return self::note_from_html($r['body'], $id);
    }
    /** Read-only, own-account creator list; one bounded page per queue attempt. */
    public static function creator_receipt($id, $cookie, $page = 0) {
        if (!is_string($id) || !preg_match('/^[0-9a-f]{24}$/D', $id) || !is_int($page) || $page < 0 || $page > 5) { throw new RuntimeException('创作中心笔记核对参数无效。'); }
        $d = self::api('/api/galaxy/v2/creator/note/user/posted', 'GET', array('tab' => 0, 'page' => $page), $cookie);
        if (!isset($d['notes']) || !is_array($d['notes']) || count($d['notes']) > 100) { throw new RuntimeException('创作中心笔记列表结构尚未核对，已有 ID 保留。'); }
        $found = null;
        foreach ($d['notes'] as $note) {
            if (!is_array($note)) { continue; }
            $nid = $note['note_id'] ?? $note['noteId'] ?? $note['id'] ?? '';
            if ($nid === $id) {
                if ($found !== null) { throw new RuntimeException('创作中心返回重复笔记记录，保留待核对。'); }
                $found = $note;
            }
        }
        return array('note' => $found, 'next_page' => $page < 5 && in_array($d['has_more'] ?? false, array(true, 1, '1'), true) ? $page + 1 : 0);
    }
}
