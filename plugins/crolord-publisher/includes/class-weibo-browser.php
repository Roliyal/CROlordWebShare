<?php
if (!defined('ABSPATH')) { exit; }

/** Outbound-only, single-account browser worker. No PHP requests to Weibo. */
final class Crolord_Publisher_Weibo_Browser {
    const OPTION = 'crolord_publisher_weibo_browser';
    const NS = 'crolord-publisher/v3';
    public static function config() {
        return wp_parse_args(get_option(self::OPTION, array()), array('device'=>'','kind'=>'server','token_hash'=>'','pair_hash'=>'','pair_expires'=>0,'uid'=>'','name'=>'','revision'=>1,'enabled'=>false,'seen'=>0,'worker'=>'','status'=>'尚未连接微博执行器','login_until'=>0,'view_until'=>0,'command'=>array(),'image_mb'=>5,'video_mb'=>100));
    }
    public static function save($c) { if (!update_option(self::OPTION, $c, false) && get_option(self::OPTION)!==$c) { throw new RuntimeException('浏览器设置保存失败。'); } }
    public static function route($p) { return ($p['weibo_route']??'')==='browser'; }
    public static function binding($c) { return hash('sha256', $c['device'].'|'.$c['uid'].'|'.$c['revision']); }
    public static function boot() {
        add_action('rest_api_init', function () {
            register_rest_route(self::NS, '/weibo-browser/pair', array('methods'=>'POST','permission_callback'=>'__return_true','callback'=>array(__CLASS__,'pair')));
            register_rest_route(self::NS, '/weibo-browser/(?P<op>probe|poll|claim|progress|authorize|receipt|result|package)', array('methods'=>'POST','permission_callback'=>array(__CLASS__,'permission'),'callback'=>function($r){return self::dispatch($r,(string)$r['op']);}));
            register_rest_route(self::NS, '/weibo-browser/media/(?P<job>\d+)/(?P<item>\d+)', array('methods'=>'GET','permission_callback'=>array(__CLASS__,'media_permission'),'callback'=>function(){return new WP_REST_Response(array('crolord_browser_media'=>true),200);}));
        });
        add_filter('rest_pre_serve_request', array(__CLASS__,'serve_media'),10,4);
    }
    public static function permission($r) {
        $c=self::config(); $auth=(string)$r->get_header('authorization');if(!$auth&&$r->get_header('x-crolord-token')){$auth='Bearer '.$r->get_header('x-crolord-token');}
        if (!preg_match('/^Bearer ([a-f0-9]{64})$/D',$auth,$m)||!$c['token_hash']||!hash_equals($c['token_hash'],hash('sha256',$m[1]))||!$c['device']||!hash_equals($c['device'],(string)$r->get_header('x-crolord-device'))) { return new WP_Error('browser_auth','浏览器配对授权无效。',array('status'=>401)); }
        return true;
    }
    public static function pair($r) {
        $b=$r->get_json_params();
        $v=Crolord_Publisher_Store::locked(function() use($b) {
            $c=self::config(); $code=is_array($b)?(string)($b['code']??''):''; $device=(string)($b['device']??'');
            if (!preg_match('/^[a-f0-9]{32}$/D',$code)||!$c['pair_hash']||$c['pair_expires']<time()||!hash_equals($c['pair_hash'],hash('sha256',$code))||$c['device']||!preg_match('/^[a-f0-9-]{36}$/D',$device)) { return new WP_Error('browser_pair','配对码无效、过期或已使用。',array('status'=>403)); }
            $token=bin2hex(random_bytes(32)); $c['device']=$device; $c['kind']=($b['kind']??'')==='extension'?'extension':'server';$c['token_hash']=hash('sha256',$token); $c['pair_hash']=''; $c['pair_expires']=0; $c['seen']=time();$c['uid']='';$c['name']='';$c['enabled']=false;$c['login_until']=0;$c['view_until']=0;$c['command']=array();$c['status']=$c['kind']==='extension'?'扩展已配对，请在本人浏览器登录微博并检查账号':'组件已配对，请打开登录窗口'; self::save($c);
            return array('token'=>$token,'device'=>$device,'version'=>CROLORD_PUBLISHER_VERSION,'protocol'=>1);
        });
        return is_wp_error($v)?$v:new WP_REST_Response($v,200,array('Cache-Control'=>'private, no-store'));
    }
    public static function media($p,$verify=true) {
        $items=($p['weibo_format']??'images')==='video'?array($p['weibo_video']??array()):($p['images']??array()); $out=array(); $c=self::config();
        foreach ($items as $m) {
            $id=(int)($m['id']??0); if (!$id||!user_can($p['requested_by'],'edit_post',$id)) { throw new RuntimeException('所选媒体权限无效。'); }
            $f=Crolord_Publisher_Media::local($id); $mime=wp_check_filetype_and_ext($f,basename($f))['type']??''; $video=($p['weibo_format']??'images')==='video';
            if (($video&&($mime!=='video/mp4'||substr(file_get_contents($f,false,null,0,12),4,4)!=='ftyp'))||(!$video&&!in_array(wp_get_image_mime($f),array('image/jpeg','image/png'),true))) { throw new RuntimeException('图片需为 JPEG/PNG，视频需为单个 MP4。'); }
            $size=filesize($f); if (!$size||$size>$c[$video?'video_mb':'image_mb']*1048576||($verify&&!hash_equals((string)$m['sha256'],hash_file('sha256',$f)))) { throw new RuntimeException('媒体已改变、为空或超过后台文件上限。'); }
            $out[]=array('id'=>$id,'size'=>$size,'sha256'=>$m['sha256'],'mime'=>$video?'video/mp4':wp_get_image_mime($f),'ext'=>$video?'mp4':(wp_get_image_mime($f)==='image/jpeg'?'jpg':'png'));
        }
        return $out;
    }
    public static function validate($p,$files=true) {
        $c=self::config();
        if (!$c['enabled']||!$c['device']||!$c['uid']) { return new WP_Error('browser_setup','请先配对浏览器扩展、登录微博并在本站启用自动发布。'); }
        if (($p['account']??'')!==$c['uid']||!hash_equals(self::binding($c),(string)($p['browser_binding']??''))) { return new WP_Error('browser_account','微博绑定已改变，请核对账号后显式采用当前设置。'); }
        if (empty($p['title'])||strlen($p['title'])>900||!preg_match('~^https://~i',$p['url']??'')) { return new WP_Error('browser_text','请填写有效标题和文章 HTTPS 链接。'); }
        $video=($p['weibo_format']??'images')==='video';
        if ($video) {
            if (empty($p['weibo_video'])||!empty($p['images'])) { return new WP_Error('browser_media','视频需要单独选择一个 MP4。'); }
            preg_match_all('/./us',($p['weibo_video_title']??'')?:$p['title'],$chars); if(count($chars[0])>30){return new WP_Error('browser_title','请单独填写不超过 30 字的视频标题，完整文章标题仍保留在正文。');}
        } elseif (empty($p['images'])||count($p['images'])>9) { return new WP_Error('browser_media','请选择 1–9 张图片。'); }
        try { if($files){self::media($p);} } catch(Throwable $e) { return new WP_Error('browser_media',$e->getMessage()); }
        return true;
    }
    public static function valid_job($job,$files=false) {
        $p=json_decode($job['payload'],true); $post=get_post($job['post_id']);
        if ($job['platform']!=='weibo'||!self::route($p)||!$post||$post->post_status!=='publish'||$post->post_password||!user_can($p['requested_by'],'crolord_publish_social')||!user_can($p['requested_by'],'edit_post',$job['post_id'])) { throw new RuntimeException('文章不再公开或发布者权限已撤销。'); }
        $valid=self::validate($p,$files); if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());} return $p;
    }
    public static function write($job,$values) {
        global $wpdb; $values['touched']=time(); $where=array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease']);
        $n=$wpdb->update(Crolord_Publisher_Store::table(),$values,$where);
        if ($n===false) { throw new RuntimeException('检查点写入失败，已停止。'); }
        if (!$n) { $fresh=Crolord_Publisher_Store::get($job['id']); foreach(array_merge($where,$values) as $key=>$value){if(!$fresh||(string)$fresh[$key] !== (string)$value){throw new RuntimeException('任务已改变或检查点未保存，已停止。');}} }
    }
    private static function item($job) {
        $p=self::valid_job($job); $media=self::media($p,false); foreach($media as $i=>&$m){$m['url']=rest_url(self::NS.'/weibo-browser/media/'.$job['id'].'/'.$i);unset($m['id']);} unset($m);
        return array('id'=>(int)$job['id'],'lease'=>$job['lease'],'attempts'=>(int)$job['attempts'],'payload_hash'=>hash('sha256',$job['payload']),'title'=>$p['title'],'caption'=>$p['title']."\n\n".$p['url'],'url'=>$p['url'],'account'=>$p['account'],'format'=>$p['weibo_format'],'video_title'=>($p['weibo_video_title']??'')?:$p['title'],'statement'=>(int)($p['weibo_statement']??0),'reset'=>(int)count((json_decode($job['progress'],true)['weibo_browser']['previous_intents']??array())),'media'=>$media,'remote_ids'=>json_decode($job['remote_ids'],true)?:array());
    }
    public static function dispatch($r,$op) {
        $auth=self::permission($r); if(is_wp_error($auth)){return $auth;} $b=$r->get_json_params(); if(!is_array($b)){return new WP_Error('browser_body','请求无效。',array('status'=>400));}
        $value=Crolord_Publisher_Store::locked(function()use($r,$op,$b){
            try {
                global $wpdb; $auth=self::permission($r); if(is_wp_error($auth)){return $auth;} $c=self::config(); $table=Crolord_Publisher_Store::table();
                if($op==='probe'){
                    $busy=$wpdb->get_var("SELECT id FROM $table WHERE runner='browser' AND state IN ('running','publishing') LIMIT 1");
                    return array('component'=>'crolord-publisher','protocol'=>1,'extension_protocol'=>1,'kind'=>$c['kind'],'version'=>CROLORD_PUBLISHER_VERSION,'device'=>$c['device'],'enabled'=>(bool)$c['enabled'],'busy'=>(bool)$busy);
                }
                if ($op==='package') {
                    $files=array(); foreach(array('worker.cjs','driver.cjs','core.cjs') as $name){$f=dirname(CROLORD_PUBLISHER_FILE).'/browser/'.$name;if(!is_file($f)){throw new RuntimeException('浏览器运行包缺少必要模块。');}$files[]=array('name'=>$name,'sha256'=>hash_file('sha256',$f),'content'=>base64_encode(file_get_contents($f)));}
                    return array('protocol'=>1,'runtime'=>'playwright-1.58.2','version'=>CROLORD_PUBLISHER_VERSION,'files'=>$files);
                }
                if ($op==='poll') {
                    $changed=false;
                    if($c['seen']<time()-25){$c['seen']=time();$c['worker']=substr(sanitize_text_field((string)($b['version']??'')),0,30);$changed=true;}
                    if(isset($b['status'])){$status=substr(sanitize_text_field((string)$b['status']),0,240);if($status!==$c['status']){$c['status']=$status;$changed=true;}}
                    if(!empty($b['pause'])&&$c['enabled']){$c['enabled']=false;$changed=true;}
                    if(!empty($b['uid'])&&preg_match('/^\d{5,24}$/D',(string)$b['uid'])){
                        if($c['uid']&&$c['uid']!==(string)$b['uid']){$c['enabled']=false;$c['status']='登录账号与已绑定 UID 不同，请解除配对后重新绑定';$changed=true;}
                        elseif(!$c['uid']){$c['uid']=(string)$b['uid'];$c['name']=substr(sanitize_text_field((string)($b['name']??'')),0,80);$changed=true;}
                    }
                    if(!empty($b['ack'])&&($c['command']['id']??'')===$b['ack']){$c['command']=array();$changed=true;}
                    if($c['login_until']>time()&&$c['view_until']>time()&&!empty($b['preview'])&&is_string($b['preview'])&&strlen($b['preview'])<600000&&preg_match('/^[A-Za-z0-9+\/=]+$/D',$b['preview'])){
                        $bytes=base64_decode($b['preview'],true);if($bytes!==false&&substr($bytes,0,3)==="\xff\xd8\xff"){set_transient('crolord_weibo_browser_preview',Crolord_Publisher_Store::encrypt($b['preview']),30);}
                    }
                    if($changed){self::save($c);} $command=$c['login_until']>time()?$c['command']:array();if(!empty($command['encrypted'])){$command['text']=Crolord_Publisher_Store::decrypt($command['text']);unset($command['encrypted']);} return array('enabled'=>(bool)$c['enabled'],'account'=>$c['uid'],'login_until'=>$c['login_until'],'preview'=>$c['view_until']>time(),'command'=>$command,'wait'=>$c['login_until']>time()?2:30);
                }
                if ($op==='claim') {
                    if(!$c['enabled']||!$c['uid']||$c['login_until']>time()){return array('job'=>null);}
                    $wpdb->query($wpdb->prepare("UPDATE $table SET state='unknown',lease='',error_text='浏览器连接中断；可能已发表，请核对后处理。' WHERE runner='browser' AND state='publishing' AND touched<%d",time()-180));
                    $wpdb->query($wpdb->prepare("UPDATE $table SET state='failed',lease='',error_text='浏览器准备阶段中断，尚未获得发表授权；可重试。' WHERE runner='browser' AND state='running' AND touched<%d",time()-180));
                    $active=$wpdb->get_var("SELECT id FROM $table WHERE runner='browser' AND state IN ('running','publishing','unknown','external_paused') LIMIT 1"); if($active){return array('job'=>null,'message'=>'前一条浏览器任务待处理。');}
                    $job=$wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE runner='browser' AND state IN ('queued','scheduled','external_pending') AND available<=%d ORDER BY id LIMIT 1",time()),ARRAY_A); if(!$job){return array('job'=>null);}
                    try{self::valid_job($job,true);}catch(Throwable $e){self::write($job,array('state'=>'failed','error_text'=>$e->getMessage()));return array('job'=>null);}
                    self::write($job,array('state'=>'running','lease'=>wp_generate_uuid4(),'attempts'=>(int)$job['attempts']+1,'error_text'=>''));return array('job'=>self::item(Crolord_Publisher_Store::get($job['id'])));
                }
                $job=Crolord_Publisher_Store::get(absint($b['id']??0));
                if(!$job||($job['runner']??'')!=='browser'||!$job['lease']||!hash_equals($job['lease'],(string)($b['lease']??''))||!in_array($job['state'],array('running','publishing'),true)){throw new RuntimeException('任务租约无效或状态已变化。');}
                $p=json_decode($job['payload'],true); $progress=json_decode($job['progress'],true)?:array(); $w=$progress['weibo_browser']??array(); $ids=json_decode($job['remote_ids'],true)?:array();
                if($op==='progress') {
                    self::valid_job($job); $phase=sanitize_key($b['phase']??'');if(!in_array($phase,array('account','media','composer','uploading','checking','receipt','verifying'),true)){throw new RuntimeException('进度无效。');}
                    $w['phase']=$phase;$progress['weibo_browser']=$w;self::write($job,array('progress'=>wp_json_encode($progress)));return array('ok'=>true);
                }
                if($op==='authorize') {
                    if($job['state']!=='running'||!empty($w['publish_started'])||$ids){return array('allowed'=>false);}
                    self::valid_job($job,true);$e=$b['evidence']??array();$m=self::media($p,false);
                    if(($e['account']??'')!==$p['account']||($e['caption']??'')!==$p['title']."\n\n".$p['url']||($e['hashes']??array())!==array_column($m,'sha256')||(int)($e['count']??0)!==count($m)){throw new RuntimeException('发表前账号、完整文案或媒体数量核验未通过。');}
                    $w['publish_started']=time();$w['phase']='publishing';$progress['weibo_browser']=$w;self::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));return array('allowed'=>true);
                }
                if($op==='receipt') {
                    if(empty($w['publish_started'])||!preg_match('/^\d{5,24}$/D',(string)($b['remote_id']??''))){throw new RuntimeException('没有有效发表意图或微博 ID。');}
                    $id=(string)$b['remote_id'];if($ids&&$ids!==array($id)){throw new RuntimeException('微博 ID 与已存回执不同。');}
                    $w['phase']='receipt';$w['url']='https://weibo.com/detail/'.$id;$progress['weibo_browser']=$w;
                    self::write($job,array('remote_ids'=>wp_json_encode(array($id)),'progress'=>wp_json_encode($progress)));return array('ok'=>true);
                }
                if($op==='result') {
                    $state=(string)($b['state']??'');
                    if($state==='succeeded'){
                        self::valid_job($job);$e=$b['evidence']??array();
                        if(!$ids||$ids!==array((string)($e['id']??''))||($e['account']??'')!==$p['account']||empty($e['title_match'])||empty($e['url_match'])||($e['format']??'')!==$p['weibo_format']||(int)($e['count']??0)!==count(self::media($p,false))){throw new RuntimeException('微博回执未核对通过，不能登记成功。');}
                        $w['phase']='verified';$w['verified_at']=time();$error='';
                    } else {
                        $state=$ids?'external_paused':(!empty($w['publish_started'])?'unknown':'failed');
                        $error=substr(sanitize_text_field((string)($b['message']??'浏览器未完成，请检查登录和队列。')),0,400);
                    }
                    $progress['weibo_browser']=$w;self::write($job,array('state'=>$state,'progress'=>wp_json_encode($progress),'error_text'=>$error,'lease'=>''));return array('ok'=>true,'state'=>$state);
                }
                throw new RuntimeException('操作不存在。');
            }catch(Throwable $e){return new WP_Error('browser_stopped',$e->getMessage(),array('status'=>409));}
        });
        return is_wp_error($value)?$value:new WP_REST_Response($value,200,array('Cache-Control'=>'private, no-store'));
    }
    public static function action($job,$op) {
        if($op==='weibo_browser_migrate'){
            $progress=json_decode($job['progress'],true)?:array();$old=json_decode($job['payload'],true);
            if(json_decode($job['remote_ids'],true)||!in_array($job['state'],array('failed','cancelled','queued','scheduled','manual_ready'),true)||!empty($progress['weibo']['publish_started'])||!empty($progress['weibo_api']['publish_started'])||!empty($progress['weibo_browser']['publish_started'])){throw new RuntimeException('任务可能已发送或已有回执，不能换通道重发。');}
            $s=get_post_meta($job['post_id'],'_crolord_publisher_selection',true);
            if(!is_array($s)||!in_array('weibo',$s['platforms']??array(),true)||($s['weibo_delivery']??'')!=='browser'){throw new RuntimeException('先在原文章选择“浏览器扩展自动发布”并保存。');}
            $s['requested_by']=get_current_user_id();$p=Crolord_Publisher::bind(Crolord_Publisher::for_platform(Crolord_Publisher::snapshot($job['post_id'],$s),'weibo'),'weibo');$p['not_before']=max((int)($old['not_before']??0),(int)$p['not_before']);$valid=self::validate($p);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}
            $progress['browser_migration']=array('at'=>time(),'by'=>get_current_user_id(),'old_payload'=>$old,'old_progress'=>$progress);$progress['weibo_browser']=array();
            self::write($job,array('runner'=>'browser','payload'=>wp_json_encode($p),'progress'=>wp_json_encode($progress),'state'=>$p['not_before']>time()?'scheduled':'queued','available'=>max(time(),$p['not_before']),'lease'=>'','error_text'=>''));return true;
        }
        $p=json_decode($job['payload'],true);if(!self::route($p)){return null;} $progress=json_decode($job['progress'],true)?:array();$w=$progress['weibo_browser']??array();$ids=json_decode($job['remote_ids'],true)?:array();
        if($op==='weibo_check'&&$ids&&!in_array($job['state'],array('running','publishing','succeeded'),true)) { self::write($job,array('state'=>'external_pending','available'=>time(),'lease'=>'','error_text'=>''));return true; }
        if($op==='reconcile'&&$job['state']==='unknown'){
            $id=trim(wp_unslash($_POST['receipt']??''));if(!preg_match('/^\d{5,24}$/D',$id)){throw new RuntimeException('请填写真实数字微博 ID。');}
            self::write($job,array('remote_ids'=>wp_json_encode(array($id)),'state'=>'external_pending','available'=>time(),'lease'=>''));return true;
        }
        if($op==='reset_unknown'&&$job['state']==='unknown'&&!$ids&&!empty($_POST['absent'])){
            $w['previous_intents'][]=array('at'=>time(),'by'=>get_current_user_id(),'intent'=>$w['publish_started']??0);unset($w['publish_started']);$progress['weibo_browser']=$w;
            self::write($job,array('state'=>'queued','progress'=>wp_json_encode($progress),'lease'=>'','error_text'=>'','available'=>max(time(),(int)$p['not_before'])));return true;
        }
        if(in_array($op,array('reconcile','reset_unknown'),true)){throw new RuntimeException('此任务不能重新登记或重发。已有 ID 时请检查同一条微博。');}
        if(in_array($op,array('retry','refresh','cancel','reschedule'),true)&&(!empty($w['publish_started'])||$ids)){throw new RuntimeException('已开始发表或已有 ID，只能核对同一条微博。');}
        return null;
    }
    public static function media_permission($r) {
        $auth=self::permission($r);if(is_wp_error($auth)){return $auth;}$job=Crolord_Publisher_Store::get(absint($r['job']));
        try{if(!$job||$job['state']!=='running'||!$job['lease']||!hash_equals($job['lease'],(string)$r->get_header('x-crolord-lease'))){throw new RuntimeException('媒体不可用');}$p=self::valid_job($job);$m=self::media($p);if(!isset($m[absint($r['item'])])){throw new RuntimeException('媒体不存在');}}
        catch(Throwable $e){return new WP_Error('browser_media','媒体已改变或租约失效。',array('status'=>404));}return true;
    }
    public static function serve_media($served,$result,$r,$server) {
        if($served||!preg_match('~^/crolord-publisher/v3/weibo-browser/media/\d+/\d+$~D',$r->get_route())||is_wp_error($result)||$result->get_status()!==200||$result->get_data()!==array('crolord_browser_media'=>true)){return $served;}
        if(is_wp_error(self::media_permission($r))){status_header(404);return true;}$job=Crolord_Publisher_Store::get(absint($r['job']));$m=self::media(json_decode($job['payload'],true),false)[absint($r['item'])];$f=Crolord_Publisher_Media::local($m['id']);
        header('Content-Type: '.$m['mime']);header('Content-Length: '.$m['size']);header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');$h=fopen($f,'rb');if($h){fpassthru($h);fclose($h);}return true;
    }
}
