<?php
if (!defined('ABSPATH')) { exit; }

/** Account state only; publish failures remain attached to their own immutable job. */
final class Crolord_Publisher_XHS_Session {
    const POLICY = 'native-account-v2';
    private static $identities = array();
    public static function boot() {
        self::upgrade();
        add_action('crolord_publisher_tick', array(__CLASS__, 'health'), 20);
        add_action('crolord_publisher_tick', array(__CLASS__, 'resume'), 21);
        add_action('crolord_publisher_xhs_resume', array(__CLASS__, 'resume'));
    }
    private static function key($cookie, $c) { return hash('sha256', $cookie . '|' . $c['uid'] . '|' . $c['revision']); }
    private static function current($context, $c) {
        if (empty($context['current']) || $context['uid'] !== $c['uid'] || $context['revision'] !== $c['revision'] || $context['admin_revision'] !== ($c['admin_revision'] ?? '')) { return false; }
        try { return hash_equals($context['key'], self::key(Crolord_Publisher_XHS::cookie($c), $c)); } catch (Throwable $e) { return false; }
    }
    public static function upgrade() {
        $c = Crolord_Publisher_XHS::config();
        if (!$c['session'] || !$c['uid'] || ($c['session_policy'] ?? '') === self::POLICY) { return; }
        try { Crolord_Publisher_Store::locked(function () {
            $c = Crolord_Publisher_XHS::config();
            if (!$c['session'] || !$c['uid'] || ($c['session_policy'] ?? '') === self::POLICY) { return; }
            $cookie = Crolord_Publisher_XHS::cookie($c); $old = $c['session_state'] ?? array();
            $kind = 'recheck'; // Retired policies mixed account and publication state; one identity GET establishes the new scope.
            $c['session_policy'] = self::POLICY;
            $c['session_state'] = array('key' => self::key($cookie, $c), 'kind' => $kind, 'checked' => (int) ($old['checked'] ?? 0), 'next' => 0, 'observed' => microtime(true));
            // Legacy encrypted jars and publish_problem are retained as inactive history.
            Crolord_Publisher_XHS::save($c);
        }); } catch (Throwable $e) { /* Explicit reconnection repairs unreadable data; upgrade never invents a session. */ }
    }
    public static function context($cookie, $url, $managed = true) {
        $cookie = Crolord_Publisher_XHS_HTTP::cookies($cookie); $p = wp_parse_url($url);
        if (($p['scheme'] ?? '') !== 'https' || !in_array($p['host'] ?? '', array('creator.xiaohongshu.com', 'edith.xiaohongshu.com', 'www.xiaohongshu.com'), true) || isset($p['port']) || isset($p['user']) || isset($p['pass']) || isset($p['fragment']) || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) { throw new RuntimeException('会话只能用于固定的小红书 HTTPS 地址。'); }
        $c = Crolord_Publisher_XHS::config(); $current = false;
        if ($managed && $c['session'] && $c['uid']) { try { $current = hash_equals(Crolord_Publisher_XHS::cookie($c), $cookie); } catch (Throwable $e) { /* Candidate credentials cannot mutate the saved owner. */ } }
        return array('base' => $cookie, 'header' => $cookie, 'url' => $url, 'key' => self::key($cookie, $c), 'uid' => $c['uid'], 'revision' => $c['revision'], 'admin_revision' => $c['admin_revision'] ?? '', 'current' => $current, 'started' => microtime(true));
    }
    public static function received($context, $headers, $identity = false) {
        if ($identity) { self::$identities[hash('sha256', $context['base'])] = $context; }
    }
    private static function state($context, $kind) {
        if (empty($context['current'])) { return; }
        try { Crolord_Publisher_Store::locked(function () use ($context, $kind) {
            $c = Crolord_Publisher_XHS::config(); $old = $c['session_state'] ?? array();
            if (!self::current($context, $c) || (float) ($old['observed'] ?? 0) > $context['started']) { return; }
            if ($kind === 'temporary' && in_array($old['kind'] ?? '', array('expired', 'verification', 'account', 'unavailable'), true)) { return; }
            $c['session_state'] = array('key' => $context['key'], 'kind' => $kind, 'checked' => time(), 'next' => $kind === 'valid' ? time() + 21600 : ($kind === 'temporary' ? time() + 900 : 0), 'observed' => $context['started']);
            Crolord_Publisher_XHS::save($c);
        }); } catch (Throwable $e) { /* Optional bookkeeping cannot discard an actual platform result. */ }
    }
    public static function identity_ok($cookie, $uid) {
        $context = self::$identities[hash('sha256', $cookie)] ?? null;
        if ($context && !empty($context['current'])) { self::state($context, $uid === $context['uid'] ? 'valid' : 'account'); }
    }
    public static function identity_rejected($cookie) {
        $context = self::$identities[hash('sha256', $cookie)] ?? null;
        if ($context) { self::state($context, 'expired'); }
    }
    public static function identity_problem($cookie, $error) {
        $context = self::$identities[hash('sha256', $cookie)] ?? null;
        if ($context) { self::failure($context, $error); }
    }
    public static function failure($context, $error) {
        if (empty($context['current']) || !($error instanceof Crolord_Publisher_XHS_Request_Error)) { return; }
        $d = $error->diagnostic();
        // Only an actual identity response changes account availability.
        if (($d['stage'] ?? '') !== '账号核验') { return; }
        $reason = $d['kind'] ?? '';
        if (in_array($reason, array('login', 'cookie'), true)) { $kind = 'expired'; }
        elseif ($reason === 'verification') { $kind = 'verification'; }
        elseif (in_array($reason, array('network', 'rate'), true) || (int) ($d['http'] ?? 0) >= 500) { $kind = 'temporary'; }
        elseif (in_array($reason, array('signature', 'restricted', 'permission', 'denied', 'protocol'), true)) { $kind = 'unavailable'; }
        else { $kind = ''; }
        if ($kind !== '') { self::state($context, $kind); }
    }
    public static function connected_config($c, $cookie, $uid, $reset = false) {
        $c['session_policy'] = self::POLICY;
        $c['session_state'] = array('key' => self::key($cookie, $c), 'kind' => 'valid', 'checked' => time(), 'next' => time() + 21600, 'observed' => microtime(true));
        return $c;
    }
    public static function published($context, $id) { /* The per-job native ID is authoritative, not an account-wide circuit. */ }
    public static function publication_info($config = null) {
        return array('blocked' => false, 'http' => 0, 'at' => 0, 'message' => '账号、上传与发表分别核验；连接通过不等于发表成功。');
    }
    public static function info($config = null, $writing = true) {
        $c = $config ?? Crolord_Publisher_XHS::config();
        try { $cookie = Crolord_Publisher_XHS::cookie($c); $key = self::key($cookie, $c); }
        catch (Throwable $e) { return array('kind' => empty($c['session']) ? 'missing' : 'unreadable', 'label' => '等待本人连接', 'waiting' => true, 'needs_user' => true, 'checked' => 0, 'next' => 0, 'materials' => array(), 'message' => $e->getMessage()); }
        $s = $c['session_state'] ?? array(); if (($s['key'] ?? '') !== $key) { $s = array(); }
        $kind = in_array($s['kind'] ?? '', array('valid', 'recheck', 'temporary', 'expired', 'verification', 'account', 'unavailable'), true) ? $s['kind'] : 'valid';
        $materials = Crolord_Publisher_XHS_HTTP::materials($cookie);
        if ($writing && $kind === 'valid' && !$materials['complete']) { $kind = 'materials'; }
        $messages = array('valid' => '本人会话已保存，后续任务复用；每条任务独立核对实际发表结果。', 'recheck' => '等待只读核对本人账号，原任务和排期保留。', 'temporary' => '账号读取暂时异常，稍后只读检查；没有重新发表。', 'expired' => '平台未接受当前登录会话，请本人重新连接；原任务和 ID 保留。', 'verification' => '账号核验要求本人验证；停止后台反复检查。', 'account' => '本次 UID 与已绑定账号不同，未改绑或发表。', 'materials' => '缺少官网 web_session。请在同一浏览器登录小红书官网与创作中心后，更新完整 Cookie；原任务保留。');
        $labels = array('valid' => '账号已核验', 'recheck' => '等待账号核对', 'temporary' => '等待连接自动恢复', 'expired' => '会话待更新', 'verification' => '账号核验请求被拦截', 'account' => '账号不一致', 'materials' => '发表会话不完整');
        $messages['unavailable'] = '账号核验接口未通过，已保留会话与任务。请查看请求诊断并检查接口兼容性；这不等于登录过期，不会反复请求或继续发表。';
        $labels['unavailable'] = '账号接口异常';
        return array('kind' => $kind, 'label' => $labels[$kind], 'waiting' => $kind !== 'valid', 'needs_user' => in_array($kind, array('expired', 'verification', 'account', 'materials', 'unavailable'), true), 'checked' => (int) ($s['checked'] ?? 0), 'next' => (int) ($s['next'] ?? 0), 'materials' => $materials, 'message' => $messages[$kind]);
    }
    public static function health() {
        $c = Crolord_Publisher_XHS::config();
        if (!$c['enabled'] || !$c['verified'] || !$c['uid'] || !$c['session']) { return; }
        $info = self::info($c, false); if ($info['needs_user'] || $info['next'] > time()) { return; }
        $cookie = Crolord_Publisher_XHS::cookie($c); $key = self::key($cookie, $c);
        try { $lease = Crolord_Publisher_Store::locked(function () use ($key) {
            $c = Crolord_Publisher_XHS::config(); $info = self::info($c, false);
            if (!$c['enabled'] || $info['needs_user'] || $info['next'] > time() || !hash_equals(self::key(Crolord_Publisher_XHS::cookie($c), $c), $key)) { return false; }
            $c['session_state']['next'] = time() + 90; Crolord_Publisher_XHS::save($c); return true;
        }); } catch (Throwable $e) { return; }
        if (is_wp_error($lease) || !$lease) { return; }
        try { Crolord_Publisher_XHS_HTTP::identity($cookie); self::resume(); } catch (Throwable $e) { /* Actual identity errors control bounded recovery. */ }
    }
    public static function resume() {
        $c = Crolord_Publisher_XHS::config(); if (!$c['uid'] || !$c['verified'] || self::info($c, false)['waiting']) { return 0; }
        try { $result = Crolord_Publisher_Store::locked(function () {
            $c = Crolord_Publisher_XHS::config(); if (!$c['uid'] || !$c['verified'] || self::info($c, false)['waiting']) { return 0; }
            $writing = $c['enabled'] && !self::info($c)['waiting'];
            global $wpdb;
            $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Crolord_Publisher_Store::table() . " WHERE platform='xiaohongshu' AND state='xhs_session_wait' AND payload LIKE %s ORDER BY id LIMIT 50", '%"account":"' . $c['uid'] . '"%'), ARRAY_A); $count = 0;
            foreach ((array) $rows as $j) {
                $p = json_decode($j['payload'], true); $progress = json_decode($j['progress'], true); $w = $progress['xhs'] ?? array(); $ids = json_decode($j['remote_ids'], true);
                if (!is_array($p) || !is_array($ids) || $ids !== array_values($ids) || count($ids) > 1 || ($ids && (!is_string($ids[0]) || !preg_match('/^[0-9a-f]{24}$/D', $ids[0]))) || ($p['account'] ?? '') !== $c['uid'] || (!$ids && (!$writing || !empty($w['publish_started']) || !hash_equals(Crolord_Publisher_XHS::binding($c), (string) ($p['xhs_binding'] ?? ''))))) { continue; }
                $at = max(time() + $count * 3, $ids ? 0 : (int) ($p['not_before'] ?? 0));
                if ($wpdb->update(Crolord_Publisher_Store::table(), array('state' => $ids ? 'external_pending' : ($at > time() ? 'scheduled' : 'queued'), 'lease' => '', 'available' => $at, 'error_text' => '', 'touched' => time()), array('id' => $j['id'], 'state' => 'xhs_session_wait', 'lease' => $j['lease'], 'remote_ids' => $j['remote_ids']))) { $count++; Crolord_Publisher::due($j['id'], $at); }
            }
            return $count;
        }); } catch (Throwable $e) { return 0; }
        return is_wp_error($result) ? 0 : (int) $result;
    }
}

