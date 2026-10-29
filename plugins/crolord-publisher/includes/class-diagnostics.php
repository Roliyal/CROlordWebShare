<?php
if (!defined('ABSPATH')) { exit; }
final class Crolord_Publisher_Diagnostics {
    public static function boot() {
        foreach (array('account','environment','images','preview','enqueue','recover','run') as $action) {
            add_action('wp_ajax_crolord_publisher_' . $action, array(__CLASS__, $action));
        }
    }
    private static function authorize($settings = false) {
        check_ajax_referer('crolord_publisher_ajax', 'nonce');
        if (!current_user_can('crolord_publish_social') || ($settings && !current_user_can('manage_options'))) { wp_send_json_error(array('message'=>'没有权限。'),403); }
    }
    private static function post() {
        self::authorize();
        $id=absint($_POST['post']??0); $post=get_post($id);
        if (!$post || $post->post_type!=='post' || !current_user_can('edit_post',$id)) { wp_send_json_error(array('message'=>'没有此文章的编辑权限。'),403); }
        return $post;
    }
    public static function account() {
        self::authorize(true);
        wp_send_json_error(array('message'=>'旧平台 API 检测已移除。请使用 Publisher 中的 Buffer 账号连接。'),410);
    }
    public static function environment() {
        self::authorize(true);
        $stamp=(string)time();
        $response=wp_remote_post(admin_url('admin-ajax.php'),array('timeout'=>6,'redirection'=>0,'body'=>array('action'=>'crolord_publisher_worker','stamp'=>$stamp,'signature'=>hash_hmac('sha256','publisher-worker|'.$stamp,wp_salt('nonce')),'probe'=>'1')));
        $code=is_wp_error($response)?0:(int)wp_remote_retrieve_response_code($response);
        $body=is_wp_error($response)?array():json_decode(wp_remote_retrieve_body($response),true);
        $loopback=$code===200&&!empty($body['success'])&&!empty($body['data']['publisher_probe'])&&($body['data']['version']??'')===CROLORD_PUBLISHER_VERSION;
        $disabled=defined('DISABLE_WP_CRON')&&DISABLE_WP_CRON;
        $message=$loopback?'签名后台回环可达。':'后台回环未通过（HTTP '.$code.'），请检查服务器出站、WAF/安全插件和 TLS。可在文章任务中使用“继续处理此任务”绕过回环阻塞。';
        if ($disabled) { $message.=' WP-Cron 已禁用，定时需要现有系统 Cron 触发。'; }
        Crolord_Publisher_Clock::changed(); Crolord_Publisher_Clock::sync(true);
        $health = class_exists('Crolord_Echoes_Account') ? Crolord_Echoes_Account::request('health', array(), 4) : null;
        $clock = !is_wp_error($health) && is_array($health) ? ($health['publisher_clock'] ?? array()) : array();
        if (!empty($clock['enabled'])) {
            $message .= ' 独立定时唤醒已连接。';
            if (!empty($clock['last_ok'])) { $message .= ' 最近执行：' . wp_date('H:i:s', (int) $clock['last_ok']) . '。'; }
            if (!empty($clock['failures'])) { $message .= ' 最近回调未完成，正在退避重连。'; }
        } else { $message .= ' 独立定时唤醒尚未连接，当前由 WordPress Cron 继续处理。'; }
        $last=(int)get_option('crolord_publisher_last_run',0);
        wp_send_json_success(array('message'=>$message,'loopback'=>$loopback,'cron_disabled'=>(bool)$disabled,'next_tick'=>(int)wp_next_scheduled('crolord_publisher_tick'),'last_run'=>$last,'clock'=>$clock));
    }
    public static function discover($post,$maximum=9) {
        $found=Crolord_Publisher_Media::discover($post,get_current_user_id(),$maximum);$images=array();
        foreach($found['ids']as$id){$images[]=array('id'=>$id,'url'=>wp_get_attachment_image_url($id,'thumbnail'),'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true));}
        return array('images'=>$images,'unsupported'=>$found['blocked'],'audit'=>array_diff_key($found,array('ids'=>true)));
    }
    public static function images() {
        $post=self::post();$found=self::discover($post);$found['message']='已按正文顺序读取媒体库图片，最多九张；无正文图片时使用特色图。';
        if($found['unsupported']){$found['message'].=' '.(new Crolord_Publisher_Media_Error($found['audit']['issues']))->getMessage();}wp_send_json_success($found);
    }
    public static function preview() {
        $post=clone self::post();
        try{
            $input=wp_unslash($_POST);$content=$input['content']??null;
            if($content!==null){if(!is_string($content)||strlen($content)>4*1024*1024){throw new RuntimeException('预览内容过大，请先保存文章。');}$post->post_content=$content;}
            if(isset($input['article_title'])&&is_string($input['article_title'])){$post->post_title=sanitize_text_field($input['article_title']);}
            $selection=Crolord_Publisher_Admin::selection($input,get_post_meta($post->ID,'_crolord_publisher_selection',true));$selection['requested_by']=get_current_user_id();
            if(!Crolord_Publisher_Platforms::needs_images($selection)){$images=array();$warnings=0;$audit=array('source'=>'card','occurrences'=>0,'unique'=>0,'duplicates'=>0);}
            elseif($selection['media_mode']==='article'){$found=self::discover($post,Crolord_Publisher_Media::maximum($selection));$images=$found['images'];$warnings=$found['unsupported'];$audit=$found['audit'];}
            else{$images=array();$warnings=0;$audit=array('source'=>'manual','occurrences'=>count($selection['images']),'unique'=>count($selection['images']),'duplicates'=>0);foreach($selection['images']as$id){$images[]=array('id'=>$id,'url'=>wp_get_attachment_image_url($id,'thumbnail'),'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true));}}
            $limits=array('weibo'=>9,'x'=>4);if(in_array('xiaohongshu',$selection['platforms'],true)){$limits['xiaohongshu']=9;}
            $picks=array();foreach($limits as$key=>$max){$picks[$key]=Crolord_Publisher_Platforms::selected_images($images,$selection['media_mode']==='article'?null:($selection['platform_images'][$key]??array()),$max);}
            $layouts=array();foreach(array_keys($limits)as$key){$layouts[$key]=Crolord_Publisher_Platforms::layout($selection,$key);if($layouts[$key]==='card'||($key==='xiaohongshu'&&($selection['xhs_format']??'')==='video')){$picks[$key]=array();}}
            $result=array('title'=>$selection['title']?:wp_strip_all_tags($post->post_title),'url'=>get_permalink($post->ID),'site'=>wp_strip_all_tags(get_bloginfo('name')),'platform_images'=>$picks,'platform_layouts'=>$layouts,'warnings'=>$warnings,'audit'=>$audit,'share_layout'=>$selection['share_layout'],'card'=>in_array('card',$layouts,true)?Crolord_Publisher_Share_Metadata::build($post):null,'link_layout'=>'inline','slug_pending'=>!Crolord_Publisher_Poetic_Slug::ready($post->ID),'automatic'=>$selection['media_mode']==='article','enabled'=>!empty($selection['auto_sync']));
            if(in_array('xiaohongshu',$selection['platforms'],true)){
                $result['xhs']=Crolord_Publisher_XHS::compose($post,$selection,$result['title'],$result['url']);
                foreach($result['platform_images']['xiaohongshu']as&$picture){$picture['url']=wp_get_attachment_image_url($picture['id'],'medium_large')?:$picture['url'];}unset($picture);
            }
            wp_send_json_success($result);
        }catch(Throwable $e){wp_send_json_error(array('message'=>$e->getMessage()),400);}
    }
    public static function enqueue() {
        $post=self::post();
        if($post->post_status!=='publish'||$post->post_password){wp_send_json_error(array('message'=>'这篇文章尚未公开或有密码，请先在 WordPress 保存/发布。'),400);}
        try {
            $selection=Crolord_Publisher_Admin::selection(wp_unslash($_POST),get_post_meta($post->ID,'_crolord_publisher_selection',true));$selection['requested_by']=get_current_user_id();
            if(empty($selection['auto_sync'])){wp_send_json_error(array('message'=>'本篇已关闭同步，请先开启自动同步。'),400);}
            if(!$selection['platforms']){wp_send_json_error(array('message'=>'请先勾选至少一个平台。'),400);}
            $before=count(Crolord_Publisher_Store::jobs($post->ID)); update_post_meta($post->ID,'_crolord_publisher_selection',$selection);
            Crolord_Publisher::save($post->ID,$post,true,true);
            $jobs=Crolord_Publisher_Store::jobs($post->ID); $error=get_post_meta($post->ID,'_crolord_publisher_error',true);
            if (!$jobs && $error) { wp_send_json_error(array('message'=>$error),400); }
            $created=count($jobs)-$before;
            wp_send_json_success(array('message'=>$created?'已建立任务；等待链接或准备失败的原因会显示在任务中。':'已有任务保持原记录，请在下方查看或恢复尚未提交的准备任务。','created'=>$created));
        } catch(Throwable $e) { update_post_meta($post->ID,'_crolord_publisher_error',$e->getMessage());wp_send_json_error(array('message'=>$e->getMessage()),400); }
    }
    public static function recover() {
        $post = self::post();
        if (!Crolord_Publisher_Automatic::retry_preparation($post->ID)) { wp_send_json_error(array('message' => '请先在文章编辑页保存同步设置；已发表或没有准备记录的旧文章不会自动补发。'), 409); }
        Crolord_Publisher_Automatic::complete($post->ID);
        wp_send_json_success(array('message' => '已恢复原准备流程；请查看任务中的真实状态。'));
    }
    public static function run() {
        self::authorize();$job=Crolord_Publisher_Admin::authorize_job(absint($_POST['job']??0));
        if ($job['platform'] === 'wechat') { wp_send_json_error(array('message'=>Crolord_Publisher_Wechat_History::unavailable()->get_error_message()),410); }
        if(in_array($job['state'],array('succeeded','xhs_recorded','manual_ready','manual_recorded','cancelled'),true)){wp_send_json_success(array('state'=>$job['state'],'done'=>true,'message'=>'任务已结束，未再次发送。'));}
        if(!in_array($job['state'],array('queued','scheduled','external_pending'),true)||(int)$job['available']>time()){wp_send_json_error(array('message'=>'任务正在运行、尚未到时或需要先处理失败/待核对状态。'),409);}
        Crolord_Publisher::tick((int)$job['id']);$fresh=Crolord_Publisher_Store::get($job['id']);
        wp_send_json_success(array('state'=>$fresh['state'],'done'=>$fresh['state']!=='queued','message'=>$fresh['error_text']?:($fresh['state']==='xhs_recorded'?'创作中心已收录同一篇笔记；完整详情尚未核验，未重新发表。':(Crolord_Publisher_Admin::states()[$fresh['state']]??$fresh['state']))));
    }
}
