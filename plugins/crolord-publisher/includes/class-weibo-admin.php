<?php
if (!defined('ABSPATH')) { exit; }
/** Plugin-native QR/session controls; no remote desktop or extension enrollment. */
final class Crolord_Publisher_Weibo_Admin {
    public static function boot(){
        add_action('wp_ajax_crolord_publisher_weibo',array(__CLASS__,'ajax'));
        add_action('admin_enqueue_scripts',function($hook){
            if(!current_user_can('manage_options')||$hook!=='settings_page_crolord-publisher'){return;}
            wp_enqueue_style('crolord-weibo-native',plugins_url('assets/weibo-native.css',CROLORD_PUBLISHER_FILE),array(),CROLORD_PUBLISHER_VERSION);
            wp_enqueue_script('crolord-weibo-native',plugins_url('assets/weibo-native.js',CROLORD_PUBLISHER_FILE),array(),CROLORD_PUBLISHER_VERSION,true);
            wp_localize_script('crolord-weibo-native','CrolordWeibo',array('ajax'=>admin_url('admin-ajax.php'),'nonce'=>wp_create_nonce('crolord_weibo_admin')));
        });
    }
    public static function render(){
        $c=Crolord_Publisher_Weibo_Session::config();
        echo '<section class="cp-weibo cp-weibo-native"><div class="cp-weibo-heading"><h2>微博</h2><span>图文与视频</span></div>';
        echo '<p class="cp-weibo-account">'.esc_html($c['uid']?($c['name']?:'微博账号').' · UID '.$c['uid']:'尚未连接微博').'</p><p class="cp-weibo-readiness">'.esc_html($c['status']).'</p><p class="cp-weibo-runtime">'.esc_html($c['enabled']?'插件内自动同步已启用':'插件内自动同步已暂停').'</p><div class="cp-weibo-actions"><button type="button" class="button button-primary" data-weibo="qr_start">扫码连接微博</button><button type="button" class="button" data-weibo="check">检查账号连接</button><button type="button" class="button" data-weibo="enable">启用自动同步</button><button type="button" class="button" data-weibo="pause">暂停</button></div><p class="cp-weibo-notice" role="status" aria-live="polite"></p><div class="cp-weibo-qr" hidden><img alt="本人微博 App 扫码登录" width="180" height="180"><p class="cp-weibo-qr-state">等待扫码</p><button type="button" class="button" data-weibo="qr_poll">检查扫码进度</button><button type="button" class="button" data-weibo="qr_cancel">关闭二维码</button></div>';
        echo '<details class="cp-weibo-diagnostic" hidden><summary>连接诊断 · 已脱敏</summary><p class="description">仅含步骤、状态码与字段是否存在，不含 Cookie、登录票据或账号值。</p><pre tabindex="0"></pre><button type="button" class="button" data-weibo-copy>复制连接诊断</button><span class="cp-weibo-copy-state" role="status" aria-live="polite"></span></details>';
        echo '<details><summary>文件上限与连接管理</summary><label>单张图片 · MB<input name="weibo_image_mb" type="number" min="1" max="20" value="'.(int)$c['image_mb'].'"></label><label>单个视频 · MB<input name="weibo_video_mb" type="number" min="1" max="1024" value="'.(int)$c['video_mb'].'"></label><button type="button" class="button" data-weibo="limits">保存上限</button><button type="button" class="button" data-weibo="disconnect">断开本地连接并暂停</button></details></section>';
    }
    public static function state(){
        $c=Crolord_Publisher_Weibo_Session::config();return array('uid'=>$c['uid'],'name'=>$c['name'],'enabled'=>(bool)$c['enabled'],'status'=>$c['status'],'verified'=>(int)$c['verified'],'protocol'=>(int)$c['protocol'],'mode'=>'php');
    }
    private static function busy(){global $wpdb;return $wpdb->get_var('SELECT id FROM '.Crolord_Publisher_Store::table()." WHERE platform='weibo' AND state IN ('running','publishing') AND touched>".(time()-180).' LIMIT 1');}
    private static function modify($work){$r=Crolord_Publisher_Store::locked(function()use($work){$c=Crolord_Publisher_Weibo_Session::config();$c=$work($c);Crolord_Publisher_Weibo_Session::save($c);return true;});if(is_wp_error($r)){throw new RuntimeException('账号设置正在更新，请稍后重试。');}}
    private static function check(){
        $before=Crolord_Publisher_Weibo_Session::config();$binding=Crolord_Publisher_Weibo_Native::binding($before);$jar=Crolord_Publisher_Weibo_Session::jar();$who=Crolord_Publisher_Weibo_Session::identity($jar);
        $checked='';self::modify(function($c)use($who,$jar,$binding,&$checked){if(!hash_equals($binding,Crolord_Publisher_Weibo_Native::binding($c))||$c['uid']!==$who['uid']){throw new RuntimeException('当前微博账号或连接已改变，未覆盖原账号。');}if((int)$c['protocol']!==2){$c['enabled']=false;$c['revision']++;}$c['protocol']=2;$c['session']=Crolord_Publisher_Store::encrypt(wp_json_encode($jar));$c['verified']=time();$c['status']='本人 UID 已核验；媒体上传与发表仍需实际任务验证';$checked=Crolord_Publisher_Weibo_Native::binding($c);return $c;});return $checked;
    }
    public static function operation($op,$input=array()){
        $data=array('message'=>'设置已更新。');
        if($op==='poll'){return self::state()+array('message'=>'');}
        if($op==='qr_start'){
            if(self::busy()){throw new RuntimeException('微博任务正在处理，请先暂停并等待当前任务结束。');}self::modify(function($c){$c['enabled']=false;$c['status']='扫码连接中，自动同步已暂停';return $c;});$data=Crolord_Publisher_Weibo_Native::qr_start();
        }elseif($op==='qr_poll'){$data=Crolord_Publisher_Weibo_Native::qr_check((string)($input['handle']??''));}
        elseif($op==='qr_cancel'){delete_transient(Crolord_Publisher_Weibo_Native::qkey());$data['message']='已关闭本次二维码，原连接保持暂停。';}
        elseif($op==='check'){self::check();$data['message']='本人账号连接检查通过；不代表已完成发布。';}
        elseif($op==='enable'){if(get_transient(Crolord_Publisher_Weibo_Native::qkey())){throw new RuntimeException('请先完成登录或关闭当前二维码，再启用同步。');}$checked=self::check();self::modify(function($c)use($checked){if(!$c['uid']||!$c['session']||(int)$c['protocol']!==2||!hash_equals($checked,Crolord_Publisher_Weibo_Native::binding($c))){throw new RuntimeException('请先扫码核对本人 UID，连接已改变时重新检查。');}$c['enabled']=true;$c['status']='插件内自动同步已启用';return $c;});}
        elseif($op==='pause'){self::modify(function($c){$c['enabled']=false;$c['status']='插件内自动同步已暂停';return $c;});}
        elseif($op==='limits'){self::modify(function($c)use($input){$c['image_mb']=min(20,max(1,absint($input['image_mb']??5)));$c['video_mb']=min(1024,max(1,absint($input['video_mb']??100)));return $c;});}
        elseif($op==='disconnect'){if(self::busy()){throw new RuntimeException('任务正在处理，请先暂停并等待当前任务结束。');}delete_transient(Crolord_Publisher_Weibo_Native::qkey());self::modify(function($c){$c['enabled']=false;$c['session']='';$c['uid']='';$c['name']='';$c['verified']=0;$c['protocol']=0;$c['revision']++;$c['status']='已断开本地连接，历史任务与 ID 保留';return $c;});}
        else{throw new RuntimeException('旧执行器操作入口已停用，请刷新 Publisher 设置。');}
        // Preserve QR-stage status separately from the persistent connection state.
        if(isset($data['status'])){$data['qr_status']=$data['status'];unset($data['status']);}return array_merge(self::state(),$data);
    }
    public static function ajax(){
        check_ajax_referer('crolord_weibo_admin','nonce');if(!current_user_can('manage_options')||!current_user_can('crolord_publish_social')){wp_send_json_error(array('message'=>'只有管理员可以操作微博连接。'),403);return;}
        try{$data=self::operation(sanitize_key($_POST['operation']??''),wp_unslash($_POST));wp_send_json_success($data);}catch(Throwable $e){wp_send_json_error(self::error_response($e),400);}
    }
    public static function error_response($error){$data=array('message'=>$error->getMessage());if($error instanceof Crolord_Publisher_Weibo_Connection_Error){$data['diagnostic']=$error->diagnostic;}return $data;}
}
