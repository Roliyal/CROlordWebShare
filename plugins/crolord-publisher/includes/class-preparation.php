<?php
if (!defined('ABSPATH')) { exit; }

/** Durable, non-deliverable reservations; a row becomes sendable only after a complete snapshot. */
final class Crolord_Publisher_Preparation {
    public static function is_reservation($job) {
        $p = json_decode($job['payload'] ?? '', true);
        return is_array($p) && ($p['preparation'] ?? 0) === 1 && in_array($job['state'] ?? '', array('preparing', 'prepare_failed', 'cancelled'), true) && trim((string) ($job['remote_ids'] ?? '')) === '[]' && trim((string) ($job['progress'] ?? '')) === '{}' && ($job['lease'] ?? '') === '';
    }
    public static function mutable($job) { return self::is_reservation($job) && $job['state'] !== 'cancelled'; }
    private static function find($post_id, $platform) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Crolord_Publisher_Store::table() . ' WHERE post_id=%d AND platform=%s LIMIT 1', $post_id, $platform), ARRAY_A);
    }
    public static function reserve($post_id, $platform, $selection) {
        if (!in_array($platform, array('weibo', 'x', 'xiaohongshu'), true)) { throw new RuntimeException('同步平台无效，未建立任务。'); }
        global $wpdb;
        $p = array('preparation' => 1, 'format' => 9, 'requested_by' => (int) $selection['requested_by'], 'title' => $selection['title'] ?: wp_strip_all_tags(get_the_title($post_id)), 'url' => '', 'images' => array(), 'not_before' => (int) ($selection['not_before'] ?? 0));
        $sql = 'INSERT IGNORE INTO ' . Crolord_Publisher_Store::table() . " (post_id,platform,runner,state,payload,progress,remote_ids,error_text,touched,available) VALUES (%d,%s,'php','preparing',%s,'{}','[]','正在准备文章链接与媒体。',%d,%d)";
        $created = $wpdb->query($wpdb->prepare($sql, $post_id, $platform, wp_json_encode($p), time(), time()));
        if ($created === false) { throw new RuntimeException('无法建立发布准备任务，请检查发布数据表与数据库写入权限。'); }
        $job = self::find($post_id, $platform);
        if (!$job) { throw new RuntimeException('发布准备任务未能回读，未向平台提交。'); }
        return array('job' => $job, 'created' => $created === 1);
    }
    private static function write($job, $values) {
        if (!self::mutable($job)) { return false; }
        global $wpdb; $values['touched'] = time();
        $changed = $wpdb->update(Crolord_Publisher_Store::table(), $values, array('id' => $job['id'], 'state' => $job['state'], 'payload' => $job['payload'], 'remote_ids' => $job['remote_ids'], 'progress' => $job['progress'], 'lease' => ''));
        if ($changed === false) { throw new RuntimeException('发布准备检查点保存失败；没有继续提交平台。'); }
        return (bool) $changed;
    }
    public static function note($job, $message, $waiting = false) {
        return self::write($job, array('state' => $waiting ? 'preparing' : 'prepare_failed', 'error_text' => $message));
    }
    public static function commit($job, $p) {
        if (!self::mutable($job)) { return 0; }
        $valid = Crolord_Publisher_Platforms::validate($job['platform'], $p);
        $at = max(time(), (int) ($p['not_before'] ?? 0));
        $state = is_wp_error($valid) ? 'failed' : ($at > time() ? 'scheduled' : Crolord_Publisher::waiting_state($job['platform'], $p));
        $values = array('state' => $state, 'runner' => 'php', 'payload' => wp_json_encode($p), 'available' => is_wp_error($valid) ? time() : $at, 'error_text' => is_wp_error($valid) ? $valid->get_error_message() : '');
        return self::write($job, $values) ? (int) $job['id'] : 0;
    }
    public static function notes($post_id, $message, $waiting = false) {
        foreach (Crolord_Publisher_Store::jobs($post_id) as $job) { if (self::mutable($job)) { self::note($job, $message, $waiting); } }
    }
    public static function action($job, $operation) {
        if (!self::is_reservation($job)) { return null; }
        if (!self::mutable($job)) { throw new RuntimeException('准备任务已取消，未重新同步。'); }
        if ($operation === 'cancel') {
            if (!self::write($job, array('state' => 'cancelled', 'error_text' => '已取消此平台同步准备。'))) { throw new RuntimeException('任务状态已变化，请刷新核对。'); }
            return true;
        }
        if ($operation !== 'prepare_retry') { throw new RuntimeException('任务尚未完成链接与媒体准备，请先重新准备；不会直接发给平台。'); }
        if (!Crolord_Publisher_Automatic::retry_preparation($job['post_id'])) { throw new RuntimeException('请先保存文章同步设置并检查发布权限。'); }
        Crolord_Publisher_Automatic::complete($job['post_id']);
        return true;
    }
}
