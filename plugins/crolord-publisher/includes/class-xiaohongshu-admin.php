<?php
if (!defined('ABSPATH')) { exit; }

final class Crolord_Publisher_XHS_Admin {
    public static function boot() { add_action('wp_ajax_crolord_publisher_xhs', array(__CLASS__, 'ajax')); add_action('admin_init', array('Crolord_Publisher_XHS', 'upgrade_receipts')); add_action('admin_post_crolord_publisher_xhs_verify', array(__CLASS__, 'open_verification')); }
    public static function open_verification() {
        $value = $_GET['job'] ?? '';
        if (!is_string($value) || !ctype_digit($value) || strlen($value) > 15 || (int) $value < 1 || !current_user_can('manage_options') || !current_user_can('crolord_publish_social')) { wp_die('无权打开此验证。', '', array('response' => 403)); }
        $id = (int) $value; check_admin_referer('crolord_publisher_xhs_verify_' . $id);
        $job = Crolord_Publisher_Store::get($id); $url = $job ? Crolord_Publisher_XHS_HTTP::verification_url($job) : '';
        if (!$url) { wp_die('本次验证入口已过期或任务状态已变化。请返回 Publisher 核对原任务。', '', array('response' => 410)); }
        nocache_headers(); header('Referrer-Policy: no-referrer');
        wp_redirect($url, 302, 'Publisher'); exit;
    }
    public static function status($message = '') {
        $c = Crolord_Publisher_XHS::config(); $has_session = !empty($c['session']); $readable = false; $guidance = '';
        try { Crolord_Publisher_XHS::cookie($c); $readable = true; } catch (Throwable $e) { $guidance = $e->getMessage(); }
        $connected = $readable && $c['verified'] && $c['uid'] && (int) $c['protocol'] === Crolord_Publisher_XHS::PROTOCOL;
        $session = Crolord_Publisher_XHS_Session::info($c); $enabled = $connected && $c['enabled'];
        if ($connected) { $guidance = $session['waiting'] ? $session['message'] : ($enabled ? '分享新的记录。' : '连接已就绪。'); }
        elseif ($readable) { $guidance = '已有会话尚未核验本人账号，请先检查账号连接。'; }
        $trace = Crolord_Publisher_XHS_HTTP::diagnostics()['发表笔记'] ?? array();
        $blocked = in_array((int) ($trace['http'] ?? 0), array(461, 471), true);
        $publication = array('blocked' => $blocked, 'message' => $blocked ? '最近一次发表等待平台验证（HTTP ' . (int) $trace['http'] . '）。请在 Publisher 打开对应任务。' : '');
        if ($blocked && !$session['waiting']) { $guidance = '账号已连接，最近一次发表尚未通过。'; }
        return array('connected' => (bool) $connected, 'enabled' => (bool) $enabled, 'uid' => $c['uid'], 'name' => $c['name'], 'state' => $connected ? ($enabled ? 'enabled' : 'paused') : ($has_session ? ($readable ? 'unverified' : 'unreadable') : 'missing'), 'state_label' => $enabled ? ($session['waiting'] ? '自动同步已启用 · ' . $session['label'] : '自动同步已启用') : '自动同步未启用', 'session' => $session, 'publication' => $publication, 'guidance' => $guidance, 'can_check' => $readable, 'can_inspect' => (bool) $connected, 'can_enable' => (bool) $connected && !$session['waiting'], 'can_pause' => (bool) $c['enabled'], 'can_disconnect' => (bool) ($has_session || $c['uid']), 'message' => $message);
    }
    private static function stamp($c) {
        return hash('sha256', wp_json_encode(array_intersect_key($c, array_flip(array('uid', 'revision', 'admin_revision', 'session', 'verified', 'enabled', 'protocol')))));
    }
    public static function operation($op, $input = array()) {
        $c = Crolord_Publisher_XHS::config(); $stamp = self::stamp($c);
        if ($op === 'inspect') { if (isset($input['cookie']) && (!is_string($input['cookie']) || trim($input['cookie']) !== '')) { throw new RuntimeException('填写了新的 Cookie，请先验证并连接本人会话，再读取诊断。'); } return self::inspect($c, $input); }
        $recover = false;
        if ($c['enabled'] && $c['verified'] && $c['uid'] && (int) $c['protocol'] === Crolord_Publisher_XHS::PROTOCOL) { try { Crolord_Publisher_XHS::cookie($c); $recover = true; } catch (Throwable $e) { /* Unreadable credentials require a fresh explicit enable. */ } }
        if (in_array($op, array('connect', 'check', 'enable'), true)) {
            if ($op !== 'connect' && isset($input['cookie']) && (!is_string($input['cookie']) || trim($input['cookie']) !== '')) { throw new RuntimeException('填写了新的 Cookie，请先点击“验证并连接本人会话”，不要直接检查或启用旧会话。'); }
            if ($op === 'connect' && (!is_string($input['cookie'] ?? null) || trim($input['cookie']) === '')) { throw new RuntimeException('请先粘贴本人创作中心的 Cookie 值，再点击“验证并连接本人会话”。'); }
            $cookie = $op === 'connect' ? Crolord_Publisher_XHS_HTTP::cookies(trim($input['cookie'])) : Crolord_Publisher_XHS::cookie($c);
            if ($op !== 'check' && !Crolord_Publisher_XHS_HTTP::materials($cookie)['complete']) { throw new RuntimeException('发表会话不完整：需要 a1 与官网 web_session。请在同一浏览器登录小红书官网与创作中心后复制完整 Cookie；已有会话未覆盖。'); }
            $id = Crolord_Publisher_XHS_HTTP::identity($cookie, $op !== 'connect');
            if ($c['uid'] && $id['uid'] !== $c['uid']) { throw new RuntimeException('账号与已绑定 UID 不同；请先断开旧账号。已有任务和笔记 ID 会保留。'); }
            $result = Crolord_Publisher_Store::locked(function () use ($op, $cookie, $id, $stamp, $recover) {
                $fresh = Crolord_Publisher_XHS::config();
                if (!hash_equals($stamp, self::stamp($fresh))) { throw new RuntimeException('连接或暂停状态已变化，请刷新后重新核对。'); }
                $was_verified = $fresh['verified'] && $fresh['uid'] && (int) $fresh['protocol'] === Crolord_Publisher_XHS::PROTOCOL;
                $fresh['uid'] = $id['uid']; $fresh['name'] = $id['name']; $fresh['verified'] = true;
                if (!$fresh['revision']) { $fresh['revision'] = bin2hex(random_bytes(16)); }
                $fresh['session'] = Crolord_Publisher_Store::encrypt($cookie); $fresh['protocol'] = Crolord_Publisher_XHS::PROTOCOL;
                $fresh = Crolord_Publisher_XHS_Session::connected_config($fresh, $cookie, $id['uid'], $op === 'connect');
                $fresh['admin_revision'] = bin2hex(random_bytes(16));
                if ($op === 'connect') { $fresh['enabled'] = (bool) $recover; }
                if ($op === 'check' && !$was_verified) { $fresh['enabled'] = false; }
                if ($op === 'enable') { $fresh['enabled'] = true; }
                Crolord_Publisher_XHS::save($fresh); return true;
            });
            if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
            try { Crolord_Publisher_XHS_Session::resume(); } catch (Throwable $e) { /* Connection proof remains valid if queue maintenance is temporarily unavailable. */ }
            $active = Crolord_Publisher_XHS::config()['enabled'];
            return self::status($op === 'enable' ? '小红书自动同步已启用；后续新文章默认加入，已有文章和任务不自动重发。' : ($active ? '本人 UID 读取已核验，尚未验证发表接口；未发送的等待任务按原排期继续，已有笔记仅核对同一 ID。' : '本人 UID 已核验。核对账号后启用自动同步；图片上传与发表仍需实际任务验证。'));
        }
        if (!in_array($op, array('pause', 'disconnect', 'limits'), true)) { throw new RuntimeException('未知的小红书操作。'); }
        $result = Crolord_Publisher_Store::locked(function () use ($op, $input) {
            $fresh = Crolord_Publisher_XHS::config();
            if ($op === 'pause') { $fresh['enabled'] = false; }
            if ($op === 'disconnect') { $fresh['session'] = ''; $fresh['session_jar'] = ''; $fresh['session_state'] = array(); $fresh['publish_problem'] = array(); $fresh['uid'] = ''; $fresh['name'] = ''; $fresh['verified'] = false; $fresh['enabled'] = false; $fresh['revision'] = bin2hex(random_bytes(16)); }
            if ($op !== 'limits') { $fresh['admin_revision'] = bin2hex(random_bytes(16)); }
            if ($op === 'limits') {
                $image = filter_var($input['image_mb'] ?? '', FILTER_VALIDATE_INT); $video = filter_var($input['video_mb'] ?? '', FILTER_VALIDATE_INT);
                if ($image === false || $image < 1 || $image > 20 || $video === false || $video < 1 || $video > 1024) { throw new RuntimeException('图片上限可设为 1–20 MB；视频上限为 1–1024 MB，仍受平台与站点限制。'); }
                $fresh['image_mb'] = $image; $fresh['video_mb'] = $video;
            }
            Crolord_Publisher_XHS::save($fresh); return true;
        });
        if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
        return self::status($op === 'limits' ? '文件大小上限已保存。' : ($op === 'disconnect' ? '已断开小红书；历史任务与已发布 ID 保留。' : '小红书新发表已暂停；已有笔记只能核对，不重发。'));
    }
    /** Bounded own-account GETs only; never authorize, resume, retry or publish any job. */
    private static function inspect($c, $input) {
        $page = filter_var($input['page'] ?? 0, FILTER_VALIDATE_INT);
        if ($page === false || $page < 0 || $page > 5 || !$c['uid'] || !$c['verified']) { throw new RuntimeException('请先核验本人账号；诊断页码范围为 0–5。'); }
        $cookie = Crolord_Publisher_XHS::cookie($c); $identity = Crolord_Publisher_XHS_HTTP::identity($cookie, false);
        if ($identity['uid'] !== $c['uid']) { throw new RuntimeException('本次只读核验返回了其他账号，已停止；没有更改绑定或任务。'); }
        $lines = array('Publisher ' . CROLORD_PUBLISHER_VERSION . ' · 小红书只读诊断', '本人 UID 读取通过；以下操作不发表、不重试、不恢复任务。');
        $notes = null;
        try {
            $data = Crolord_Publisher_XHS_HTTP::api('/api/galaxy/v2/creator/note/user/posted', 'GET', array('tab' => 0, 'page' => $page), $cookie, false, false);
            if (!is_array($data['notes'] ?? null) || count($data['notes']) > 100) { throw new RuntimeException('创作中心列表结构未核验，未据此认定是否发表。'); }
            $notes = $data['notes']; $lines[] = '已读取近期第 ' . ($page + 1) . ' 页，共 ' . count($notes) . ' 条；本页没有匹配不表示未发表。';
        } catch (Throwable $e) { $lines[] = '近期笔记读取未完成：' . $e->getMessage(); }
        $candidates = array(); $checked = 0;
        if ($notes !== null) { foreach (Crolord_Publisher_Store::jobs() as $job) {
            if ($checked >= 10) { break; }
            if (Crolord_Publisher_XHS::locally_held($job) && current_user_can('edit_post', $job['post_id'])) {
                $held = json_decode($job['payload'], true);
                if (is_array($held) && ($held['account'] ?? '') === $c['uid'] && hash_equals(Crolord_Publisher_XHS::binding($c), (string) ($held['xhs_binding'] ?? ''))) { $checked++; $lines[] = '任务 ' . (int) $job['id'] . '：本地暂停，尚未提交发表请求；下方历史发表响应不属于本条。可在队列单独恢复。'; }
                continue;
            }
            if ($job['platform'] !== 'xiaohongshu' || !current_user_can('edit_post', $job['post_id']) || !in_array($job['state'], array('unknown', 'xhs_session_wait'), true) || json_decode($job['remote_ids'], true)) { continue; }
            $p = json_decode($job['payload'], true); $progress = json_decode($job['progress'], true) ?: array();
            if (!is_array($p) || ($p['account'] ?? '') !== $c['uid'] || !hash_equals(Crolord_Publisher_XHS::binding($c), (string) ($p['xhs_binding'] ?? ''))) { continue; }
            $checked++; $found = array();
            foreach ($notes as $note) {
                if (!is_array($note)) { continue; }
                $id = $note['note_id'] ?? $note['noteId'] ?? $note['id'] ?? ''; $title = $note['display_title'] ?? $note['title'] ?? ''; $owner = $note['user']['userId'] ?? $note['user']['user_id'] ?? $note['user_id'] ?? '';
                if (!is_string($id) || !preg_match('/^[0-9a-f]{24}$/D', $id) || $title !== ($p['xhs_title'] ?? '') || ($owner !== '' && $owner !== $c['uid']) || isset($found[$id]) || count($found) >= 3) { continue; }
                $full = false; try { Crolord_Publisher_XHS::verify($note, $id, $p, $progress['xhs'] ?? array()); $full = true; } catch (Throwable $e) { /* Title/list presence never pretends to be full body/media evidence. */ }
                $found[$id] = array('job' => (int) $job['id'], 'id' => $id, 'verified' => $full);
            }
            $lines[] = '任务 ' . (int) $job['id'] . '（' . sanitize_text_field((string) ($p['xhs_title'] ?? '')) . '）：' . ($found ? '本页同标题候选 ' . count($found) . ' 条。' : '本页未找到同标题候选；不是未发表证明。');
            foreach ($found as $candidate) { $candidates[] = $candidate; $lines[] = '候选 ' . $candidate['id'] . ' · ' . ($candidate['verified'] ? '完整文字与媒体匹配' : '仅同标题，正文与图片未完整核验') . '；未自动登记或重发。'; }
        } }
        $lines[] = '各阶段最近一次请求（可能来自不同任务；未发送任务没有平台发表响应）：';
        foreach (Crolord_Publisher_XHS_HTTP::diagnostics() as $stage => $d) {
            $family = in_array($d['sign_family'] ?? '', array('XYW', 'XYS', 'other', 'none'), true) ? $d['sign_family'] : 'none';
            $svn = preg_match('/^[0-9]{1,3}$/D', (string) ($d['sign_svn'] ?? '')) ? (string) $d['sign_svn'] : '未提供';
            $verify = preg_match('/^[0-9]{1,4}$/D', (string) ($d['verify_type'] ?? '')) ? (string) $d['verify_type'] : '未提供';
            $version = preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', (string) ($d['plugin_version'] ?? '')) ? $d['plugin_version'] : '未记录';
            $when = !empty($d['at']) && is_numeric($d['at']) ? wp_date('Y-m-d H:i:s', (int) $d['at']) . '（站点时间）' : '时间未记录';
            $lines[] = $stage . '：HTTP ' . (int) ($d['http'] ?? 0) . '；验证类型 ' . $verify . '；签名 ' . $family . ' / ' . $svn . '；X-S-Common ' . (!empty($d['common_present']) ? '有' : '无') . '；RAP ' . (!empty($d['rap_present']) ? '有' : '无') . '；' . $when . '；版本 ' . $version;
            if ($stage === '发表笔记') { $lines[] = '该次发表请求结构：' . (($d['request_profile'] ?? '') === Crolord_Publisher_XHS::REQUEST_PROFILE ? Crolord_Publisher_XHS::REQUEST_PROFILE : '旧请求未记录，不能视为新版实测'); }
        }
        $m = Crolord_Publisher_XHS_HTTP::materials($cookie);
        $lines[] = '会话材料：a1 ' . ($m['a1'] ? '存在' : '缺少') . '；web_session ' . ($m['web_session'] ? '存在' : '缺少') . '；创作中心会话 ' . ($m['creator'] ? '存在' : '未单独提供') . '。不输出内容。';
        $lines[] = '当前使用独立任务流程；既有发表意图和笔记 ID 不会因升级、检查连接或新任务而清除。';
        $result = self::status('只读诊断完成；没有发表、重试或改写任务。'); $result['inspection'] = array('report' => implode("\n", $lines), 'candidates' => $candidates, 'page' => $page);
        return $result;
    }
    public static function ajax() {
        check_ajax_referer('crolord_publisher_ajax', 'nonce');
        if (!current_user_can('manage_options') || !current_user_can('crolord_publish_social')) { wp_send_json_error(array('message' => '没有账号管理权限。'), 403); }
        try { wp_send_json_success(self::operation(sanitize_key($_POST['operation'] ?? ''), wp_unslash($_POST))); }
        catch (Throwable $e) {
            $status = self::status($e->getMessage());
            if ($e instanceof Crolord_Publisher_XHS_Request_Error) { $status['problem'] = $e->diagnostic(); }
            wp_send_json_error($status, 400);
        }
    }
    public static function queue_error($job) {
        if (Crolord_Publisher_XHS::locally_held($job)) { return '旧版在本地暂停了这条尚未提交的任务。可恢复这一条同步；原文案、媒体和排期保留。'; }
        $progress = json_decode($job['progress'] ?? '', true); $d = $progress['xhs']['last_error'] ?? array();
        if (($d['stage'] ?? '') === '发表笔记' && ($d['kind'] ?? '') === 'verification' && in_array((int) ($d['http'] ?? 0), array(461, 471), true)) {
            if (json_decode($job['remote_ids'] ?? '[]', true)) { return '已保存真实笔记 ID；当前仅核对同一篇内容，不会重新发表。'; }
            return '发表笔记 · HTTP ' . (int) $d['http'] . '：平台拦截了本次发表，尚未取得可核验的笔记 ID。账号读取和图片上传成功不能证明发表可用，也不能据此认定 Cookie 已失效。请先核对创作中心，本条不会自动重发。';
        }
        return (string) ($job['error_text'] ?? '');
    }
    public static function render() {
        $c = Crolord_Publisher_XHS::config(); $info = self::status();
        $label = $info['connected'] ? $info['name'] . ' · UID ' . $info['uid'] : ($info['state'] === 'unreadable' ? '已保存会话需要更新' : ($info['state'] === 'unverified' ? '会话尚未完成账号核验' : '尚未连接小红书'));
        $flags = array_intersect_key($info, array_flip(array('connected', 'enabled', 'state', 'can_check', 'can_inspect', 'can_enable', 'can_pause', 'can_disconnect')));
        echo '<section class="cp-xhs" data-connection="' . esc_attr(wp_json_encode($flags)) . '"><h2>小红书</h2><p>图文与视频</p>';
        echo '<p class="cp-xhs-account"><strong>' . esc_html($label) . '</strong></p><p class="cp-xhs-state">' . esc_html($info['state_label']) . '</p><p class="cp-xhs-guidance">' . esc_html($info['guidance']) . '</p>';
        echo '<p class="cp-xhs-publication" role="status"' . ($info['publication']['message'] ? '' : ' hidden') . '>' . esc_html($info['publication']['message']) . '</p>';
        echo '<details class="cp-xhs-connection"' . ($info['connected'] ? '' : ' open') . '><summary>' . ($info['connected'] ? '更新本人会话' : '连接小红书 · 第一步') . '</summary><ol><li>在同一浏览器登录本人 <a href="https://www.xiaohongshu.com/" target="_blank" rel="noopener noreferrer">小红书官网</a> 与 <a href="https://creator.xiaohongshu.com/" target="_blank" rel="noopener noreferrer">创作中心</a>。</li><li>在开发者工具 Network 刷新创作中心，从当前请求的 Request Headers 复制完整 Cookie，包含官网 web_session。仅粘贴到下方密码框，不要发送到聊天。</li><li>验证后核对昵称和 UID，再启用自动同步。同一账号更新会话保留原来的启用或暂停选择。</li></ol><label for="cp-xhs-cookie">本人完整会话 Cookie</label><input id="cp-xhs-cookie" class="large-text cp-xhs-cookie" type="password" autocomplete="new-password" spellcheck="false" placeholder="粘贴完整 Cookie；不会回显已有会话"><p><button type="button" class="button button-primary cp-xhs-action" data-operation="connect">验证并连接本人会话</button></p></details>';
        echo '<div class="cp-xhs-actions"><button type="button" class="button cp-xhs-action" data-operation="check"' . ($info['can_check'] ? '' : ' disabled') . '>检查账号连接</button> <button type="button" class="button button-primary cp-xhs-action" data-operation="enable"' . ($info['can_enable'] ? '' : ' disabled') . '>启用自动同步</button> <button type="button" class="button cp-xhs-action" data-operation="pause"' . ($info['can_pause'] ? '' : ' disabled') . '>暂停</button></div><p class="cp-xhs-result" role="status" aria-live="polite"></p>';
        echo '<details class="cp-xhs-recovery"><summary>连接或发表遇到问题</summary><p><a href="https://creator.xiaohongshu.com/" target="_blank" rel="noopener noreferrer">打开创作中心</a></p><p>会话过期或账号读取要求本人验证时，更新会话后，尚未提交的等待任务按原排期继续。平台拦截最终发表请求时，仅保留当前任务的真实响应；请先在创作中心核对是否存在该篇，不能用账号检查通过代替发表验收。</p><p>已有笔记 ID 只核对同一篇，结果未知的任务不会自动重发。平台真正撤销的会话或人工验证仍需本人处理。</p></details>';
        echo '<details class="cp-xhs-inspection"><summary>请求诊断与近期笔记</summary><p>只读取本人账号和近期笔记，不发表、不重试，不显示 Cookie 或签名值。</p><button type="button" class="button cp-xhs-action" data-operation="inspect"' . ($info['can_inspect'] ? '' : ' disabled') . '>读取诊断与近期候选笔记</button><pre class="cp-xhs-report" style="white-space:pre-wrap;overflow-wrap:anywhere;max-width:100%" role="status" aria-live="polite"></pre></details>';
        echo '<details class="cp-xhs-limits"><summary>文件上限与连接管理</summary><div class="cp-fields"><label>单张图片 · MB<input class="cp-xhs-image-limit" type="number" min="1" max="20" value="' . (int) $c['image_mb'] . '"></label><label>单个视频 · MB<input class="cp-xhs-video-limit" type="number" min="1" max="1024" value="' . (int) $c['video_mb'] . '"></label></div><p><button type="button" class="button cp-xhs-action" data-operation="limits">保存上限</button> <button type="button" class="button cp-xhs-action" data-operation="disconnect"' . ($info['can_disconnect'] ? '' : ' disabled') . '>断开账号并暂停</button></p></details>';
        echo '</section>';
    }
    public static function row($job, $p, $progress, $ids) {
        $w = $progress['xhs'] ?? array(); $uploaded = count($w['pictures'] ?? array());
        if (($p['xhs_format'] ?? 'images') === 'video') { echo '<p>单个视频 · 已上传 ' . round((int) ($w['video']['bytes'] ?? 0) / 1048576, 1) . ' MB · ' . esc_html(($w['video']['stage'] ?? '') === 'ready' ? '上传完成' : '后台分片上传') . '</p>'; }
        else { echo '<p>计划 ' . count($p['images'] ?? array()) . ' 张 · 已上传 ' . $uploaded . ' 张' . (isset($w['receipt']['count']) ? ' · 最终附件 ' . (int) $w['receipt']['count'] . ' 张' : '') . '</p>'; }
        if (($w['receipt']['scope'] ?? '') === 'creator_list') { echo '<p class="description">创作中心已核对同一篇笔记及标题；完整正文与最终附件尚未核验。该笔记不会重新发表。</p>'; }
        if ($job['state'] === 'xhs_session_wait' && !$ids && empty($w['publish_started'])) { echo '<button type="button" class="button cp-action" data-operation="cancel">取消此条同步</button>'; }
        if (!empty($w['url']) && $ids) { echo '<p><a target="_blank" rel="noopener noreferrer" href="' . esc_url('https://www.xiaohongshu.com/explore/' . $ids[0]) . '">查看该篇小红书笔记</a></p>'; }
        if ($ids && !in_array($job['state'], array('running', 'publishing'), true)) { echo '<button type="button" class="button cp-action" data-operation="xhs_check">检查同一篇笔记</button>'; }
    }
    public static function publish_blocked($job, $progress) {
        $error = $progress['xhs']['last_error'] ?? array();
        return (($error['stage'] ?? '') === '发表笔记' && in_array((int) ($error['http'] ?? 0), array(461, 471), true)) || preg_match('/^发表笔记 · HTTP (461|471)(?: ·|：)/u', (string) ($job['error_text'] ?? '')) === 1;
    }
    public static function unknown($job, $progress) {
        $field = 'cp-xhs-receipt-' . (int) $job['id'];
        echo '<div class="cp-xhs-resolution">';
        if (self::publish_blocked($job, $progress)) {
            if (Crolord_Publisher_XHS_HTTP::verification_url($job)) {
                $entry = wp_nonce_url(add_query_arg(array('action' => 'crolord_publisher_xhs_verify', 'job' => (int) $job['id']), admin_url('admin-post.php')), 'crolord_publisher_xhs_verify_' . (int) $job['id']);
                echo '<p><a class="button button-primary cp-xhs-verify" data-verify-attempt="' . esc_attr(hash('sha256', $progress['xhs']['verification_scope'])) . '" target="_blank" rel="noopener noreferrer" href="' . esc_url($entry) . '">打开本次小红书验证</a></p><p>验证页会自动打开一次。在已登录同一账号的浏览器完成验证后，返回核对本篇；确认未发表再恢复这一条。平台再次要求验证时，仍需本人完成。</p>';
            } else { echo '<p class="cp-history-note">本次发表未取得笔记 ID，原响应没有可用的验证入口。先核对创作中心；确认未发表后可恢复这一条，读取平台的新响应。账号检查通过不代表发表校验已通过。</p>'; }
        }
        echo '<div class="cp-xhs-absent-path"><h4>创作中心没有这篇笔记</h4><p>此操作不需要填写笔记 ID。请先核对创作中心，包括待审核或定时内容。恢复后重新上传同一批原图，保留原文案、图片顺序和排期；回收站或未公开文章不会恢复。</p><label><input type="checkbox" class="cp-absent">我已核对，确认当前这一篇没有发布</label><button type="button" class="button cp-action" data-operation="reset_unknown" disabled>确认未发布，恢复此任务</button></div>';
        echo '<details class="cp-xhs-published-path"><summary>已经发表？登记真实笔记 ID</summary><p>仅在创作中心确实存在这篇笔记时使用。登记后只核对同一篇内容，不会重新发表。</p><label for="' . esc_attr($field) . '">已发表笔记的 24 位 ID</label><input id="' . esc_attr($field) . '" class="cp-receipt" type="text" autocomplete="off" spellcheck="false" maxlength="24" placeholder="24 位数字和 a–f 字母"><p><button type="button" class="button cp-action" data-operation="reconcile" disabled>登记已发表 ID 并核对</button></p></details>';
        echo '<p class="cp-client-error cp-error" role="alert"></p></div>';
    }
}
