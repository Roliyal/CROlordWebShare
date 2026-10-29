<?php
if (!defined('ABSPATH')) { exit; }
final class Crolord_Publisher {
    public static function install() {
        Crolord_Publisher_Store::install();
        Crolord_Publisher_Weibo_Native::retire_browser_jobs();
        wp_clear_scheduled_hook('crolord_publisher_duoplus_tick');
        wp_clear_scheduled_hook('crolord_publisher_duoplus_watchdog');
        $role = get_role('administrator'); if ($role) { $role->add_cap('crolord_publish_social'); }
        Crolord_Publisher_Wechat_History::upgrade();
        Crolord_Publisher_Buffer::recheck_media_receipts();
        Crolord_Publisher_XHS::upgrade_receipts();
        self::schedule();
    }
    public static function boot() {
        add_filter('cron_schedules', function ($items) { $items['crolord_publisher_minute'] = array('interval' => 60, 'display' => 'Publisher 每分钟'); return $items; });
        add_action('crolord_publisher_tick', array(__CLASS__, 'tick'));
        add_action('crolord_publisher_due', array(__CLASS__, 'tick'), 10, 0);
        add_action('wp_ajax_nopriv_crolord_publisher_worker', array(__CLASS__, 'worker'));
        add_action('wp_ajax_crolord_publisher_worker', array(__CLASS__, 'worker'));
        add_action('save_post_post', array(__CLASS__, 'save'), 30, 3);
        if (get_option('crolord_publisher_db') !== (defined('CROLORD_PUBLISHER_DB_VERSION') ? CROLORD_PUBLISHER_DB_VERSION : CROLORD_PUBLISHER_VERSION)) { self::install(); }
        self::schedule();
        Crolord_Publisher_Clock::boot();
        if (is_admin()) {
            Crolord_Publisher_Admin::boot();
            Crolord_Publisher_Diagnostics::boot();
            Crolord_Publisher_Weibo_Admin::boot();
            Crolord_Publisher_XHS_Admin::boot();
        }
        Crolord_Publisher_Buffer::boot();
        Crolord_Publisher_XHS_Session::boot();
        // Browser/extension endpoints are retired; their queue rows and credentials remain.
        Crolord_Publisher_Wechat_History::boot();
        Crolord_Publisher_Automatic::boot();
        Crolord_Publisher_Poetic_Slug::boot();
        Crolord_Publisher_Share_Metadata::boot();
    }
    public static function schedule() {
        if (!wp_next_scheduled('crolord_publisher_tick')) { wp_schedule_event(time() + 10, 'crolord_publisher_minute', 'crolord_publisher_tick'); }
    }
    public static function snapshot($post_id, $selection) {
        $card=($selection['share_layout']??'')==='card';$article=get_post($post_id);
        if(!Crolord_Publisher_Platforms::needs_images($selection)){$selection['images']=array();$selection['platform_images']=array('weibo'=>array(),'x'=>array());$selection['allow_empty_media']=true;if(!in_array('xiaohongshu',$selection['platforms']??array(),true)){$selection['weibo_format']='images';}}
        if(($selection['share_layout']??'')==='platform'){$selection['weibo_format']='images';}
        $only_video=count($selection['platforms']??array())===1&&in_array('weibo',$selection['platforms'],true)&&($selection['weibo_format']??'')==='video';
        if(!$only_video&&Crolord_Publisher_Platforms::needs_images($selection)){$selection=Crolord_Publisher_Media::hydrate($article,$selection);}
        $images = array();
        foreach ($only_video?array():$selection['images'] as $index=>$id) {
            $images[]=Crolord_Publisher_Media::freeze($id,(int)$selection['requested_by'],$index+1);
        }
        $snapshot=array('title' => $selection['title'] ?: wp_strip_all_tags(html_entity_decode(get_the_title($post_id), ENT_QUOTES, 'UTF-8')), 'location' => '', 'place_id' => '', 'url' => get_permalink($post_id), 'images' => $images, 'requested_by' => $selection['requested_by'], 'created' => time(), 'format' => 3, 'x_delivery' => ($selection['x_delivery']??'buffer')==='native'?'native':'buffer', 'image_selections' => $selection['platform_images'] ?? null, 'weibo_delivery' => ($selection['weibo_delivery']??'php'), 'weibo_format' => ($selection['weibo_format']??'images'), 'weibo_statement'=>Crolord_Publisher_Weibo::statement($selection['weibo_statement']??0), 'weibo_video_title' => ($selection['weibo_video_title']??''), 'weibo_video' => in_array('weibo',$selection['platforms']??array(),true)&&($selection['weibo_format']??'')==='video'&&!empty($selection['weibo_video_id']) ? Crolord_Publisher_Weibo::video_snapshot((int)$selection['weibo_video_id'],(int)$selection['requested_by'],($selection['weibo_delivery']??'')==='php'?Crolord_Publisher_Weibo_Session::config():null) : null, 'allow_empty_media'=>!empty($selection['allow_empty_media']), 'media_warnings'=>(int)($selection['media_warnings']??0), 'media_mode'=>$selection['media_mode']??'manual', 'media_audit'=>$selection['media_audit']??array(), 'share_layout'=>$selection['share_layout']??'legacy', 'card'=>$card?Crolord_Publisher_Share_Metadata::build($article):null, 'link_layout'=>isset($selection['share_layout'])?'inline':'reply_last', 'not_before' => (int) ($selection['not_before'] ?? 0));
        if(($selection['share_layout']??'')==='album'&&isset($selection['x_presentation'])){
            $snapshot['x_presentation']=$selection['x_presentation'];
            if($selection['x_presentation']==='card'&&in_array('x',$selection['platforms']??array(),true)){$snapshot['x_card']=Crolord_Publisher_Share_Metadata::build($article);}
        }
        if(in_array('xiaohongshu',$selection['platforms']??array(),true)){
            $snapshot['xhs_title']=($selection['xhs_title']??'')?:$snapshot['title'];$snapshot['xhs_format']=($selection['xhs_format']??'images')==='video'?'video':'images';
            try{$snapshot['xhs_description']=Crolord_Publisher_XHS::compose($article,$selection,$snapshot['title'],$snapshot['url'])['description'];}
            catch(Throwable $e){$snapshot['xhs_description']='';$snapshot['xhs_description_error']=$e->getMessage();}
            if($snapshot['xhs_format']==='video'){
                $snapshot['xhs_video']=Crolord_Publisher_XHS::video_snapshot((int)($selection['xhs_video_id']??0),(int)$selection['requested_by']);
                $snapshot['xhs_cover']=Crolord_Publisher_Media::freeze((int)($selection['xhs_cover_id']??0),(int)$selection['requested_by'],1);
            }
        }
        return $snapshot;
    }
    public static function for_platform($payload, $platform) {
        if(($payload['share_layout']??'')==='platform'){
            $payload['requested_layout']='platform';$payload['share_layout']=Crolord_Publisher_Platforms::layout($payload,$platform);
        }
        if($platform==='x'&&($payload['x_presentation']??'')==='card'){
            $payload['share_layout']='card';$payload['x_card_mode']='main';$payload['card']=$payload['x_card']??null;$payload['images']=array();$payload['image_selections']=array('x'=>array());$payload['allow_empty_media']=true;$payload['link_layout']='inline';
        }
        unset($payload['x_presentation'],$payload['x_card']);
        if($platform!=='xiaohongshu'){unset($payload['xhs_title'],$payload['xhs_format'],$payload['xhs_video'],$payload['xhs_cover'],$payload['xhs_description'],$payload['xhs_description_error']);}
        $images=Crolord_Publisher_Platforms::selected_images($payload['images'],$payload['image_selections'][$platform]??null,$platform==='x'?4:9);
        $payload['images'] = $platform==='weibo'&&($payload['weibo_format']??'images')==='video' ? array() : $images;
        $payload['expected_images']=count($payload['images']);
        if ($platform!=='weibo') { unset($payload['weibo_video']);$payload['weibo_format']='images'; }
        if($platform==='xiaohongshu'){
            $payload['share_layout']='album';$payload['link_layout']='inline';$payload['card']=null;
            if(($payload['xhs_format']??'images')==='video'){$payload['images']=array();$payload['expected_images']=0;}
        }
        return $payload;
    }
    public static function due($id, $timestamp) {
        Crolord_Publisher_Clock::changed();
        wp_clear_scheduled_hook('crolord_publisher_due', array((int) $id));
        $job = Crolord_Publisher_Store::get($id);
        if ($job && $job['platform'] === 'wechat') { return; }
        if ($timestamp > time()) { wp_schedule_single_event((int) $timestamp, 'crolord_publisher_due', array((int) $id)); }
    }
    public static function bind($payload, $platform) {
        $config = Crolord_Publisher_Platforms::config($platform);
        $payload['account'] = $config['uid'] ?? '';
        if($platform==='xiaohongshu'){$c=Crolord_Publisher_XHS::config();$payload['account']=$c['uid'];$payload['xhs_binding']=Crolord_Publisher_XHS::binding($c);$payload['format']=8;$payload['location']='';$payload['place_id']='';}
        if ($platform === 'x') {
            $payload['x_route'] = ($payload['x_delivery'] ?? '') === 'native' || !empty($payload['place_id']) ? 'native' : 'buffer';
            if ($payload['x_route'] === 'buffer') { $buffer=Crolord_Publisher_Buffer::config();$payload['buffer_channel']=$buffer['channel_id']??''; }
        }
        if ($platform==='weibo') {
            $route=$payload['weibo_delivery']??'native';$payload['weibo_route']=in_array($route,array('web','official','browser','php'),true)?$route:'native';
            if($route==='php'){$c=Crolord_Publisher_Weibo_Session::config();$payload['account']=$c['uid'];$payload['php_binding']=Crolord_Publisher_Weibo_Native::binding($c);$payload['format']=7;$payload['location']='';}
            if($route==='official'){$c=Crolord_Publisher_Weibo_API::config();$payload['account']=$c['uid'];$payload['format']=5;$payload['location']='';}
            if($route==='browser'){$c=Crolord_Publisher_Weibo_Browser::config();$payload['account']=$c['uid'];$payload['browser_binding']=Crolord_Publisher_Weibo_Browser::binding($c);$payload['format']=6;$payload['location']='';}
        }
        return $payload;
    }
    public static function waiting_state($platform,$payload) { return Crolord_Publisher_Platforms::manual($platform,$payload)?'manual_ready':'queued'; }
    public static function reserve_selection($post_id, $selection) {
        $post = get_post($post_id); $by = (int) ($selection['requested_by'] ?? 0);
        if (!$post || $post->post_status !== 'publish' || $post->post_password || !$by || !user_can($by, 'crolord_publish_social') || !user_can($by, 'edit_post', $post_id)) { throw new RuntimeException('文章尚未公开或发布者权限不足，未建立同步任务。'); }
        $result = array();
        foreach ($selection['platforms'] as $platform) {
            if (in_array($platform, array('weibo', 'x', 'xiaohongshu'), true)) { $result[$platform] = Crolord_Publisher_Preparation::reserve($post_id, $platform, $selection); }
        }
        return $result;
    }
    public static function enqueue_selection($post_id, $selection) {
        $reserved = self::reserve_selection($post_id, $selection); $ids = array(); $active = array();
        foreach ($reserved as $platform => $item) {
            if ($item['created']) { $ids[] = (int) $item['job']['id']; }
            if (Crolord_Publisher_Preparation::mutable($item['job'])) { $active[$platform] = $item['job']; }
        }
        if (!$active) { return $ids; }
        if (!Crolord_Publisher_Poetic_Slug::ready($post_id)) {
            $slug = get_post_meta($post_id, Crolord_Publisher_Poetic_Slug::META, true);
            $waiting = in_array($slug['status'] ?? '', array('pending', 'working'), true);
            $message = $waiting ? '正在等待英文链接；任务已建立，尚未上传或发表。' : '英文链接尚未就绪：' . ($slug['message'] ?? '请在文章编辑页检查链接状态。');
            foreach ($active as $job) { Crolord_Publisher_Preparation::note($job, $message, $waiting); }
            update_post_meta($post_id, '_crolord_publisher_error', $message);
            Crolord_Publisher_Automatic::wait($post_id, (int) $selection['requested_by']);
            return array_values(array_unique($ids));
        }
        $errors = array();
        foreach ($active as $platform => $job) {
            try {
                $one = $selection; $one['platforms'] = array($platform);
                $payload = self::bind(self::for_platform(self::snapshot($post_id, $one), $platform), $platform);
                $id = Crolord_Publisher_Preparation::commit($job, $payload);
                if ($id) { $ids[] = $id; self::due($id, (int) $payload['not_before']); }
            } catch (Throwable $e) {
                $message = Crolord_Publisher_Platforms::names()[$platform] . '：' . $e->getMessage();
                $errors[] = $message;
                try { Crolord_Publisher_Preparation::note($job, $message); }
                catch (Throwable $checkpoint_error) { $errors[] = $checkpoint_error->getMessage(); }
            }
        }
        if ($errors) { update_post_meta($post_id, '_crolord_publisher_error', implode(' ', $errors)); }
        else { delete_post_meta($post_id, '_crolord_publisher_error'); }
        return array_values(array_unique($ids));
    }
    public static function save($post_id,$post,$update,$force=false) {
        if(wp_is_post_revision($post_id)||wp_is_post_autosave($post_id)||(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)){return;}
        if(isset($_POST['crolord_publisher_nonce'])&&wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['crolord_publisher_nonce'])),'crolord_publisher_edit_'.$post_id)&&current_user_can('crolord_publish_social')&&current_user_can('edit_post',$post_id)){
            try{$selection=Crolord_Publisher_Admin::selection(wp_unslash($_POST),get_post_meta($post_id,'_crolord_publisher_selection',true));}
            catch(Throwable $e){Crolord_Publisher_Automatic::halt($post_id,$e->getMessage());return;}
            $selection['requested_by']=get_current_user_id();update_post_meta($post_id,'_crolord_publisher_selection',$selection);Crolord_Publisher_Automatic::retry_preparation($post_id);
        }
        if($post->post_status!=='publish'||$post->post_password){return;}
        if(!$force){Crolord_Publisher_Automatic::defer($post_id);return;}
        $selection=Crolord_Publisher_Automatic::defaults(get_post_meta($post_id,'_crolord_publisher_selection',true));
        if(empty($selection['auto_sync'])||empty($selection['platforms'])){return;}
        $owner=(int)($selection['requested_by']??0);
        if(!$owner||!user_can($owner,'crolord_publish_social')||!user_can($owner,'edit_post',$post_id)){return;}
        try{self::enqueue_selection($post_id,$selection);add_action('shutdown',array(__CLASS__,'kick'));}
        catch(Throwable $e){update_post_meta($post_id,'_crolord_publisher_error',$e->getMessage());}
    }
    public static function kick() {
        global $wpdb;
        $pending = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Crolord_Publisher_Store::table() . " WHERE runner='php' AND platform<>'wechat' AND state IN ('queued','external_pending') AND available<=%d LIMIT 1", time()));
        if (!$pending) { return; }
        $stamp = (string) time();
        wp_remote_post(admin_url('admin-ajax.php'), array('timeout'=>0.01,'blocking'=>false,'redirection'=>0,'body'=>array('action'=>'crolord_publisher_worker','stamp'=>$stamp,'signature'=>hash_hmac('sha256','publisher-worker|' . $stamp,wp_salt('nonce')))));
    }
    public static function worker() {
        $stamp = isset($_POST['stamp']) ? (string) $_POST['stamp'] : '';
        $signature = isset($_POST['signature']) ? (string) $_POST['signature'] : '';
        if (!ctype_digit($stamp) || abs(time() - (int)$stamp)>90 || !hash_equals(hash_hmac('sha256','publisher-worker|' . $stamp,wp_salt('nonce')),$signature)) { wp_die('', '', array('response'=>403)); }
        if (!empty($_POST['probe'])) { wp_send_json_success(array('publisher_probe'=>1,'version'=>CROLORD_PUBLISHER_VERSION)); }
        if(!empty($_POST['slug'])){Crolord_Publisher_Poetic_Slug::work(absint($_POST['slug']));wp_die();}
        ignore_user_abort(true); if(function_exists('set_time_limit')){@set_time_limit(120);}
        self::tick();
        wp_die();
    }
    public static function tick($target = 0) {
        Crolord_Publisher_Clock::changed();
        $job = Crolord_Publisher_Store::claim($target); if (!$job) { return; }
        update_option('crolord_publisher_last_run', time(), false);
        add_action('shutdown', array(__CLASS__, 'kick'));
        try { self::step($job); }
        catch (Throwable $e) {
            $fresh = Crolord_Publisher_Store::get($job['id']);
            if ($fresh && $fresh['lease'] === $job['lease']) {
                // Catch only locally generated messages; never expose provider response bodies.
                Crolord_Publisher_Store::write($job, array('state' => $fresh['state'] === 'publishing' ? 'unknown' : 'failed', 'error_text' => $e->getMessage()));
            }
        }
    }
    private static function fail($job, $error) {
        $data = $error->get_error_data();
        $delay = isset($data['delay']) ? (int) $data['delay'] : 0;
        $progress = json_decode($job['progress'], true) ?: array();
        if ($delay) { $progress['retry_after'] = time() + $delay; }
        if (is_array($data)) { $progress['last_error'] = array_intersect_key($data,array_flip(array('stage','http','type','request_id'))); }
        Crolord_Publisher_Store::write($job, array('state' => $error->get_error_code() === 'unknown' ? 'unknown' : 'failed', 'error_text' => $error->get_error_message(), 'available' => time() + $delay, 'progress'=>wp_json_encode($progress)));
    }
    private static function step($job) {
        $payload = json_decode($job['payload'], true); $progress = json_decode($job['progress'], true) ?: array(); $ids = json_decode($job['remote_ids'], true) ?: array();
        if (!empty($payload['preparation'])) { Crolord_Publisher_Store::write($job, array('state' => 'prepare_failed', 'lease' => '', 'error_text' => '链接与媒体尚未完成准备，未上传或发表。')); return; }
        $post = get_post($job['post_id']);
        if (!$post || $post->post_status !== 'publish' || $post->post_password || !user_can($payload['requested_by'], 'crolord_publish_social') || !user_can($payload['requested_by'], 'edit_post', $job['post_id'])) { throw new RuntimeException('文章不再公开或发布者权限已撤销，已停止同步。'); }
        if ($job['platform'] === 'x' && $ids && empty($payload['link_layout'])) { Crolord_Publisher_Store::write($job, array('state'=>'succeeded','lease'=>'')); return; }
        if($job['platform']==='x'&&$ids&&!empty($payload['link_layout'])&&empty($progress['buffer']['id'])){
            Crolord_Publisher_Store::write($job,array('state'=>'external_paused','lease'=>'','error_text'=>'已有 X 帖子 ID，但缺少原 Buffer 记录；保留待核对，不新建或重发。'));return;
        }
        if ($job['platform'] === 'x' && empty($payload['format'])) { $payload['images'] = array_slice($payload['images'], 0, 4); }
        if ($job['platform'] === 'x' && !empty($progress['buffer']['id'])) { Crolord_Publisher_Buffer::step($job, $payload, $progress); return; }
        if ($job['platform'] === 'xiaohongshu') { Crolord_Publisher_XHS::step($job,$payload,$progress);return; }
        if ($job['platform']==='weibo'&&Crolord_Publisher_Weibo_Native::route($payload)) { Crolord_Publisher_Weibo_Native::step($job,$payload,$progress);return; }
        if ($job['platform']==='weibo'&&Crolord_Publisher_Weibo_Browser::route($payload)) { Crolord_Publisher_Store::write($job,array('state'=>$ids?'external_paused':(!empty($progress['weibo_browser']['publish_started'])?'unknown':'failed'),'lease'=>'','error_text'=>'浏览器/扩展通道已停用，原记录保留；仅显式迁移确定未发出的任务到插件内通道。'));return; }
        if ($job['platform']==='weibo'&&Crolord_Publisher_Weibo_API::route($payload)) { Crolord_Publisher_Store::write($job,array('state'=>$ids?'external_paused':(!empty($progress['weibo_api']['publish_started'])?'unknown':'failed'),'lease'=>'','error_text'=>'付费官方 API 通道已停用，未继续请求或消耗积分。旧记录保留；可显式迁移确定未发出的任务到插件内通道。'));return; }
        if ($job['platform']==='weibo'&&Crolord_Publisher_Weibo::web($payload)) {
            Crolord_Publisher_Store::write($job,array('state'=>$ids?'external_paused':(!empty($progress['weibo']['publish_started'])?'unknown':'failed'),'lease'=>'','error_text'=>'旧 PHP 扫码通道已停用。原记录与 ID 保留；可显式迁移确定未发出的任务到插件内通道。'));return;
        }
        $valid = Crolord_Publisher_Platforms::validate($job['platform'], $payload);
        if (is_wp_error($valid)) { self::fail($job, $valid); return; }
        if (Crolord_Publisher_Platforms::manual($job['platform'], $payload)) { Crolord_Publisher_Store::write($job, array('state' => 'manual_ready')); return; }
        if ($job['platform'] === 'x' && ($payload['x_route'] ?? 'api') === 'buffer') { Crolord_Publisher_Buffer::step($job, $payload, $progress); return; }
        self::fail($job, Crolord_Publisher_Platforms::retired());
    }
}
