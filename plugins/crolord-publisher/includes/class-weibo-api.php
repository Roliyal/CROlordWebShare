<?php
if (!defined('ABSPATH')) { exit; }

/** Official Weibo platform HTTP protocol; no website Cookie or headless browser. */
final class Crolord_Publisher_Weibo_API_Error extends RuntimeException {
    public $uncertain;
    public function __construct($message,$uncertain=false){parent::__construct($message);$this->uncertain=$uncertain;}
}
final class Crolord_Publisher_Weibo_API {
    const OPTION='crolord_publisher_weibo_api';
    const BASE='https://open.weibo.com/cli/api';
    const CLIENT='weibo-cli'; // Public device client published by the official SDK.
    public static function route($p){return ($p['weibo_route']??'')==='official';}
    public static function uid($v){return (is_string($v)||is_int($v))&&preg_match('/^[1-9][0-9]{0,23}$/D',(string)$v);}
    public static function config(){return wp_parse_args(get_option(self::OPTION,array()),array('uid'=>'','name'=>'','credentials'=>'','revision'=>0,'verified'=>0,'caps'=>array(),'checked'=>0,'image_mb'=>5,'service'=>'','ready'=>false));}
    public static function generation($c=null){$c=$c??self::config();return hash('sha256',$c['uid'].'|'.(int)$c['revision']);}
    public static function save($c){update_option(self::OPTION,$c,false);}
    private static function locked($work){$r=Crolord_Publisher_Store::locked($work);if(is_wp_error($r)){throw new RuntimeException('微博 API 授权正在更新，请稍后重试。');}return $r;}
    public static function http($path,$body=null,$token='',$stage='微博 API',$publish=false){
        $allowed=array('/oauth/device/code','/oauth/token','/cli/whoami','/cli/commands?access=all','/cli/invoke');
        if(!in_array($path,$allowed,true)){throw new RuntimeException('拒绝非预期的微博 API 接口。');}
        if($token!==''&&(!is_string($token)||strlen($token)>16000||preg_match('/[\x00-\x20\x7f]/',$token))){throw new RuntimeException('微博 API 授权格式无效，请重新授权。');}
        $headers=array('Accept'=>'application/json','User-Agent'=>'crolord-publisher/'.CROLORD_PUBLISHER_VERSION,'X-Weibo-Agent-Source'=>'crolord-publisher','X-Weibo-Agent-Host'=>'wordpress','X-Weibo-Source-Method'=>'plugin');
        if($token!==''){$headers['Authorization']='Bearer '.$token;}
        $args=array('method'=>$body===null?'GET':'POST','headers'=>$headers,'timeout'=>25,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>3*1024*1024);
        if($body!==null){$args['headers']['Content-Type']='application/json';$args['body']=wp_json_encode($body);}
        $r=wp_remote_request(self::BASE.$path,$args);
        if(is_wp_error($r)){throw new Crolord_Publisher_Weibo_API_Error($stage.'：连接未完成。'.($publish?'发表结果未知，先核对微博，不会自动重发。':''),$publish);}
        $status=(int)wp_remote_retrieve_response_code($r);$d=json_decode(wp_remote_retrieve_body($r),true,64,JSON_BIGINT_AS_STRING);
        if($status<200||$status>=300||!is_array($d)){
            $raw=is_array($d)?($d['error']['code']??(is_string($d['error']??null)?$d['error']:'')):'';
            $code=is_string($raw)&&preg_match('/^[a-zA-Z0-9_-]{1,64}$/D',$raw)?$raw:'';
            $reasons=array(401=>'授权过期或已撤销，请重新连接',402=>'服务未开通或积分不足，请到微博官方平台检查',403=>'平台拒绝，请检查开发者认证、服务与接口权限',413=>'图片或请求体超过平台限制',429=>'触发平台限流，请稍后重试');
            $explicit_denial=is_array($d)&&isset($d['error'])&&in_array($status,array(400,401,402,403,404,413,422,429),true);
            $uncertain=$publish&&(!$explicit_denial||isset($d['meta']['outcome']));
            throw new Crolord_Publisher_Weibo_API_Error($stage.' · HTTP '.$status.($code?' · '.$code:'').'：'.($reasons[$status]??'未返回有效成功响应').($uncertain?'；发表结果未知，不自动重发。':''),$uncertain);
        }
        return $d;
    }
    private static function token_fields($d,$previous=array()){
        $access=$d['access_token']??'';$refresh=$d['refresh_token']??($previous['refresh_token']??'');$expires=(int)($d['expires_in']??0);
        foreach(array($access,$refresh)as$value){if(!is_string($value)||$value===''||strlen($value)>16000||preg_match('/[\x00-\x20\x7f]/',$value)){throw new RuntimeException('微博官方未返回完整有效授权，尚未保存。');}}
        if($expires<1||$expires>366*DAY_IN_SECONDS){throw new RuntimeException('微博官方返回的授权有效期无效。');}
        return array('access_token'=>$access,'refresh_token'=>$refresh,'expires'=>time()+$expires,'refresh_expires'=>isset($d['refresh_expires_in'])?time()+max(0,(int)$d['refresh_expires_in']):($previous['refresh_expires']??0));
    }
    public static function token(){
        return self::locked(function(){
            $c=self::config();$v=json_decode(Crolord_Publisher_Store::decrypt($c['credentials']),true);
            if(!is_array($v)||empty($v['refresh_token'])){throw new RuntimeException('请先在 Publisher 连接微博官方 API。');}
            if(($v['expires']??0)>time()+60&&!empty($v['access_token'])){return $v['access_token'];}
            if(!empty($v['refresh_expires'])&&$v['refresh_expires']<=time()){throw new RuntimeException('微博官方授权已过期，请重新连接。');}
            $d=self::http('/oauth/token',array('grant_type'=>'refresh_token','refresh_token'=>$v['refresh_token'],'client_id'=>self::CLIENT),'','刷新微博官方授权');
            $next=self::token_fields($d,$v);$c['credentials']=Crolord_Publisher_Store::encrypt(wp_json_encode($next));self::save($c);return $next['access_token'];
        });
    }
    public static function invoke($token,$action,$args=array(),$publish=false){
        $known=array('users/show/biz'=>array('users','show/biz'),'statuses/upload_pic'=>array('statuses','upload_pic'),'statuses/upload_url_text'=>array('statuses','upload_url_text'),'statuses/show_batch/biz'=>array('statuses','show_batch/biz'));
        if(!isset($known[$action])){throw new RuntimeException('该微博 API 动作未接入。');}
        $d=self::http('/cli/invoke',array('group'=>$known[$action][0],'action'=>$known[$action][1],'args'=>(object)$args),$token,'微博 API · '.$action,$publish);
        if(!array_key_exists('result',$d)||!is_array($d['result'])){throw new Crolord_Publisher_Weibo_API_Error('微博 API 未返回可核对结果。'.($publish?'发表结果未知，不自动重发。':''),$publish);}
        $r=$d['result'];
        if(!empty($r['error'])||!empty($r['error_code'])||(isset($r['ok'])&&(int)$r['ok']!==1)){
            throw new Crolord_Publisher_Weibo_API_Error('微博上游接口拒绝或未完成本次操作。'.($publish?'先在微博核对；本次不会自动重发。':''),$publish);
        }
        return $r;
    }
    public static function identity($token){
        $r=self::invoke($token,'users/show/biz');$u=$r['data']??$r;$uid=$u['idstr']??$u['id']??$u['uid']??'';
        if(!self::uid($uid)){throw new RuntimeException('微博官方 API 未返回可核验的本人 UID，尚未保存连接。');}
        return array('uid'=>(string)$uid,'name'=>sanitize_text_field($u['screen_name']??$u['username']??'微博账号'));
    }
    public static function capabilities($token){
        $d=self::http('/cli/commands?access=all',null,$token,'读取微博 API 权限');$found=array();
        $required=array('users/show/biz'=>array(),'statuses/upload_pic'=>array('pic'),'statuses/upload_url_text'=>array('pic_id','status'),'statuses/show_batch/biz'=>array('ids'));
        foreach(($d['commands']??array())as$cmd){
            $key=($cmd['group']??'').'/'.($cmd['action']??'');if(!isset($required[$key])){continue;}
            $flags=array();$unknown_required=false;
            foreach(($cmd['flags']??array())as$f){$name=$f['name']??'';if(!is_string($name)){continue;}$flags[$name]=array('type'=>$f['type']??'','required'=>!empty($f['required']));if(!empty($f['required'])&&!in_array($name,$required[$key],true)){$unknown_required=true;}}
            $matches=!$unknown_required;foreach($required[$key]as$name){if(!isset($flags[$name])||$flags[$name]['type']!=='string'){$matches=false;}}
            $found[$key]=array('allowed'=>($cmd['access']??'')==='allowed'&&$matches,'flags'=>$flags);
        }
        return $found;
    }
    public static function check(){
        $revision=(int)self::config()['revision'];$token=self::token();$id=self::identity($token);$caps=self::capabilities($token);$who=self::http('/cli/whoami',null,$token,'读取微博服务状态');
        return self::locked(function()use($revision,$id,$caps,$who){$c=self::config();if($c['uid']!==$id['uid']){throw new RuntimeException('微博 API UID 与已绑定账号不一致，未覆盖原账号。');}
            // Token refresh may rotate ciphertext; revocation/new login changes revision and UID.
            if((int)$c['revision']!==$revision){throw new RuntimeException('授权已改变，请重新检查。');}
            $c['name']=$id['name'];$c['caps']=$caps;$c['checked']=time();$c['service']=sanitize_text_field($who['service_status']['kind']??'unknown');$c['ready']=self::ready($caps);self::save($c);return $c;});
    }
    public static function ready($caps){foreach(array('users/show/biz','statuses/upload_pic','statuses/upload_url_text','statuses/show_batch/biz')as$key){if(empty($caps[$key]['allowed'])){return false;}}return true;}
    public static function device_start(){
        $key='cp_weibo_api_device_'.get_current_user_id();$handle=bin2hex(random_bytes(16));$generation=self::generation();
        $initial=array('handle'=>$handle,'generation'=>$generation,'expires'=>time()+600,'next'=>time()+5);self::locked(function()use($key,$initial){set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($initial)),600);});
        $d=self::http('/oauth/device/code',array('client_id'=>self::CLIENT,'device_name'=>'crolord Publisher · '.wp_parse_url(home_url('/'),PHP_URL_HOST)),'','发起微博官方授权');
        $url=$d['verification_uri']??'';$parts=is_string($url)?wp_parse_url($url):false;$code=$d['user_code']??'';$device=$d['device_code']??'';
        if(!$parts||($parts['scheme']??'')!=='https'||($parts['host']??'')!=='open.weibo.com'||strpos($parts['path']??'','/cli/')!==0||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['fragment'])||!is_string($code)||!preg_match('/^[A-Z0-9-]{4,32}$/Di',$code)||!is_string($device)||$device===''||strlen($device)>4096){throw new RuntimeException('微博官方返回的设备授权信息无效。');}
        $seconds=min(600,max(1,(int)($d['expires_in']??600)));$interval=min(60,max(5,(int)($d['interval']??5)));
        $state=array('handle'=>$handle,'generation'=>$generation,'device'=>$device,'expires'=>time()+$seconds,'interval'=>$interval,'next'=>time()+$interval);
        self::locked(function()use($key,$state){$now=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);if(!is_array($now)||!hash_equals($now['handle'],$state['handle'])||!hash_equals($state['generation'],self::generation())){throw new RuntimeException('官方授权流程已被替换，请重新连接。');}set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($state)),max(1,$state['expires']-time()));});
        return array('handle'=>$handle,'verification_url'=>add_query_arg(array('user_code'=>$code),$url),'user_code'=>$code,'expires'=>$seconds,'interval'=>$interval,'message'=>'打开微博官方授权页，核对设备名并确认；网站不会读取微博网页 Cookie。');
    }
    public static function device_poll($handle){
        $key='cp_weibo_api_device_'.get_current_user_id();
        $s=self::locked(function()use($key,$handle){$s=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);if(!is_array($s)||!hash_equals($s['handle'],(string)$handle)||$s['expires']<=time()||!hash_equals($s['generation'],self::generation())){throw new RuntimeException('官方授权已过期、被替换或撤销，请重新连接。');}if(empty($s['device'])||$s['next']>time()){return array('waiting'=>true,'interval'=>$s['interval']??5);} $s['next']=time()+$s['interval'];set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($s)),max(1,$s['expires']-time()));return $s;});
        if(!empty($s['waiting'])){return array('status'=>'waiting','interval'=>$s['interval'],'message'=>'等待微博官方授权。');}
        try{$d=self::http('/oauth/token',array('grant_type'=>'urn:ietf:params:oauth:grant-type:device_code','device_code'=>$s['device'],'client_id'=>self::CLIENT),'','检查微博官方授权');}
        catch(Crolord_Publisher_Weibo_API_Error $e){
            if(strpos($e->getMessage(),'authorization_pending')!==false){return array('status'=>'waiting','interval'=>$s['interval'],'message'=>'请在微博官方授权页确认。');}
            if(strpos($e->getMessage(),'slow_down')!==false){$s['interval']=min(60,$s['interval']+5);self::locked(function()use($key,$s){$now=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);if(is_array($now)&&hash_equals($now['handle'],$s['handle'])&&hash_equals($s['generation'],self::generation())){$now['interval']=$s['interval'];$now['next']=time()+$s['interval'];set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($now)),max(1,$s['expires']-time()));}});return array('status'=>'waiting','interval'=>$s['interval'],'message'=>'平台要求降低轮询频率，正在等待。');}
            throw $e;
        }
        $v=self::token_fields($d);$id=self::identity($v['access_token']);$caps=self::capabilities($v['access_token']);$who=self::http('/cli/whoami',null,$v['access_token'],'读取微博服务状态');
        $c=self::locked(function()use($key,$s,$v,$id,$caps,$who){$now=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);if(!is_array($now)||!hash_equals($now['handle'],$s['handle'])||$now['expires']<=time()||!hash_equals($s['generation'],self::generation())){throw new RuntimeException('授权已过期或改变，未保存迟到的结果。');}$c=self::config();$c['uid']=$id['uid'];$c['name']=$id['name'];$c['credentials']=Crolord_Publisher_Store::encrypt(wp_json_encode($v));$c['revision']++;$c['verified']=time();$c['caps']=$caps;$c['checked']=time();$c['service']=sanitize_text_field($who['service_status']['kind']??'unknown');$c['ready']=self::ready($caps);self::save($c);delete_transient($key);return $c;});
        return array('status'=>'connected','uid'=>$c['uid'],'name'=>$c['name'],'message'=>$c['ready']?'官方 API 已连接，图片上传、九图发布与回查接口已开放；实际发表仍需任务验证。':'身份已连接，但多图发布接口尚未全部开放。请在官方平台检查开发者认证与正式服务。');
    }
    public static function disconnect(){return self::locked(function(){$c=self::config();$c['uid']='';$c['name']='';$c['credentials']='';$c['verified']=0;$c['ready']=false;$c['caps']=array();$c['revision']++;self::save($c);delete_transient('cp_weibo_api_device_'.get_current_user_id());return true;});}
    public static function validate($p){
        if(($p['weibo_format']??'images')!=='images'||!empty($p['weibo_video'])){return new WP_Error('weibo_video','当前官方 API 目录未提供视频上传/发布能力，本版不会通过旧网页接口代发视频。');}
        $c=self::config();if(!$c['verified']||!$c['credentials']||!self::uid($c['uid'])||empty($p['account'])){return new WP_Error('weibo_auth','请先连接微博官方 API，并在文章保存官方 API 发布方式。');}
        if((string)$p['account']!==$c['uid']){return new WP_Error('weibo_account','微博官方账号已改变，旧任务不会自动改绑。');}
        if(!$c['ready']||!self::ready($c['caps'])){return new WP_Error('weibo_access','官方多图上传、发表或回查权限未全部开放。请完成开发者认证并检查正式服务；免费体验不包含多图。');}
        if(empty($p['title'])||empty($p['url'])||!preg_match('~^https://~i',$p['url'])){return new WP_Error('weibo_text','需要分享标题和公开文章的 HTTPS 网址。');}
        preg_match_all('/./us',Crolord_Publisher_Platforms::text($p),$chars);if(count($chars[0])>2000){return new WP_Error('weibo_text','微博标题和文章链接合计不能超过 2000 字。');}
        if(empty($p['images'])||count($p['images'])>9){return new WP_Error('weibo_images','微博 API 单帖请选择 1–9 张图片。');}
        return true;
    }
    public static function image($media,$owner){
        if(!user_can($owner,'edit_post',$media['id'])){throw new RuntimeException('发布者的图片使用权限已撤销。');}$file=Crolord_Publisher_Media::local($media['id']);$size=filesize($file);
        if(!$size||$size>=10*1024*1024||$size>(int)self::config()['image_mb']*1024*1024){throw new RuntimeException('微博 API 图片超过设置上限或官方小于 10 MB 的限制。');}
        if(!in_array(wp_get_image_mime($file),array('image/jpeg','image/png'),true)||!hash_equals($media['sha256'],hash_file('sha256',$file))){throw new RuntimeException('排队图片已变化或不是 JPEG/PNG，未上传。');}return $file;
    }
    private static function require_command($caps,$key,$args){
        if(empty($caps[$key]['allowed'])){throw new RuntimeException('微博官方接口尚未开放：'.$key.'。请检查正式服务与积分。');}
        $flags=$caps[$key]['flags']??array();foreach($args as$name=>$value){if(!isset($flags[$name])){throw new RuntimeException('微博官方参数目录已变化，请先检查 API 连接。');}}
        foreach($flags as$name=>$flag){if(!empty($flag['required'])&&!array_key_exists($name,$args)){throw new RuntimeException('微博官方接口新增必填参数，请先检查 API 连接。');}}
    }
    public static function receipt($p,$id,$token){
        if(!self::uid($id)){throw new RuntimeException('微博帖子 ID 无效。');}$c=self::config();$args=array('ids'=>(string)$id);if(isset($c['caps']['statuses/show_batch/biz']['flags']['isGetLongText'])){$args['isGetLongText']=1;}
        self::require_command($c['caps'],'statuses/show_batch/biz',$args);$r=self::invoke($token,'statuses/show_batch/biz',$args);$rows=$r['statuses']??$r['data']['statuses']??$r['data']??$r;$status=null;
        if(isset($rows['idstr'])||isset($rows['id'])){$rows=array($rows);}foreach($rows as$row){if(is_array($row)&&(string)($row['idstr']??$row['id']??'')===(string)$id){$status=$row;break;}}
        if(!$status||(string)($status['user']['idstr']??$status['user']['id']??$status['user']['uid']??'')!==(string)$p['account']){throw new RuntimeException('官方回执未核对到同一帖子与账号，未登记成功。');}
        $text=html_entity_decode(wp_strip_all_tags($status['longText']['longTextContent']??$status['long_text']??$status['text_raw']??$status['text']??''),ENT_QUOTES,'UTF-8');$compact=function($s){return preg_replace('/\s+/u','',$s);};
        if(strpos($compact($text),$compact($p['title']))===false){throw new RuntimeException('官方回执未核对到分享标题。');}$url=strpos($text,$p['url'])!==false;
        foreach(($status['url_struct']??array())as$link){if(($link['long_url']??'')===$p['url']){$url=true;}}if(!$url){throw new RuntimeException('官方回执未核对到文章网址，短链可能尚未展开。');}
        $pics=$status['pic_ids']??array_keys($status['pic_infos']??array());if(!is_array($pics)||count($pics)!==count($p['images'])){throw new RuntimeException('官方回执图片数量与任务不一致。');}
        return 'https://weibo.com/detail/'.(string)$id;
    }
    private static function checkpoint($job,$progress,$state='queued',$error='',$delay=0,$ids=null){$v=array('progress'=>wp_json_encode($progress),'state'=>$state,'error_text'=>$error,'lease'=>'','available'=>time()+$delay);if($ids!==null){$v['remote_ids']=wp_json_encode($ids);}Crolord_Publisher_Store::write($job,$v);if($state==='queued'){Crolord_Publisher::due($job['id'],time());}}
    public static function step($job,$p,$progress){
        $ids=json_decode($job['remote_ids'],true)?:array();$a=$progress['weibo_api']??array();$valid=self::validate($p);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}$token=self::token();$c=self::config();
        if(time()-(int)$c['checked']>DAY_IN_SECONDS){$c=self::check();$valid=self::validate($p);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}}
        if($ids){try{$a['url']=self::receipt($p,$ids[0],$token);$a['phase']='verified';$progress['weibo_api']=$a;self::checkpoint($job,$progress,'succeeded');}catch(Throwable $e){$a['verify_tries']=(int)($a['verify_tries']??0)+1;$progress['weibo_api']=$a;self::checkpoint($job,$progress,$a['verify_tries']>=5?'external_paused':'external_pending',$e->getMessage(),60);}return;}
        if(!empty($a['publish_started'])){self::checkpoint($job,$progress,'unknown','上次发表结果未知，未再次提交。请先核对微博。');return;}
        if(empty($a['identity_revision'])||(int)$a['identity_revision']!==(int)$c['revision']){$id=self::identity($token);if($id['uid']!==$p['account']){throw new RuntimeException('官方 API 当前 UID 与任务绑定账号不一致。');}$a['identity_revision']=(int)$c['revision'];}
        $pictures=$a['pictures']??array();$index=count($pictures);
        if($index<count($p['images'])){
            $file=self::image($p['images'][$index],$p['requested_by']);$limit=wp_convert_hr_to_bytes(ini_get('memory_limit'));
            if($limit>0&&memory_get_usage(true)+filesize($file)*7+16*1024*1024>$limit){throw new RuntimeException('PHP 剩余内存不足以编码此原图，请压缩图片或提高内存上限。');}
            self::require_command($c['caps'],'statuses/upload_pic',array('pic'=>''));$pic='data:'.wp_get_image_mime($file).';base64,'.base64_encode(file_get_contents($file));$r=self::invoke($token,'statuses/upload_pic',array('pic'=>$pic));unset($pic);$r=$r['data']??$r;$pid=$r['pic_id']??$r['pid']??$r['pic']['pid']??'';
            if(!is_string($pid)||!preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$pid)){throw new RuntimeException('官方图片上传未返回有效图片 ID，未发表。');}$pictures[]=$pid;$a['pictures']=$pictures;$a['phase']='images_'.count($pictures);$progress['weibo_api']=$a;self::checkpoint($job,$progress);return;
        }
        $article=get_post($job['post_id']);if(!$article||$article->post_status!=='publish'||$article->post_password||!user_can($p['requested_by'],'edit_post',$job['post_id'])||!user_can($p['requested_by'],'crolord_publish_social')){throw new RuntimeException('文章状态或发布权限已变化，未发表。');}foreach($p['images']as$image){self::image($image,$p['requested_by']);}
        $args=array('pic_id'=>implode(',',$pictures),'status'=>Crolord_Publisher_Platforms::text($p));$flags=$c['caps']['statuses/upload_url_text']['flags'];if(isset($flags['visible'])){$args['visible']=0;}preg_match_all('/./us',$args['status'],$chars);if(count($chars[0])>140){if(!isset($flags['is_longtext'])){throw new RuntimeException('当前官方接口没有开放长文参数，请缩短分享标题。');}$args['is_longtext']=1;}
        if(isset($flags['mblog_statement'])){$args['mblog_statement']=(int)($p['weibo_statement']??0);}self::require_command($c['caps'],'statuses/upload_url_text',$args);
        $latest=self::config();if((int)$latest['revision']!==(int)$c['revision']||$latest['uid']!==$p['account']||!$latest['credentials']){throw new RuntimeException('微博官方授权在发表前已改变，未提交。');}
        $a['phase']='publishing';$a['publish_started']=time();$progress['weibo_api']=$a;Crolord_Publisher_Store::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));
        try{$r=self::invoke($token,'statuses/upload_url_text',$args,true);}catch(Crolord_Publisher_Weibo_API_Error $e){if(!$e->uncertain){unset($a['publish_started']);$progress['weibo_api']=$a;}self::checkpoint($job,$progress,$e->uncertain?'unknown':'failed',$e->getMessage());return;}
        $r=$r['data']??$r;$id=$r['idstr']??$r['id']??'';if(!self::uid($id)){self::checkpoint($job,$progress,'unknown','官方发表未返回可核对的帖子 ID，不自动重发。');return;}
        $a['phase']='receipt';$progress['weibo_api']=$a;self::checkpoint($job,$progress,'external_pending','',10,array((string)$id));
    }
    private static function action_write($job,$progress,$values=array()){
        global $wpdb;$v=array_merge(array('state'=>'external_pending','progress'=>wp_json_encode($progress),'error_text'=>'','lease'=>'','available'=>time(),'touched'=>time()),$values);$changed=$wpdb->update(Crolord_Publisher_Store::table(),$v,array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease'],'remote_ids'=>$job['remote_ids']));if(!$changed){throw new RuntimeException('任务状态已改变，未重复提交。');}
    }
    public static function action($job,$op){
        $p=json_decode($job['payload'],true);$progress=json_decode($job['progress'],true)?:array();$ids=json_decode($job['remote_ids'],true)?:array();
        if($op==='weibo_api_migrate'){
            if(self::route($p)||$ids||!in_array($job['state'],array('queued','scheduled','failed','cancelled','manual_ready','external_paused'),true)||!empty($progress['weibo']['publish_started'])||!empty($progress['weibo_api']['publish_started'])){throw new RuntimeException('只能迁移尚未发表、没有平台 ID 且结果明确的旧任务。');}
            $s=get_post_meta($job['post_id'],'_crolord_publisher_selection',true);if(!is_array($s)||!in_array('weibo',$s['platforms']??array(),true)||($s['weibo_delivery']??'')!=='official'){throw new RuntimeException('先在文章保存微博“官方 API”发布方式，再迁移旧任务。');}
            $s['requested_by']=get_current_user_id();$prepared=Crolord_Publisher::bind(Crolord_Publisher::for_platform(Crolord_Publisher::snapshot($job['post_id'],$s),'weibo'),'weibo');$v=self::validate($prepared);if(is_wp_error($v)){throw new RuntimeException($v->get_error_message());}
            $prepared['not_before']=max((int)$prepared['not_before'],(int)($p['not_before']??0));$when=max(time(),$prepared['not_before']);$audit=array('weibo_api_migration'=>array('by'=>get_current_user_id(),'at'=>time(),'previous_payload'=>$p,'previous_progress'=>$progress,'previous_state'=>$job['state']));self::action_write($job,$audit,array('payload'=>wp_json_encode($prepared),'state'=>$when>time()?'scheduled':'queued','available'=>$when));Crolord_Publisher::due($job['id'],$when);return 1;
        }
        if(!self::route($p)){return $op==='cancel'&&!$ids?null:new WP_Error('weibo_retired','旧微博网页通道已停用；没有发表结果的任务可显式迁移到官方 API。');}
        if($op==='weibo_check'){if(!$ids||in_array($job['state'],array('running','publishing'),true)){throw new RuntimeException('没有可核对的帖子 ID，或任务正在执行。');}self::action_write($job,$progress);return 1;}
        if($ids&&in_array($op,array('retry','refresh','reset_unknown','cancel','reschedule'),true)){return new WP_Error('weibo_received','已有微博帖子 ID，只能核对同一帖，不能再次发表。');}
        if($op==='reconcile'&&$job['state']==='unknown'&&!$ids){$id=trim((string)wp_unslash($_POST['receipt']??''));$progress['weibo_api']['url']=self::receipt($p,$id,self::token());$progress['weibo_api']['phase']='verified';self::action_write($job,$progress,array('state'=>'succeeded','remote_ids'=>wp_json_encode(array($id))));return 1;}
        if($op==='reset_unknown'&&$job['state']==='unknown'&&!$ids&&!empty($_POST['absent'])){unset($progress['weibo_api']['publish_started']);$progress['weibo_api']['absence_confirmed_by']=get_current_user_id();$progress['weibo_api']['absence_confirmed_at']=time();self::action_write($job,$progress,array('state'=>'queued'));return 1;}
        return null;
    }
}
