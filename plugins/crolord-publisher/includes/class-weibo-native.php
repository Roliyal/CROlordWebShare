<?php
if (!defined('ABSPATH')) { exit; }

/** Pure WordPress/PHP web-session publisher. No browser executor or shell. */
final class Crolord_Publisher_Weibo_Native {
    const PROTOCOL = 2;
    public static function retire_browser_jobs(){
        global $wpdb;$table=Crolord_Publisher_Store::table();$rows=$wpdb->get_results("SELECT id,state,lease,remote_ids,progress FROM $table WHERE runner='browser' AND state IN ('running','publishing') ORDER BY id LIMIT 50",ARRAY_A);
        foreach($rows as$row){$p=json_decode($row['progress'],true)?:array();$ids=json_decode($row['remote_ids'],true)?:array();$state=$ids?'external_paused':($row['state']==='publishing'||!empty($p['weibo_browser']['publish_started'])?'unknown':'failed');$wpdb->update($table,array('state'=>$state,'lease'=>'','touched'=>time(),'error_text'=>'旧浏览器通道已停用；原 ID 和发送意图保留。可能已发出的任务需核对，确定未发出的任务可显式迁移到插件内通道。'),array('id'=>$row['id'],'state'=>$row['state'],'lease'=>$row['lease'],'remote_ids'=>$row['remote_ids']));}
    }
    public static function route($p){return ($p['weibo_route']??'')==='php';}
    public static function binding($c=null){$c=$c??Crolord_Publisher_Weibo_Session::config();return hash('sha256',$c['uid'].'|'.$c['revision'].'|php2');}
    public static function validate($p,$reading=false){
        $c=Crolord_Publisher_Weibo_Session::config();
        if((!$c['enabled']&&!$reading)||(int)$c['protocol']!==self::PROTOCOL){return new WP_Error('weibo_php_setup','请在 Publisher 中扫码连接、核对 UID 并启用插件内自动发布。');}
        if(!hash_equals(self::binding($c),(string)($p['php_binding']??''))){return new WP_Error('weibo_php_binding','微博连接已更换，请先核对未发出的任务，再显式采用当前设置。');}
        return Crolord_Publisher_Weibo::validate($p);
    }
    public static function login_url($url){
        if(!is_string($url)||strlen($url)>16384||preg_match('/[\x00-\x20\x7f\\\\]/',$url)||!Crolord_Publisher_Weibo_Session::permitted($url)){return false;}
        $p=wp_parse_url($url);$host=strtolower($p['host']);$path=$p['path']??'/';
        $routes=array('passport.weibo.com'=>array('/sso/signin','/sso/v2/login','/sso/login','/sso/crossdomain','/sso/crossdomain.php','/wbsso/login'),'passport.weibo.cn'=>array('/sso/crossdomain','/sso/login','/sso/crossdomain.php'),'login.sina.com.cn'=>array('/sso/login.php','/sso/v2/crossdomain','/sso/crossdomain','/sso/crossdomain.php','/crossdomain2.php'),'weibo.com'=>array('/','/sso/login.php','/ajaxlogin.php','/newlogin'),'www.weibo.com'=>array('/','/sso/login.php','/ajaxlogin.php','/newlogin'));
        return (isset($routes[$host])&&in_array($path,$routes[$host],true))||(in_array($host,array('weibo.com','www.weibo.com'),true)&&preg_match('~^/(?:u/)?[1-9][0-9]{0,23}(?:/home)?/?$~D',$path));
    }
    public static function links($body){
        $body=substr((string)$body,0,131072);$out=array();$d=null;
        try{$d=Crolord_Publisher_Weibo_Session::json($body);}catch(Throwable $e){
            // Parse only JSON arguments to one known SSO callback, never evaluate JS.
            if(preg_match('~sinaSSOController\.setCrossDomainUrlList\(\s*(\{.{1,32768}?\})\s*\)~s',$body,$m)){$d=json_decode($m[1],true,32,JSON_BIGINT_AS_STRING);}
        }
        if(is_array($d)){
            Crolord_Publisher_Weibo_Session::accepted($d,'交换微博登录票据');
            foreach(array($d,$d['data']??array())as$part){if(!is_array($part)){continue;}foreach(array('location','url')as$key){if(is_string($part[$key]??null)){$out[]=$part[$key];}}foreach(array('arrURL','crossDomainUrlList','crossdomainlist')as$key){if(is_array($part[$key]??null)){foreach(array_slice($part[$key],0,8)as$url){if(is_string($url)){$out[]=$url;}}}}}
        }
        if(!$out){
            if(preg_match('~(?:window\.)?location\.(?:replace|assign)\(\s*("(?:[^"\\\\]|\\\\.)*")\s*\)~s',$body,$m)||preg_match('~(?:window\.)?location\.href\s*=\s*("(?:[^"\\\\]|\\\\.)*")~s',$body,$m)){$url=json_decode($m[1],true);if(is_string($url)){$out[]=$url;}}
            elseif(preg_match("~(?:window\\.)?location\\.(?:replace|assign)\\(\\s*'([^'\\\\\\x00-\\x20]{1,16384})'\\s*\\)~",$body,$m)){$out[]=$m[1];}
            elseif(preg_match("~(?:window\\.)?location\\.href\\s*=\\s*'([^'\\\\\\x00-\\x20]{1,16384})'~",$body,$m)){$out[]=$m[1];}
        }
        if(!$out&&preg_match_all('~<meta\b[^>]{0,4096}>~i',$body,$tags)){
            foreach(array_slice($tags[0],0,16)as$tag){if(preg_match('~\bhttp-equiv\s*=\s*(["\x27])\s*refresh\s*\1~i',$tag)&&preg_match('~\bcontent\s*=\s*(["\x27])(.*?)\1~is',$tag,$m)){$value=html_entity_decode($m[2],ENT_QUOTES,'UTF-8');if(preg_match('~^\s*[0-9]+(?:\.[0-9]+)?\s*;\s*url\s*=\s*(.*?)\s*$~i',$value,$v)){$out[]=trim($v[1]," \t\r\n\"'");}}}
        }
        return array_values(array_unique(array_slice($out,0,10)));
    }
    /** One SSO HTTP step per admin poll; no long polling PHP request or JS execution. */
    public static function exchange(&$state){
        while($state['queue']){
            $url=$state['queue'][0];if(isset($state['visited'][$url])){array_shift($state['queue']);continue;}
            if(!self::login_url($url)){$state['blocked']=true;array_shift($state['queue']);continue;}
            if(count($state['visited'])>=16){throw new RuntimeException('微博登录票据交换超过安全步数，请重新扫码。');}
            if(!isset($state['trace'])){$state['trace']=array();}
            $stage='交换微博登录票据 · '.wp_parse_url($url,PHP_URL_HOST).(wp_parse_url($url,PHP_URL_PATH)?:'/');$r=Crolord_Publisher_Weibo_Session::request($url,'GET',null,$state['jar'],$stage,'application/x-www-form-urlencoded',array(),true,$state['trace']);
            if(($state['phase']??'')==='bootstrap'&&$url==='https://weibo.com/'&&$r['http']===200){$state['home_identity']=Crolord_Publisher_Weibo_Session::home_identity($r['body'])?:false;}
            array_shift($state['queue']);$state['visited'][$url]=true;$links=$r['http']>=300?array($r['location']):self::links($r['body']);
            foreach($links as$link){$next=Crolord_Publisher_Weibo_Session::redirect_url($link,$url);if($next!==''&&!isset($state['visited'][$next])&&!in_array($next,$state['queue'],true)){$state['queue'][]=$next;}}
            if(count($state['queue'])>24){throw new RuntimeException('微博登录票据列表异常，已停止。');}return false;
        }
        return true;
    }
    public static function qkey(){return 'cp_weibo_php_qr_'.get_current_user_id();}
    private static function retain($key,$s){
        $result=Crolord_Publisher_Store::locked(function()use($key,$s){$old=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);if(!is_array($old)||($old['lease']??'')!==$s['lease']||!hash_equals($old['handle'],$s['handle'])||!hash_equals($s['generation'],Crolord_Publisher_Weibo_Session::connection_generation())){throw new RuntimeException('二维码或微博连接已改变，请刷新设置。');}$s['lease']='';$s['busy_until']=0;set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($s)),max(1,$s['expires']-time()));return true;});if(is_wp_error($result)){throw new RuntimeException('登录状态正在更新，请稍后重试。');}
    }
    public static function qr_start(){
        $key=self::qkey();$generation=Crolord_Publisher_Weibo_Session::connection_generation();$jar=array();
        $initial=array('queue'=>array(Crolord_Publisher_Weibo_Session::signin_url()),'visited'=>array(),'jar'=>array(),'blocked'=>false);for($i=0;$i<4;$i++){if(self::exchange($initial)){break;}}$jar=$initial['jar'];
        $url='https://passport.weibo.com/sso/v2/qrcode/image';if(Crolord_Publisher_Weibo_Session::cookie_value($jar,$url,'X-CSRF-TOKEN')===''){throw new RuntimeException('微博登录入口未提供 CSRF 会话；请检查平台返回与服务器网络，未生成无效登录码。');}
        $d=Crolord_Publisher_Weibo_Session::request(add_query_arg(array('entry'=>'miniblog','size'=>180),$url),'GET',null,$jar,'生成插件内微博二维码');
        if((string)($d['retcode']??'')!=='20000000'||!is_string($d['data']['qrid']??null)||strlen($d['data']['qrid'])>1024||empty($d['data']['qrid'])||!is_string($d['data']['image']??null)){throw new RuntimeException('微博未返回完整的登录二维码。');}
        $image=Crolord_Publisher_Weibo_Session::qr_image($d['data']['image'],$jar);$s=array('handle'=>bin2hex(random_bytes(16)),'generation'=>$generation,'expires'=>time()+180,'qrid'=>$d['data']['qrid'],'jar'=>$jar,'phase'=>'scan','queue'=>array(),'visited'=>array(),'blocked'=>false,'lease'=>'','busy_until'=>0,'next_poll'=>0,'trace'=>$initial['trace']??array());
        $result=Crolord_Publisher_Store::locked(function()use($key,$s){if(!hash_equals($s['generation'],Crolord_Publisher_Weibo_Session::connection_generation())){throw new RuntimeException('微博连接已变化，请重新生成二维码。');}set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($s)),180);return true;});if(is_wp_error($result)){throw new RuntimeException('微博设置正在更新，请稍后重试。');}
        return array('handle'=>$s['handle'],'image'=>$image,'expires'=>180,'status'=>'waiting','message'=>'请用本人微博 App 扫码并确认。');
    }
    public static function qr_check($handle){
        $key=self::qkey();$s=Crolord_Publisher_Store::locked(function()use($key,$handle){$s=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);if(!is_array($s)||!hash_equals($s['handle'],(string)$handle)||$s['expires']<time()||!hash_equals($s['generation'],Crolord_Publisher_Weibo_Session::connection_generation())){throw new RuntimeException('二维码已过期、被替换或账号连接已改变。');}if(($s['busy_until']??0)>time()){throw new RuntimeException('登录票据正在交换，请稍后再检查。');}$s['lease']=bin2hex(random_bytes(12));$s['busy_until']=time()+($s['phase']==='identity'?90:45);set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($s)),max(1,$s['expires']-time()));return $s;});if(is_wp_error($s)){throw new RuntimeException('微博设置正在更新。');}
        try{
            if($s['phase']==='scan'){
                if($s['next_poll']>time()){return array('status'=>'waiting','message'=>'等待扫码确认。');}
                $d=Crolord_Publisher_Weibo_Session::request(add_query_arg(array('entry'=>'miniblog','source'=>'miniblog','url'=>'https://weibo.com/','qrid'=>$s['qrid'],'rid'=>'','ver'=>'20250520'),'https://passport.weibo.com/sso/v2/qrcode/check'),'GET',null,$s['jar'],'检查插件内扫码状态');$s['next_poll']=time()+2;$code=(string)($d['retcode']??'');
                if(in_array($code,array('50114001','50114002'),true)){return array('status'=>'waiting','message'=>$code==='50114002'?'已扫码，请在微博 App 确认。':'等待本人微博 App 扫码。');}
                if($code!=='20000000'){throw new RuntimeException('微博扫码未完成（状态码 '.(ctype_digit($code)?$code:'未知').'），请重新扫码或在微博完成验证。');}
                $url=$d['data']['url']??'';$alt=$d['data']['alt']??'';if(is_string($url)&&$url!==''){$s['queue'][]=Crolord_Publisher_Weibo_Session::redirect_url($url,'https://passport.weibo.com/');}
                if(is_string($alt)&&$alt!==''&&strlen($alt)<4096){$s['queue'][]=add_query_arg(array('entry'=>'miniblog','alt'=>$alt,'returntype'=>'TEXT','crossdomain'=>1,'cdult'=>3,'domain'=>'weibo.com'),'https://login.sina.com.cn/sso/login.php');}
                if(!$s['queue']){throw new RuntimeException('微博确认扫码但未提供可交换票据，未记为已登录。');}$s['phase']='exchange';$s['expires']=time()+600;
                return array('status'=>'finishing','message'=>'扫码已确认，正在交换桌面微博登录票据。');
            }
            if($s['phase']==='exchange'){if(self::exchange($s)){$s['phase']='bootstrap';unset($s['visited']['https://weibo.com/']);$s['queue']=array('https://weibo.com/');}return array('status'=>'finishing','message'=>'正在交换各微博登录域名的票据。');}
            if($s['phase']==='bootstrap'){if(self::exchange($s)){$s['phase']='identity';}return array('status'=>'finishing','message'=>'正在初始化桌面会话并核验本人 UID。');}
            $id=Crolord_Publisher_Weibo_Session::identity($s['jar'],$s['home_identity']??null,array('visited'=>$s['visited'],'blocked'=>$s['blocked'],'trace'=>$s['trace']??array()));Crolord_Publisher_Weibo_Session::connected($s['jar'],$id,$s['generation'],array('key'=>$key,'handle'=>$s['handle'],'lease'=>$s['lease']),true);$s['done']=true;
            return array('status'=>'connected','uid'=>$id['uid'],'name'=>$id['name'],'message'=>'本人 UID 已核验。核对后启用自动发布；上传与发表仍需实际任务验收。');
        }catch(Throwable $e){
            if($e instanceof Crolord_Publisher_Weibo_Connection_Error){throw $e;}
            throw Crolord_Publisher_Weibo_Session::connection_error($e->getMessage(),$s['jar'],$s['trace']??array(),$s['home_identity']??null,'qr_'.$s['phase'],array('visited'=>$s['visited'],'blocked'=>$s['blocked']));
        }finally{if(empty($s['done'])){self::retain($key,$s);}}
    }
    public static function step($job,$p,$progress){$valid=self::validate($p,!empty(json_decode($job['remote_ids'],true)));if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}Crolord_Publisher_Weibo::step($job,$p,$progress);}
    public static function action($job,$op){
        $p=json_decode($job['payload'],true);$progress=json_decode($job['progress'],true)?:array();$ids=json_decode($job['remote_ids'],true)?:array();
        if($op==='weibo_php_migrate'){
            foreach(array('weibo','weibo_api','weibo_browser')as$key){if(!empty($progress[$key]['publish_started'])){throw new RuntimeException('此任务可能已发表，保留发送意图，不换通道重发。');}}
            if(self::route($p)||$ids||!in_array($job['state'],array('manual_ready','failed','cancelled','queued','scheduled'),true)){throw new RuntimeException('只迁移确定未发表、没有平台 ID 的旧任务。');}
            $s=get_post_meta($job['post_id'],'_crolord_publisher_selection',true);if(!is_array($s)||!in_array('weibo',$s['platforms']??array(),true)||($s['weibo_delivery']??'')!=='php'){throw new RuntimeException('请先保存原文章的 Publisher 同步设置，再迁移旧任务。');}$s['requested_by']=get_current_user_id();$fresh=Crolord_Publisher::bind(Crolord_Publisher::for_platform(Crolord_Publisher::snapshot($job['post_id'],$s),'weibo'),'weibo');$fresh['not_before']=max((int)($p['not_before']??0),(int)$fresh['not_before'],(int)$job['available'],(int)($progress['retry_after']??0));$valid=self::validate($fresh);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}
            $audit=array('php_migration'=>array('at'=>time(),'by'=>get_current_user_id(),'previous_payload'=>$p,'previous_progress'=>$progress,'previous_runner'=>$job['runner']??'php'));self::write($job,array('runner'=>'php','payload'=>wp_json_encode($fresh),'progress'=>wp_json_encode($audit),'state'=>$fresh['not_before']>time()?'scheduled':'queued','available'=>max(time(),$fresh['not_before']),'lease'=>'','error_text'=>''));Crolord_Publisher::due($job['id'],$fresh['not_before']);return true;
        }
        if(!self::route($p)){return null;}
        if($op==='weibo_tail_retry'&&$ids&&!empty($progress['weibo']['link_rejected'])&&empty($progress['weibo']['link_comment'])&&!in_array($job['state'],array('running','publishing','succeeded'),true)){
            unset($progress['weibo']['link_started'],$progress['weibo']['link_rejected']);$progress['weibo']['verify_tries']=0;
            self::write($job,array('state'=>'external_pending','available'=>time(),'lease'=>'','error_text'=>'','progress'=>wp_json_encode($progress)));return true;
        }
        if($ids){if($op==='weibo_check'&&!in_array($job['state'],array('running','publishing','succeeded'),true)){self::write($job,array('state'=>'external_pending','available'=>time(),'lease'=>''));return true;}if(in_array($op,array('retry','refresh','reset_unknown','cancel','reschedule'),true)){throw new RuntimeException('已有微博 ID，只能检查同一帖，不再次发表。');}}
        if($op==='reconcile'&&$job['state']==='unknown'&&!$ids){$id=trim(wp_unslash($_POST['receipt']??''));if(!Crolord_Publisher_Weibo_Session::uid($id)){throw new RuntimeException('请输入真实数字微博 ID。');}self::write($job,array('remote_ids'=>wp_json_encode(array($id)),'state'=>'external_pending','available'=>time(),'lease'=>''));return true;}
        if($op==='reset_unknown'&&$job['state']==='unknown'&&!$ids){if(empty($_POST['absent'])){throw new RuntimeException('请先在微博确认这条内容没有发表。');}$progress['weibo']['previous_intents'][]=$progress['weibo']['publish_started']??time();unset($progress['weibo']['publish_started']);$progress['weibo']['absence_confirmed_by']=get_current_user_id();self::write($job,array('state'=>'queued','progress'=>wp_json_encode($progress),'available'=>max(time(),(int)$p['not_before']),'lease'=>''));return true;}
        if(!empty($progress['weibo']['publish_started'])&&in_array($op,array('retry','refresh','cancel','reschedule'),true)){throw new RuntimeException('已有发表意图，请先核对微博，不重置任务重发。');}return null;
    }
    private static function write($job,$values){global $wpdb;$values['touched']=time();if(!$wpdb->update(Crolord_Publisher_Store::table(),$values,array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease'],'remote_ids'=>$job['remote_ids']))){throw new RuntimeException('任务已改变，请刷新队列后检查。');}}
}
