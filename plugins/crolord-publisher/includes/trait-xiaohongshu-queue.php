<?php
if (!defined('ABSPATH')) { exit; }

/** Native PHP worker: one account/media step per lease, one durable final POST, then read-only receipts. */
trait Crolord_Publisher_XHS_Queue {
    public static function step($job, $p, $progress) {
        $ids = json_decode($job['remote_ids'], true);
        if (!is_array($ids) || $ids !== array_values($ids) || count($ids) > 1 || ($ids && (!is_string($ids[0]) || !preg_match('/^[0-9a-f]{24}$/D', $ids[0])))) { throw new RuntimeException('已保存的小红书 ID 结构异常，保留原记录，禁止发表。'); }
        if ($ids) { self::read_receipt($job, $p, $progress, $ids[0]); return; }
        $w = $progress['xhs'] ?? array();
        if (!empty($w['publish_started'])) { self::checkpoint($job, $progress, 0, array('state' => 'unknown', 'error_text' => '已有发表意图，尚未取得可靠笔记 ID；只核对原结果，不重复发表。')); return; }
        $valid = self::validate($p); if (is_wp_error($valid)) { throw new RuntimeException($valid->get_error_message()); }
        $info = Crolord_Publisher_XHS_Session::info();
        if ($info['waiting']) { self::checkpoint($job, $progress, 0, array('state' => 'xhs_session_wait', 'error_text' => $info['message'])); return; }
        self::source($job, $p, false);
        if ((int) ($w['adapter'] ?? 0) !== 2) {
            $old = array_intersect_key($w, array_flip(array('pictures', 'video', 'cover', 'identity_at')));
            if ($old) { $w['previous_uploads'][] = array('at' => time(), 'reason' => 'native_v2_upgrade', 'media' => self::public_media_history($old)); }
            foreach (array('pictures', 'video', 'cover', 'identity_at', 'identity_key', 'retry_authorization', 'local_hold', 'last_error') as $key) { unset($w[$key]); }
            $w['adapter'] = 2; $w['phase'] = 'account';
        }
        $cookie = self::cookie(); $context = Crolord_Publisher_XHS_Session::context($cookie, 'https://edith.xiaohongshu.com/web_api/sns/v2/note');
        if (empty($w['identity_at']) || $w['identity_at'] < time() - 300 || ($w['identity_key'] ?? '') !== $context['key']) {
            try { self::identity($cookie, $p); } catch (Crolord_Publisher_XHS_Request_Error $e) {
                $w['last_error'] = $e->diagnostic(); $progress['xhs'] = $w;
                self::checkpoint($job, $progress, 0, array('state' => Crolord_Publisher_XHS_Session::info()['waiting'] ? 'xhs_session_wait' : 'failed', 'error_text' => $e->getMessage())); return;
            }
            $w['identity_at'] = time(); $w['identity_key'] = $context['key'];
        }
        try {
            if (($p['xhs_format'] ?? 'images') === 'video') {
                $file = self::file($p['xhs_video'], $p['requested_by'], true);
                if (($w['video']['stage'] ?? '') !== 'ready') { self::video_step($file, $w, $cookie); $progress['xhs'] = $w; self::checkpoint($job, $progress); return; }
                if (empty($w['cover'])) { $w['cover'] = self::image($p['xhs_cover'], $p['requested_by'], $cookie); $w['phase'] = 'cover'; $progress['xhs'] = $w; self::checkpoint($job, $progress); return; }
            } else {
                $index = count($w['pictures'] ?? array());
                if ($index > count($p['images'])) { throw new RuntimeException('上传检查点多于计划原图，未发表。'); }
                if ($index < count($p['images'])) { $w['pictures'][] = self::image($p['images'][$index], $p['requested_by'], $cookie); $w['phase'] = 'images'; $progress['xhs'] = $w; self::checkpoint($job, $progress); return; }
            }
        } catch (Crolord_Publisher_XHS_Request_Error $e) {
            $w['last_error'] = $e->diagnostic(); $progress['xhs'] = $w;
            self::checkpoint($job, $progress, 0, array('state' => 'failed', 'error_text' => $e->getMessage())); return;
        }
        self::source($job, $p, true);
        $data = self::post_data($p, $w);
        $w['phase'] = 'publishing'; $w['publish_started'] = time(); $w['request_profile'] = self::REQUEST_PROFILE;
        $w['verification_scope'] = bin2hex(random_bytes(16));
        $w['request_sha256'] = hash('sha256', Crolord_Publisher_XHS_HTTP::json($data)); $progress['xhs'] = $w;
        $intent = Crolord_Publisher_Store::locked(function () use ($job, $p, $progress, $cookie) {
            $valid = self::validate($p); if (is_wp_error($valid)) { throw new RuntimeException($valid->get_error_message()); }
            self::source($job, $p, false);
            $fresh = Crolord_Publisher_Store::get($job['id']);
            if (!$fresh || $fresh['state'] !== 'running' || !hash_equals((string) $fresh['lease'], (string) $job['lease']) || $fresh['payload'] !== $job['payload'] || json_decode($fresh['remote_ids'], true) || !hash_equals(self::cookie(), $cookie)) { throw new RuntimeException('任务、账号连接或租约已变化，未提交发表。'); }
            Crolord_Publisher_Store::write($job, array('state' => 'publishing', 'progress' => wp_json_encode($progress))); return true;
        });
        if (is_wp_error($intent) || !$intent) { throw new RuntimeException('账号或任务正在更新，本次没有提交发表。'); }
        try {
            $response = Crolord_Publisher_XHS_HTTP::api('/web_api/sns/v2/note', 'POST', $data, $cookie, true, true, $w['verification_scope']);
            $id = $response['note_id'] ?? $response['noteId'] ?? $response['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[0-9a-f]{24}$/D', $id)) { throw new RuntimeException('发表响应没有可靠笔记 ID，保留结果待核对，禁止自动重发。'); }
        } catch (Throwable $e) {
            if ($e instanceof Crolord_Publisher_XHS_Request_Error) { $w['last_error'] = $e->diagnostic(); }
            $w['phase'] = 'publish_result'; $progress['xhs'] = $w;
            self::checkpoint($job, $progress, 0, array('state' => 'unknown', 'error_text' => $e->getMessage())); return;
        }
        $w['phase'] = 'receipt'; $w['url'] = 'https://www.xiaohongshu.com/explore/' . $id; $progress['xhs'] = $w;
        self::checkpoint($job, $progress, 20, array('remote_ids' => wp_json_encode(array($id))));
    }
    private static function source($job, $p, $media) {
        $post = get_post($job['post_id']);
        if (!$post || $post->post_status !== 'publish' || $post->post_password || !user_can($p['requested_by'] ?? 0, 'crolord_publish_social') || !user_can($p['requested_by'] ?? 0, 'edit_post', $job['post_id'])) { throw new RuntimeException('文章尚未公开、已进入回收站或原发布者权限已改变，未同步。'); }
        if ($media) {
            foreach ($p['images'] as $image) { self::file($image, $p['requested_by']); }
            if (!empty($p['xhs_video'])) { self::file($p['xhs_video'], $p['requested_by'], true); }
            if (!empty($p['xhs_cover'])) { self::file($p['xhs_cover'], $p['requested_by']); }
        }
    }
    private static function read_receipt($job, $p, $progress, $id) {
        $w = $progress['xhs'] ?? array();
        $valid = self::validate($p, true); if (is_wp_error($valid)) { throw new RuntimeException($valid->get_error_message()); }
        $info = Crolord_Publisher_XHS_Session::info(null, false);
        if ($info['waiting']) { self::checkpoint($job, $progress, 0, array('state' => 'xhs_session_wait', 'error_text' => $info['message'])); return; }
        try {
            $cookie = self::cookie(); self::identity($cookie, $p); $note = null;
            if (empty($w['public_redirect'])) {
                try { $note = Crolord_Publisher_XHS_HTTP::receipt($id, $cookie); }
                catch (Crolord_Publisher_XHS_Request_Error $e) {
                    if ($e->needs_attention()) { throw $e; }
                    if ($e->diagnostic()['kind'] === 'receipt_redirect') { $w['public_redirect'] = true; }
                } catch (RuntimeException $e) { /* Missing public JSON can still have a same-ID creator receipt. */ }
            }
            if ($note !== null) { $receipt = self::verify($note, $id, $p, $w); }
            else {
                $listing = Crolord_Publisher_XHS_HTTP::creator_receipt($id, $cookie, (int) ($w['creator_page'] ?? 0)); $w['creator_page'] = $listing['next_page'];
                if (!$listing['note']) { throw new RuntimeException('已有真实笔记 ID，创作中心暂未回读同一篇；只继续核对，不重新发表。'); }
                $receipt = self::creator_proof($listing['note'], $id, $p);
            }
            $recorded = ($receipt['scope'] ?? '') === 'creator_list'; $w['receipt'] = $receipt; $w['phase'] = $recorded ? 'recorded' : 'verified'; $w['url'] = 'https://www.xiaohongshu.com/explore/' . $id; $progress['xhs'] = $w;
            self::checkpoint($job, $progress, 0, array('state' => $recorded ? 'xhs_recorded' : 'succeeded'));
        } catch (Throwable $e) {
            $w['verify_tries'] = (int) ($w['verify_tries'] ?? 0) + 1; $w['phase'] = 'receipt';
            if ($e instanceof Crolord_Publisher_XHS_Request_Error) { $w['receipt_error'] = $e->diagnostic(); }
            $progress['xhs'] = $w; $needs = $e instanceof Crolord_Publisher_XHS_Request_Error && $e->needs_attention();
            if (Crolord_Publisher_XHS_Session::info(null, false)['waiting']) { self::checkpoint($job, $progress, 0, array('state' => 'xhs_session_wait', 'error_text' => $e->getMessage())); return; }
            self::checkpoint($job, $progress, $needs || $w['verify_tries'] >= 6 ? 0 : 30, array('state' => $needs || $w['verify_tries'] >= 6 ? 'external_paused' : 'external_pending', 'error_text' => $e->getMessage()));
        }
    }
    private static function public_media_history($old) {
        if (isset($old['video'])) { $old['video'] = array_intersect_key($old['video'], array_flip(array('file_id', 'video_id', 'bytes', 'stage'))); }
        return $old;
    }
    public static function locally_held($job) {
        if (($job['platform'] ?? '') !== 'xiaohongshu' || ($job['state'] ?? '') !== 'failed' || json_decode($job['remote_ids'] ?? '[]', true)) { return false; }
        $progress = json_decode($job['progress'] ?? '{}', true); $w = $progress['xhs'] ?? array();
        return empty($w['publish_started']) && (($w['local_hold']['reason'] ?? '') === 'publication_block' || strpos((string) ($job['error_text'] ?? ''), '小红书发表通道仍被平台拦截，任务与原图已保留；本次没有继续上传或发表。') === 0);
    }
    private static function action_write($job, $values) {
        global $wpdb; $values['touched'] = time();
        if (!$wpdb->update(Crolord_Publisher_Store::table(), $values, array('id' => $job['id'], 'state' => $job['state'], 'lease' => $job['lease'], 'remote_ids' => $job['remote_ids']))) { throw new RuntimeException('任务已变化，请刷新后核对。'); }
    }
    public static function action($job, $op) {
        $p = json_decode($job['payload'], true); $progress = json_decode($job['progress'], true) ?: array(); $w = $progress['xhs'] ?? array(); $ids = json_decode($job['remote_ids'], true) ?: array();
        if ($ids) {
            if ($op !== 'xhs_check' || in_array($job['state'], array('running', 'publishing'), true)) { throw new RuntimeException('已有笔记 ID，只核对同一篇，禁止再次发表。'); }
            $w['verify_tries'] = 0; $w['creator_page'] = 0; unset($w['public_redirect']); $progress['xhs'] = $w;
            self::action_write($job, array('state' => 'external_pending', 'lease' => '', 'available' => time(), 'progress' => wp_json_encode($progress), 'error_text' => '')); return true;
        }
        if ($job['state'] === 'unknown' && $op === 'reconcile') {
            $id = trim(wp_unslash($_POST['receipt'] ?? ''));
            if (!preg_match('/^[0-9a-f]{24}$/D', $id)) { throw new RuntimeException('请输入已发表笔记的 24 位真实 ID。'); }
            self::action_write($job, array('remote_ids' => wp_json_encode(array($id)), 'state' => 'external_pending', 'lease' => '', 'available' => time(), 'error_text' => '')); return true;
        }
        if ($job['state'] === 'unknown' && $op === 'reset_unknown') {
            if (empty($_POST['absent'])) { throw new RuntimeException('先核对创作中心，确认同一篇确实没有发表。'); }
            self::source($job, $p, true); $valid = self::validate($p);
            if (is_wp_error($valid)) { throw new RuntimeException($valid->get_error_message()); }
            $history = self::public_media_history(array_intersect_key($w, array_flip(array('pictures', 'cover', 'video'))));
            $w['previous_intents'][] = array('started' => $w['publish_started'] ?? 0, 'confirmed_absent_by' => get_current_user_id(), 'at' => time());
            $w['previous_uploads'][] = array('at' => time(), 'reason' => 'confirmed_absent_retry', 'pictures' => $history['pictures'] ?? array(), 'video' => $history['video'] ?? array(), 'cover' => $history['cover'] ?? array());
            foreach (array('pictures', 'cover', 'video', 'publish_started', 'identity_at', 'identity_key', 'last_error', 'receipt', 'receipt_error', 'url', 'verify_tries', 'creator_page', 'public_redirect', 'request_profile', 'request_sha256', 'verification_scope', 'retry_authorization', 'local_hold') as $key) { unset($w[$key]); }
            $w['adapter'] = 2; $w['phase'] = 'account'; $progress['xhs'] = $w; $at = max(time(), (int) ($p['not_before'] ?? 0));
            self::action_write($job, array('state' => $at > time() ? 'scheduled' : 'queued', 'lease' => '', 'available' => $at, 'error_text' => '', 'progress' => wp_json_encode($progress))); Crolord_Publisher::due($job['id'], $at); return true;
        }
        if (!empty($w['publish_started'])) { throw new RuntimeException('已有发表意图，请先核对原结果，不能通过普通重试清除。'); }
        if ($op === 'retry' && $job['state'] === 'failed') {
            if (empty($p['account']) && empty($p['xhs_binding'])) { $p = Crolord_Publisher::bind($p, 'xiaohongshu'); }
            self::source($job, $p, true); $valid = self::validate($p);
            if (is_wp_error($valid)) { throw new RuntimeException($valid->get_error_message()); }
            if (in_array($w['last_error']['stage'] ?? '', array('媒体上传', '申请媒体上传'), true)) {
                $w['previous_uploads'][] = array('at' => time(), 'reason' => 'upload_retry', 'media' => self::public_media_history(array_intersect_key($w, array_flip(array('pictures', 'video', 'cover')))));
                unset($w['pictures'], $w['video'], $w['cover'], $w['identity_at'], $w['identity_key']); $w['phase'] = 'account';
            }
            $progress['xhs'] = $w;
            $at = max(time(), (int) ($p['not_before'] ?? 0));
            self::action_write($job, array('progress' => wp_json_encode($progress), 'state' => $at > time() ? 'scheduled' : 'queued', 'payload' => wp_json_encode($p), 'available' => $at, 'lease' => '', 'error_text' => '')); return true;
        }
        if ($op === 'cancel' && $job['state'] === 'xhs_session_wait') { self::action_write($job, array('state' => 'cancelled', 'lease' => '', 'error_text' => '')); return true; }
        if ($op === 'xhs_retry_unsent') {
            if (!self::locally_held($job)) { throw new RuntimeException('只恢复旧版确实尚未发送的任务。'); }
            self::source($job, $p, true); $valid = self::validate($p);
            if (is_wp_error($valid)) { throw new RuntimeException($valid->get_error_message()); }
            $at = max(time(), (int) ($p['not_before'] ?? 0));
            self::action_write($job, array('state' => $at > time() ? 'scheduled' : 'queued', 'lease' => '', 'available' => $at, 'error_text' => '')); return true;
        }
        return null;
    }
}

