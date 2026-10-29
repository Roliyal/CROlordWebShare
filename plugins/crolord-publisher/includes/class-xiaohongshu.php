<?php
if (!defined('ABSPATH')) { exit; }

/** Native PHP queue adapter. No shell, browser, remote signer or external runner. */
final class Crolord_Publisher_XHS {
    use Crolord_Publisher_XHS_Queue;
    const OPTION = 'crolord_publisher_xiaohongshu';
    const PROTOCOL = 1;
    const REQUEST_PROFILE = 'creator-1.20.3';
    const CHUNK = 5242880;
    public static function config() {
        return wp_parse_args(get_option(self::OPTION, array()), array('session' => '', 'uid' => '', 'name' => '', 'verified' => false, 'enabled' => false, 'revision' => '', 'admin_revision' => '', 'protocol' => self::PROTOCOL, 'image_mb' => 5, 'video_mb' => 100));
    }
    public static function save($c) { update_option(self::OPTION, $c, false); }
    /** Upgrade only recent, same-account receipt reads paused on a public-page redirect. */
    public static function upgrade_receipts() {
        if (get_option('crolord_publisher_xhs_receipts_1210', false)) { return 0; }
        $c = self::config();
        if (!$c['uid'] || !$c['verified'] || !$c['session']) { return 0; }
        global $wpdb; $table = Crolord_Publisher_Store::table();
        $jobs = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE platform='xiaohongshu' AND state='external_paused' AND error_text LIKE %s ORDER BY id DESC LIMIT 50", '核对笔记 · HTTP 30%'), ARRAY_A);
        if (!is_array($jobs)) { return 0; }
        $changed = 0; $failed = false;
        foreach ($jobs as $job) {
            $ids = json_decode($job['remote_ids'], true); $p = json_decode($job['payload'], true);
            if (!is_array($ids) || count($ids) !== 1 || !isset($ids[0]) || !is_string($ids[0]) || !preg_match('/^[0-9a-f]{24}$/D', $ids[0]) || !is_array($p) || ($p['account'] ?? '') !== $c['uid']) { continue; }
            $progress = json_decode($job['progress'], true) ?: array(); if (!is_array($progress)) { continue; }
            $progress['xhs']['verify_tries'] = 0; $progress['xhs']['creator_page'] = 0; $progress['xhs']['public_redirect'] = true;
            $at = time() + $changed * 5;
            $ok = $wpdb->update($table, array('state' => 'external_pending', 'lease' => '', 'available' => $at, 'error_text' => '', 'progress' => wp_json_encode($progress), 'touched' => time()), array('id' => $job['id'], 'state' => 'external_paused', 'remote_ids' => $job['remote_ids'], 'lease' => $job['lease']));
            if ($ok) { $changed++; Crolord_Publisher::due($job['id'], $at); }
            elseif ($ok === false) { $failed = true; }
        }
        if (!$failed) { update_option('crolord_publisher_xhs_receipts_1210', 1, false); }
        if ($changed) { add_action('shutdown', array('Crolord_Publisher', 'kick')); }
        return $changed;
    }
    public static function enabled() {
        $c = self::config();
        if (!$c['enabled'] || !$c['verified'] || !$c['uid'] || (int) $c['protocol'] !== self::PROTOCOL) { return false; }
        try { self::cookie($c); return true; } catch (Throwable $e) { return false; }
    }
    public static function binding($c = null) { $c = $c ?? self::config(); return hash('sha256', $c['uid'] . '|' . $c['revision'] . '|xhs1'); }
    public static function cookie($config = null) {
        $c = $config ?? self::config(); $saved = $c['session'] ?? '';
        if ($saved === '' || $saved === null) { throw new RuntimeException('尚未连接小红书。请先填写本人创作中心 Cookie，点击“验证并连接本人会话”，核对账号后再启用自动同步。'); }
        $cookie = is_string($saved) ? Crolord_Publisher_Store::decrypt($saved) : '';
        if (!$cookie) { throw new RuntimeException('已保存的小红书会话无法解密，可能与站点加密密钥变化或保存数据损坏有关。请重新填写并验证本人 Cookie；历史任务与笔记 ID 会保留。'); }
        try { return Crolord_Publisher_XHS_HTTP::cookies($cookie); }
        catch (Throwable $e) { throw new RuntimeException('已保存的小红书会话格式无效。请重新填写并验证本人创作中心 Cookie；历史任务与笔记 ID 会保留。'); }
    }
    public static function length($text) { preg_match_all('/./us', $text, $m); return count($m[0]); }
    public static function validate($p, $reading = false) {
        $c = self::config();
        if (!$c['session'] || !$c['uid'] || !$c['verified'] || (!$reading && !$c['enabled']) || (int) $c['protocol'] !== self::PROTOCOL) { return new WP_Error('xhs_auth', '请在 Publisher 中连接小红书、核对本人账号并启用自动同步。'); }
        if (($p['account'] ?? '') !== $c['uid'] || (!$reading && !hash_equals(self::binding($c), (string) ($p['xhs_binding'] ?? '')))) { return new WP_Error('xhs_binding', '小红书账号连接已改变，旧任务不会改绑；确定未发表的任务可采用当前设置。'); }
        try { self::cookie($c); } catch (Throwable $e) { return new WP_Error('xhs_auth', $e->getMessage()); }
        $title = $p['xhs_title'] ?? $p['title'] ?? '';
        if (!$title || self::length($title) > 20) { return new WP_Error('xhs_title', '小红书分享标题请控制在本插件的 20 字上限内，可单独修改小红书标题；原文章标题不变。'); }
        if (!empty($p['xhs_description_error'])) { return new WP_Error('xhs_text', '小红书文案无法生成或与链接合计超过 1000 字，请缩短小红书文案；其他平台任务独立处理。'); }
        if (isset($p['xhs_description']) && (!is_string($p['xhs_description']) || substr($p['xhs_description'], -strlen($p['url'] ?? '')) !== ($p['url'] ?? ''))) { return new WP_Error('xhs_text', '小红书冻结文案缺少末尾的本文网址，未发表。'); }
        if (empty($p['url']) || !preg_match('~^https://~i', $p['url']) || self::length(self::description($p)) > 1000) { return new WP_Error('xhs_text', '小红书需要本篇公开文章的 HTTPS 网址；分享文字请控制在本插件的 1000 字上限内。'); }
        if (($p['xhs_format'] ?? 'images') === 'video') {
            if (empty($p['xhs_video']) || empty($p['xhs_cover']) || !empty($p['images'])) { return new WP_Error('xhs_video', '小红书视频需单独选择 MP4 和 JPEG／PNG 封面，不能混发图集。'); }
        } elseif (empty($p['images']) || count($p['images']) > 9) { return new WP_Error('xhs_images', '小红书图文请选择 1–9 张本地 JPEG／PNG 原图。'); }
        return true;
    }
    public static function description($p) { return isset($p['xhs_description']) && is_string($p['xhs_description']) ? $p['xhs_description'] : $p['title'] . "\n\n" . $p['url']; }
    public static function plain_text($text) {
        if (!is_string($text)) { throw new RuntimeException('小红书文字格式无效。'); }
        $text = preg_replace('~<(script|style|figure|picture|video|audio|iframe)\b[^>]*>.*?</\1\s*>~is', '', $text);
        $text = preg_replace('~<!--.*?-->~s', '', $text);
        $text = preg_replace('~<br\s*/?>~i', "\n", $text);
        $text = preg_replace('~</(?:p|div|li|h[1-6]|blockquote)>~i', "\n\n", $text);
        $text = html_entity_decode(wp_strip_all_tags(strip_shortcodes($text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(array("\r\n", "\r", "\xc2\xa0"), array("\n", "\n", ' '), $text);
        $text = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', $text);
        $text = preg_replace('/[ \t]+/u', ' ', $text);
        $text = preg_replace('/ *\n */u', "\n", $text);
        $text = preg_replace('/\n{3,}/u', "\n\n", $text);
        if (!is_string($text)) { throw new RuntimeException('小红书文字包含无效字符。'); }
        return trim($text);
    }
    public static function compose($article, $selection, $title, $url) {
        $manual = self::plain_text($selection['xhs_text'] ?? '');
        $body = $manual !== '' ? $manual : self::plain_text(($article->post_excerpt ?? '') !== '' ? $article->post_excerpt : ($article->post_content ?? ''));
        $titles = array($title, $selection['xhs_title'] ?? '', self::plain_text($article->post_title ?? ''));
        $lines = explode("\n", $body);
        while ($lines && (trim($lines[0]) === '' || in_array(trim($lines[0]), $titles, true))) { array_shift($lines); }
        $lines = array_values(array_filter($lines, function ($line) use ($url) { return trim($line) !== $url; }));
        $body = preg_replace('/\n{3,}/u', "\n\n", trim(implode("\n", $lines)));
        if ($manual === '' && $body !== '') {
            $parts = preg_split('/\n\n+/u', $body);
            $body = implode("\n\n", array_slice($parts, 0, 3));
            $limit = max(0, min(300, 997 - self::length($url)));
            preg_match('/\A.{0,' . $limit . '}/us', $body, $m);
            $short = rtrim($m[0] ?? '');
            $body = $short !== $body ? $short . '…' : $short;
        }
        $description = ($body !== '' ? $body . "\n\n" : '') . $url;
        if (self::length($description) > 1000) { throw new RuntimeException('小红书文案与文章链接合计超过 1000 字，请缩短小红书文案。'); }
        return array('title' => ($selection['xhs_title'] ?? '') ?: $title, 'body' => $body, 'description' => $description);
    }
    private static function creator_proof($note, $id, $p) {
        $nid = $note['note_id'] ?? $note['noteId'] ?? $note['id'] ?? '';
        $uid = $note['user']['userId'] ?? $note['user']['user_id'] ?? $note['user_id'] ?? '';
        $title = $note['display_title'] ?? $note['title'] ?? $note['note_title'] ?? '';
        if ($nid !== $id || ($uid !== '' && $uid !== $p['account']) || !is_string($title) || $title !== $p['xhs_title']) { throw new RuntimeException('创作中心回执的笔记、标题或本人账号不一致，保留同一 ID 待核对。'); }
        // Own-account list confirms that same note exists, not unseen body/media parity or public review status.
        return array('scope' => 'creator_list', 'note_id' => $id, 'account' => $p['account'], 'title' => $title);
    }
    public static function video_snapshot($id, $owner) {
        if (!$id || !user_can($owner, 'edit_post', $id) || get_post_type($id) !== 'attachment') { throw new RuntimeException('请选择有使用权限的本地 MP4 视频。'); }
        $file = Crolord_Publisher_Media::local($id); $head = file_get_contents($file, false, null, 0, 12);
        if ((wp_check_filetype_and_ext($file, basename($file))['type'] ?? '') !== 'video/mp4' || substr($head, 4, 4) !== 'ftyp') { throw new RuntimeException('小红书视频需要媒体库中的完整 MP4 文件。'); }
        if (!filesize($file) || filesize($file) > (int) self::config()['video_mb'] * 1048576) { throw new RuntimeException('小红书视频为空或超过后台设置的大小上限。'); }
        return array('id' => (int) $id, 'sha256' => hash_file('sha256', $file), 'size' => filesize($file));
    }
    private static function file($media, $owner, $video = false) {
        if (!user_can($owner, 'edit_post', $media['id'])) { throw new RuntimeException('发布者的媒体权限已撤销，已停止上传。'); }
        $file = Crolord_Publisher_Media::local($media['id']); $c = self::config();
        if (!filesize($file) || filesize($file) > (int) $c[$video ? 'video_mb' : 'image_mb'] * 1048576 || !hash_equals($media['sha256'], hash_file('sha256', $file))) { throw new RuntimeException('小红书媒体超过大小上限或在排队后发生变化，已停止。'); }
        if (!$video && !in_array(wp_get_image_mime($file), array('image/jpeg', 'image/png'), true)) { throw new RuntimeException('小红书图片需要 JPEG／PNG 原图。'); }
        if ($video && substr(file_get_contents($file, false, null, 0, 12), 4, 4) !== 'ftyp') { throw new RuntimeException('视频格式已改变，未上传。'); }
        return $file;
    }
    private static function image($media, $owner, $cookie) {
        $file = self::file($media, $owner); $permit = Crolord_Publisher_XHS_HTTP::permit('image', $cookie);
        Crolord_Publisher_XHS_HTTP::request('https://ros-upload.xiaohongshu.com/' . $permit['file_id'], 'PUT', file_get_contents($file), array('X-Cos-Security-Token' => $permit['token'], 'Content-Type' => wp_get_image_mime($file)));
        return array('file_id' => $permit['file_id'], 'mime' => wp_get_image_mime($file), 'attachment' => $media['id'], 'sha256' => $media['sha256']);
    }
    private static function identity($cookie, $p) {
        $id = Crolord_Publisher_XHS_HTTP::identity($cookie);
        if ($id['uid'] !== $p['account']) { throw new RuntimeException('小红书本人 UID 与任务绑定不一致，已停止。'); }
        return $id;
    }
    private static function checkpoint($job, $progress, $delay = 0, $extra = array()) {
        Crolord_Publisher_Store::write($job, array_merge(array('state' => $delay ? 'external_pending' : 'queued', 'lease' => '', 'progress' => wp_json_encode($progress), 'available' => time() + $delay, 'error_text' => ''), $extra));
    }
    /** One five-MB part per tick. Only scoped object-store tokens go to upload host. */
    private static function video_step($file, &$w, $cookie) {
        if (empty($w['video'])) {
            $permit = Crolord_Publisher_XHS_HTTP::permit('video', $cookie);
            $r = Crolord_Publisher_XHS_HTTP::request('https://ros-upload.xiaohongshu.com/' . $permit['file_id'] . '?uploads', 'POST', '', array('X-Cos-Security-Token' => $permit['token']));
            if (!preg_match('~<UploadId>([^<]{1,2048})</UploadId>~', $r['body'], $m)) { throw new RuntimeException('小红书未返回分片上传 ID，未发表视频。'); }
            $upload = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match('/[\x00-\x20\x7f]/', $upload)) { throw new RuntimeException('视频上传 ID 无效。'); }
            $w['video'] = array('file_id' => $permit['file_id'], 'token' => Crolord_Publisher_Store::encrypt($permit['token']), 'upload_id' => $upload, 'parts' => array(), 'stage' => 'parts', 'bytes' => 0); $w['phase'] = 'video_parts'; return;
        }
        $v =& $w['video']; $token = Crolord_Publisher_Store::decrypt($v['token']);
        if (!$token) { throw new RuntimeException('视频上传凭据无法解密，尚未发表。'); }
        $base = 'https://ros-upload.xiaohongshu.com/' . $v['file_id'];
        if ($v['stage'] === 'parts') {
            $offset = count($v['parts']) * self::CHUNK;
            if ($offset < filesize($file)) {
                $bytes = file_get_contents($file, false, null, $offset, self::CHUNK); if ($bytes === false || strlen($bytes) !== min(self::CHUNK, filesize($file) - $offset)) { throw new RuntimeException('读取视频分片失败，未发表。'); }
                $number = count($v['parts']) + 1;
                $url = $base . '?' . http_build_query(array('partNumber' => $number, 'uploadId' => $v['upload_id']), '', '&', PHP_QUERY_RFC3986);
                $r = Crolord_Publisher_XHS_HTTP::request($url, 'PUT', $bytes, array('X-Cos-Security-Token' => $token, 'Content-Type' => 'video/mp4'));
                $etag = (string) ($r['headers']['etag'] ?? '');
                if (!preg_match('/^"?[A-Za-z0-9_-]{8,160}"?$/D', $etag)) { throw new RuntimeException('视频分片未返回有效 ETag，未进入发表。'); }
                $v['parts'][] = array('number' => $number, 'etag' => $etag); $v['bytes'] = $offset + strlen($bytes); return;
            }
            $v['stage'] = 'complete'; return;
        }
        if ($v['stage'] === 'complete') {
            $xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';
            foreach ($v['parts'] as $part) { $xml .= '<Part><PartNumber>' . (int) $part['number'] . '</PartNumber><ETag>' . htmlspecialchars($part['etag'], ENT_QUOTES | ENT_XML1, 'UTF-8') . '</ETag></Part>'; }
            $xml .= '</CompleteMultipartUpload>';
            $r = Crolord_Publisher_XHS_HTTP::request($base . '?uploadId=' . rawurlencode($v['upload_id']), 'POST', $xml, array('X-Cos-Security-Token' => $token, 'Content-Type' => 'application/xml'));
            if (strpos($r['body'], '<Error>') !== false || strpos($r['body'], '<CompleteMultipartUploadResult') === false) { throw new RuntimeException('视频分片合并未核验，未发表。'); }
            $v['video_id'] = (string) ($r['headers']['x-ros-video-id'] ?? ''); $v['stage'] = 'ready'; unset($v['token']); $w['phase'] = 'video_ready';
        }
    }
    public static function post_data($p, $w) {
        // Keep the wire schema from the version that returned a real note ID.
        // Local binding checks do not add speculative fields to the signed body.
        if (($p['xhs_format'] ?? 'images') !== 'video') {
            if (count($w['pictures'] ?? array()) !== count($p['images'] ?? array())) { throw new RuntimeException('小红书上传图片数量与本篇计划不一致，未发表。'); }
            foreach ($w['pictures'] as $index => $pic) {
                $media = $p['images'][$index];
                if ((int) ($pic['attachment'] ?? 0) !== (int) $media['id'] || !hash_equals((string) $media['sha256'], (string) ($pic['sha256'] ?? '')) || !Crolord_Publisher_XHS_HTTP::file_id($pic['file_id'] ?? '') || !user_can($p['requested_by'], 'edit_post', $media['id'])) { throw new RuntimeException('小红书已上传图片与本篇原图或顺序不一致，未发表。'); }
            }
        }
        $business = array('version' => 1, 'noteId' => 0, 'noteOrderBind' => new stdClass(), 'notePostTiming' => array('postTime' => null), 'noteCollectionBind' => array('id' => ''));
        $common = array('type' => ($p['xhs_format'] ?? 'images') === 'video' ? 'video' : 'normal', 'title' => $p['xhs_title'], 'note_id' => '', 'desc' => self::description($p), 'source' => '{"type":"web","ids":"","extraInfo":"{\\"subType\\":\\"official\\"}"}', 'business_binds' => Crolord_Publisher_XHS_HTTP::json($business), 'ats' => array(), 'hash_tag' => array(), 'post_loc' => new stdClass(), 'privacy_info' => array('op_type' => 1, 'type' => 0));
        $images = array(); foreach ($w['pictures'] ?? array() as $pic) { $images[] = array('file_id' => $pic['file_id'], 'metadata' => array('source' => -1), 'stickers' => array('version' => 2, 'floating' => array()), 'extra_info_json' => Crolord_Publisher_XHS_HTTP::json(array('mimeType' => $pic['mime']))); }
        $video = $common['type'] === 'video' ? array('file_id' => $w['video']['file_id'], 'timelines' => array(), 'cover' => array('file_id' => $w['cover']['file_id'], 'frame' => array('ts' => 0, 'is_user_select' => false, 'is_upload' => true)), 'chapters' => array(), 'chapter_sync_text' => false, 'entrance' => 'web') : null;
        return array('common' => $common, 'image_info' => $video === null ? array('images' => $images) : null, 'video_info' => $video);
    }
    private static function canonical($id) {
        if (!is_string($id)) { return ''; }
        $id = preg_replace('~^spectrum/~', '', $id); return preg_replace('~\.(?:jpg|jpeg|png)$~i', '', $id);
    }
    public static function verify($note, $id, $p, $w) {
        $nid = $note['noteId'] ?? $note['note_id'] ?? ''; $uid = $note['user']['userId'] ?? $note['user']['user_id'] ?? '';
        if ($nid !== $id || $uid !== $p['account']) { throw new RuntimeException('小红书回执的笔记或本人 UID 不一致，保留待核对。'); }
        if (($note['title'] ?? '') !== $p['xhs_title'] || ($note['desc'] ?? '') !== self::description($p)) { throw new RuntimeException('小红书回执未核对到完整标题与本文网址，保留同一 ID 待核对。'); }
        if (($p['xhs_format'] ?? 'images') === 'video') {
            $video = $note['video'] ?? array(); $receipt = $video['media']['videoId'] ?? $video['media']['video_id'] ?? $video['file_id'] ?? $video['fileId'] ?? '';
            $expected = array_filter(array($w['video']['video_id'] ?? '', $w['video']['file_id'] ?? ''));
            if (($note['type'] ?? '') !== 'video' || !$receipt || !in_array($receipt, $expected, true)) { throw new RuntimeException('笔记已取得 ID，但视频真实标识尚未核对；保留待核对。'); }
            return array('kind' => 'video', 'count' => 1);
        }
        $images = $note['imageList'] ?? $note['image_list'] ?? null;
        if (!is_array($images) || count($images) !== count($p['images'])) { throw new RuntimeException('小红书最终图片数量与计划不一致，未登记成功。'); }
        $actual = array();
        foreach ($images as $image) { $actual[] = self::canonical($image['fileId'] ?? $image['file_id'] ?? ''); }
        $expected = array_map(function ($pic) { return self::canonical($pic['file_id']); }, $w['pictures'] ?? array());
        if (in_array('', $actual, true) || $actual !== $expected) { throw new RuntimeException('小红书最终图片真实 ID 或顺序与上传记录不一致，未登记成功。'); }
        return array('kind' => 'images', 'count' => count($actual), 'ids' => $actual);
    }
}
