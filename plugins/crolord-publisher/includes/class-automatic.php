<?php
if (!defined('ABSPATH')) { exit; }

/** Publish transitions, including REST and WordPress's own scheduled publishing. */
final class Crolord_Publisher_Automatic {
    const PENDING='_crolord_publisher_auto_pending';
    const DONE='_crolord_publisher_auto_done';
    const OWNER='_crolord_publisher_schedule_owner';
    const EDITOR='_crolord_publisher_editor_fields';
    private static $pending=array();
    public static function boot() {
        add_action('init',array(__CLASS__,'register_meta'));
        add_action('transition_post_status',array(__CLASS__,'transition'),20,3);
        add_action('rest_after_insert_post',array(__CLASS__,'rest_saved'),30,3);
        add_action('wp_after_insert_post',array(__CLASS__,'after_insert'),40,4);
        add_action('crolord_publisher_prepare',array(__CLASS__,'complete'));
        add_action('shutdown',array(__CLASS__,'flush'),5);
    }
    public static function register_meta() {
        register_post_meta('post',self::EDITOR,array('type'=>'string','single'=>true,'show_in_rest'=>array('schema'=>array('type'=>'string','maxLength'=>12000,'context'=>array('edit'))),'sanitize_callback'=>array(__CLASS__,'editor_fields'),'auth_callback'=>function($allowed,$key,$id){return current_user_can('crolord_publish_social')&&current_user_can('edit_post',$id);}));
    }
    public static function editor_fields($value) {
        if(!is_string($value)||strlen($value)>12000){return '';}
        return $value; // Parsed only as form data; never executed or reflected as HTML.
    }
    public static function defaults($saved=array()) {
        $saved=is_array($saved)?$saved:array();$new=empty($saved['automatic_schema']);
        $default_platforms=array('weibo','x');
        if(class_exists('Crolord_Publisher_XHS')&&Crolord_Publisher_XHS::enabled()){$default_platforms[]='xiaohongshu';}
        $value=wp_parse_args($saved,array('automatic_schema'=>1,'auto_sync'=>true,'share_layout'=>'album','x_presentation'=>'card','platforms'=>array('weibo','x'),'title'=>'','location'=>'','place_id'=>'','images'=>array(),'platform_images'=>array(),'media_mode'=>empty($saved['images'])?'article':'manual','x_delivery'=>'buffer','weibo_delivery'=>'php','weibo_format'=>'images','weibo_statement'=>0,'weibo_video_id'=>0,'weibo_video_title'=>'','not_before'=>0));
        // Editor preferences apply only to future snapshots. Never rewrite an existing job.
        if(in_array($saved['share_layout']??'',array('platform','card'),true)){$value['weibo_format']='images';}
        $value['share_layout']='album';
        $value['x_presentation']=$value['x_presentation']==='photos'?'photos':'card';
        if(!array_key_exists('platforms',$saved)){$value['platforms']=$default_platforms;}
        $value['platforms']=array_values(array_intersect(array('weibo','x','xiaohongshu'),(array)$value['platforms']));
        if($new&&empty($value['platforms'])){$value['platforms']=array('weibo','x');}
        $value['automatic_schema']=1;$value['x_delivery']='buffer';$value['weibo_delivery']='php';$value['location']='';$value['place_id']='';
        if(in_array('xiaohongshu',$value['platforms'],true)){
            $value['xhs_title']=$saved['xhs_title']??'';$value['xhs_format']=($saved['xhs_format']??'images')==='video'?'video':'images';$value['xhs_video_id']=(int)($saved['xhs_video_id']??0);$value['xhs_cover_id']=(int)($saved['xhs_cover_id']??0);
            $value['xhs_text']=$saved['xhs_text']??'';
        }
        return $value;
    }
    private static function owner($post) {
        $current=get_current_user_id();$actor=$current?:((int)get_post_meta($post->ID,self::OWNER,true)?:$post->post_author);
        return $actor&&user_can($actor,'crolord_publish_social')&&user_can($actor,'edit_post',$post->ID)?(int)$actor:0;
    }
    public static function transition($new,$old,$post) {
        if(($post->post_type??'')!=='post'||$new===$old||wp_is_post_revision($post->ID)||wp_is_post_autosave($post->ID)){return;}
        if($new==='future'){$owner=self::owner($post);if($owner){update_post_meta($post->ID,self::OWNER,$owner);}return;}
        if($new!=='publish'||$old==='publish'||$post->post_password||get_post_meta($post->ID,self::DONE,true)){return;}
        $owner=self::owner($post);if(!$owner){return;}
        $pending=get_post_meta($post->ID,self::PENDING,true);
        if(!is_array($pending)){add_post_meta($post->ID,self::PENDING,array('by'=>$owner,'at'=>time()),true);}
        self::defer($post->ID);
    }
    public static function defer($id) {
        if(!get_post_meta($id,self::PENDING,true)){return;}
        self::$pending[(int)$id]=true;
        if(!wp_next_scheduled('crolord_publisher_prepare',array((int)$id))){wp_schedule_single_event(time()+30,'crolord_publisher_prepare',array((int)$id));}
    }
    /** WordPress invokes this after REST metadata and classic-editor fields have been saved. */
    public static function after_insert($id, $post, $update = false, $before = null) {
        if (($post->post_type ?? '') !== 'post' || $post->post_status !== 'publish' || $post->post_password || wp_is_post_revision($id) || wp_is_post_autosave($id)) { return; }
        $new_publication = (!$update && !$before) || ($before && $before->post_status !== 'publish');
        $pending = get_post_meta($id, self::PENDING, true);
        if (!is_array($pending) && !get_post_meta($id, self::DONE, true) && $new_publication) {
            self::transition('publish', $before->post_status ?? 'new', $post); $pending = get_post_meta($id, self::PENDING, true);
        }
        if (!is_array($pending)) { return; }
        if ($new_publication && !get_post_meta($id, Crolord_Publisher_Poetic_Slug::META, true)) { Crolord_Publisher_Poetic_Slug::prepare($post); }
        $pending['ready'] = true; update_post_meta($id, self::PENDING, $pending);
        $selection = self::defaults(get_post_meta($id, '_crolord_publisher_selection', true)); $selection['requested_by'] = (int) ($pending['by'] ?? 0);
        if (!empty($selection['auto_sync']) && $selection['platforms']) {
            try { Crolord_Publisher::reserve_selection($id, $selection); }
            catch (Throwable $e) { update_post_meta($id, '_crolord_publisher_error', $e->getMessage()); }
        }
        self::$pending[(int) $id] = true;
        wp_clear_scheduled_hook('crolord_publisher_prepare', array((int) $id));
        wp_schedule_single_event(time() + 1, 'crolord_publisher_prepare', array((int) $id));
    }
    /** Keep the authorized intent until its canonical URL can be frozen. */
    public static function wait($id, $owner) {
        $pending = get_post_meta($id, self::PENDING, true);
        if (!is_array($pending)) { $pending = array('by' => $owner, 'at' => time(), 'ready' => true); }
        update_post_meta($id, self::PENDING, $pending); delete_post_meta($id, self::DONE);
        wp_clear_scheduled_hook('crolord_publisher_prepare', array((int) $id));
        wp_schedule_single_event(time() + 60, 'crolord_publisher_prepare', array((int) $id));
    }
    /** An explicit editor save can resume preparation; sent/native-ID jobs are never rewritten. */
    public static function retry_preparation($id) {
        $done = get_post_meta($id, self::DONE, true); $pending = get_post_meta($id, self::PENDING, true); $jobs = Crolord_Publisher_Store::jobs($id);
        $reservations = array_filter($jobs, array('Crolord_Publisher_Preparation', 'mutable'));
        $error = get_post_meta($id, '_crolord_publisher_error', true);
        if ((!is_array($pending) && empty($done['error']) && !$reservations && !(!$jobs && !$done && $error)) || ($jobs && !$reservations)) { return false; }
        $post = get_post($id); $owner = get_current_user_id(); $selection = self::defaults(get_post_meta($id, '_crolord_publisher_selection', true));
        if (!$post || $post->post_status !== 'publish' || $post->post_password || !$owner || !user_can($owner, 'crolord_publish_social') || !user_can($owner, 'edit_post', $id) || empty($selection['auto_sync']) || !$selection['platforms']) { return false; }
        delete_post_meta($id, self::DONE); update_post_meta($id, self::PENDING, array('by' => $owner, 'at' => time(), 'ready' => true)); self::defer($id); return true;
    }
    /** Only previous durable pending intent is recovered automatically, never arbitrary old articles. */
    public static function recover_pending() {
        if (!current_user_can('crolord_publish_social')) { return; }
        $ids = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 10, 'orderby' => 'ID', 'order' => 'DESC', 'meta_key' => self::PENDING, 'no_found_rows' => true, 'suppress_filters' => false)); $completed = 0;
        foreach ($ids as $id) {
            if (!current_user_can('edit_post', $id) || get_post_meta($id, self::DONE, true)) { continue; }
            $post = get_post($id); $pending = get_post_meta($id, self::PENDING, true); $by = (int) ($pending['by'] ?? 0);
            if (!$post || $post->post_password || !is_array($pending) || !$by || !user_can($by, 'crolord_publish_social') || !user_can($by, 'edit_post', $id)) { continue; }
            $selection = self::defaults(get_post_meta($id, '_crolord_publisher_selection', true)); $selection['requested_by'] = $by;
            if (empty($selection['auto_sync']) || !$selection['platforms']) { continue; }
            try {
                Crolord_Publisher::reserve_selection($id, $selection);
                if (!empty($pending['ready']) && $completed < 3) { self::complete($id); $completed++; }
                elseif (empty($pending['ready'])) { Crolord_Publisher_Preparation::notes($id, '等待文章发布设置确认；保存文章或点击重新准备后继续。', true); }
            } catch (Throwable $e) { update_post_meta($id, '_crolord_publisher_error', $e->getMessage()); }
        }
    }
    public static function rest_saved($post,$request=null,$creating=false) {
        $meta=is_object($request)&&method_exists($request,'get_param')?$request->get_param('meta'):null;
        if(is_array($meta)&&isset($meta[self::EDITOR])&&current_user_can('crolord_publish_social')&&current_user_can('edit_post',$post->ID)){
            $encoded=self::editor_fields($meta[self::EDITOR]);
            if($encoded!==''){
                parse_str($encoded,$input);
                try{$selection=Crolord_Publisher_Admin::selection($input,get_post_meta($post->ID,'_crolord_publisher_selection',true));$selection['requested_by']=get_current_user_id();update_post_meta($post->ID,'_crolord_publisher_selection',$selection);self::retry_preparation($post->ID);}
                catch(Throwable $e){self::halt($post->ID,$e->getMessage());return;}
            }
        }
        self::defer($post->ID);
    }
    public static function flush() {
        $ids=array_keys(self::$pending);self::$pending=array();foreach($ids as$id){
            $pending=get_post_meta($id,self::PENDING,true);
            if(is_array($pending)){$pending['ready']=true;update_post_meta($id,self::PENDING,$pending);}
            self::complete($id);
        }
    }
    public static function halt($id,$message) {
        update_post_meta($id,'_crolord_publisher_error',$message);
        if(get_post_meta($id,self::PENDING,true)){add_post_meta($id,self::DONE,array('at'=>time(),'error'=>true),true);delete_post_meta($id,self::PENDING);wp_clear_scheduled_hook('crolord_publisher_prepare',array((int)$id));}
    }
    public static function complete($id) {
        $pending = get_post_meta($id, self::PENDING, true);
        if (!is_array($pending) || empty($pending['ready']) || get_post_meta($id, self::DONE, true)) { return; }
        $post = get_post($id);
        if (!$post || $post->post_status !== 'publish' || $post->post_password) { Crolord_Publisher_Preparation::notes($id, '文章不再公开或有密码，未提交平台。'); delete_post_meta($id, self::PENDING); return; }
        $owner = (int) ($pending['by'] ?? 0);
        if (!$owner || !user_can($owner, 'crolord_publish_social') || !user_can($owner, 'edit_post', $id)) { Crolord_Publisher_Preparation::notes($id, '发布者权限已改变，未提交平台。'); delete_post_meta($id, self::PENDING); return; }
        $selection = self::defaults(get_post_meta($id, '_crolord_publisher_selection', true)); $selection['requested_by'] = $owner;
        try {
            if (!empty($selection['auto_sync']) && $selection['platforms']) {
                foreach (Crolord_Publisher_Store::jobs($id) as $j) {
                    if (Crolord_Publisher_Preparation::mutable($j) && !in_array($j['platform'], $selection['platforms'], true)) { Crolord_Publisher_Preparation::action($j, 'cancel'); }
                }
                update_post_meta($id, '_crolord_publisher_selection', $selection);
                Crolord_Publisher::enqueue_selection($id, $selection);
                $rows = Crolord_Publisher_Store::jobs($id);
                if (Crolord_Publisher_Store::read_failed()) { throw new RuntimeException('发布任务暂时无法回读，原准备记录已保留。'); }
                $waiting = array_filter($rows, function ($j) { return Crolord_Publisher_Preparation::mutable($j) && $j['state'] === 'preparing'; });
                if (!Crolord_Publisher_Poetic_Slug::ready($id)) {
                    if ($waiting) { return; }
                }
                elseif ($waiting) { throw new RuntimeException(get_post_meta($id, '_crolord_publisher_error', true) ?: '部分任务未能保存准备检查点；尚未提交的平台任务已保留。'); }
            } else {
                foreach (Crolord_Publisher_Store::jobs($id) as $j) { if (Crolord_Publisher_Preparation::mutable($j)) { Crolord_Publisher_Preparation::action($j, 'cancel'); } }
            }
            $failed = array_filter(Crolord_Publisher_Store::jobs($id), function ($j) { return Crolord_Publisher_Preparation::mutable($j) && $j['state'] === 'prepare_failed'; });
            add_post_meta($id, self::DONE, array('at' => time(), 'by' => $owner, 'enabled' => !empty($selection['auto_sync']), 'error' => (bool) $failed), true);
            if (!$failed) { delete_post_meta($id, '_crolord_publisher_error'); }
            delete_post_meta($id, self::PENDING); wp_clear_scheduled_hook('crolord_publisher_prepare', array((int) $id)); Crolord_Publisher::kick();
        } catch (Throwable $e) {
            update_post_meta($id, '_crolord_publisher_error', $e->getMessage());
            try { Crolord_Publisher_Preparation::notes($id, $e->getMessage()); }
            catch (Throwable $checkpoint_error) { /* The post meta above keeps the original failure visible if queue writes fail. */ }
            add_post_meta($id, self::DONE, array('at' => time(), 'by' => $owner, 'error' => true), true);
            delete_post_meta($id, self::PENDING); wp_clear_scheduled_hook('crolord_publisher_prepare', array((int) $id));
        }
    }
}
