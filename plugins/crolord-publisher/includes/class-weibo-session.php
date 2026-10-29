<?php
if (!defined('ABSPATH')) { exit; }

/** A deliberately allowlisted, credential-free report for the settings page. */
final class Crolord_Publisher_Weibo_Connection_Error extends RuntimeException {
    public $diagnostic;
    public function __construct($message,$diagnostic) { parent::__construct($message);$this->diagnostic=$diagnostic; }
}

/** First-party web-session adapter. Not an official Weibo Open Platform SDK. */
final class Crolord_Publisher_Weibo_Session {
    const OPTION = 'crolord_publisher_weibo_web';
    const UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/132.0.0.0 Safari/537.36';
    public static function config() {
        return wp_parse_args(get_option(self::OPTION,array()),array('uid'=>'','name'=>'','session'=>'','verified'=>0,'revision'=>0,'image_mb'=>5,'video_mb'=>100,'protocol'=>0,'enabled'=>false,'status'=>'尚未在插件内连接微博'));
    }
    public static function save($c) { if(!update_option(self::OPTION,$c,false)&&get_option(self::OPTION)!==$c){throw new RuntimeException('微博设置保存失败，已停止。');} }
    public static function jar() {
        $raw=Crolord_Publisher_Store::decrypt(self::config()['session']);
        $jar=json_decode($raw,true);
        if (!is_array($jar)||!$jar) { throw new RuntimeException('微博尚未连接或授权无法解密，请在 Publisher 重新扫码登录。'); }
        return $jar;
    }
    public static function permitted($url,$image=false) {
        $p=wp_parse_url($url);
        if (!$p||($p['scheme']??'')!=='https'||!empty($p['user'])||!empty($p['pass'])||!empty($p['fragment'])||(!empty($p['port'])&&(int)$p['port']!==443)) { return false; }
        $hosts=$image?array('login.sina.com.cn','v2.qr.weibo.cn','qr.weibo.cn','passport.weibo.com'):array('weibo.com','www.weibo.com','passport.weibo.com','passport.weibo.cn','login.sina.com.cn','picupload.weibo.com','fileplatform.api.weibo.com');
        return in_array(strtolower($p['host']??''),$hosts,true);
    }
    public static function cookie_header($jar,$url) {
        $p=wp_parse_url($url);$host=strtolower($p['host']);$path=$p['path']??'/';$found=array();
        foreach ($jar as $cookie) {
            $domain=ltrim(strtolower($cookie['domain']??''),'.');
            $cp=$cookie['path']??'/';$path_match=$cp==='/'||$path===$cp||strpos($path,rtrim($cp,'/').'/')===0;
            if (!$domain||(!empty($cookie['host_only'])?$host!==$domain:($host!==$domain&&substr($host,-strlen('.'.$domain))!=='.'.$domain))||!$path_match||(!empty($cookie['expires'])&&(int)$cookie['expires']<time())) { continue; }
            if (!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$cookie['name']??'')||!is_string($cookie['value']??null)||preg_match('/[\x00-\x20\x7f;]/',$cookie['value'])) { continue; }
            // More specific paths precede their root equivalent, independent of
            // response order. Preserve distinct same-name cookies as browsers do.
            $key=$domain.'|'.$cp.'|'.$cookie['name'];$found[$key]=$cookie;
        }
        uasort($found,function($a,$b){return strlen($b['path']??'/')<=>strlen($a['path']??'/');});
        $out=array();foreach($found as $cookie){$out[]=$cookie['name'].'='.$cookie['value'];}return implode('; ',$out);
    }
    public static function csrf($jar) {
        return self::cookie_value($jar,'https://weibo.com/','XSRF-TOKEN');
    }
    public static function cookie_value($jar,$url,$name) {
        foreach(explode('; ',self::cookie_header($jar,$url))as$pair){$parts=explode('=',$pair,2);if(count($parts)===2&&$parts[0]===$name){$value=rawurldecode($parts[1]);return preg_match('/[\x00-\x1f\x7f]/',$value)?'':$value;}}return '';
    }
    public static function collect(&$jar,$response,$url) {
        $host=strtolower(wp_parse_url($url,PHP_URL_HOST));$path=wp_parse_url($url,PHP_URL_PATH)?:'/';$slash=strrpos($path,'/');$default_path=$slash?substr($path,0,$slash):'/';
        // Normalize legacy leading-dot keys so a deletion cannot leave an older
        // duplicate alive. Never widen an existing cookie's domain.
        $normalized=array();foreach($jar as$c){if(is_array($c)&&isset($c['domain'],$c['name'])){$c['domain']=ltrim(strtolower($c['domain']),'.');$normalized[$c['domain'].'|'.($c['path']??'/').'|'.$c['name']]=$c;}}$jar=$normalized;
        $raw=wp_remote_retrieve_header($response,'set-cookie');$records=array();
        if($raw){
            // WP_HTTP_Requests_Response::get_cookies() URL-decodes values. SSO
            // cookies must instead make the return trip byte-for-byte unchanged.
            foreach((array)$raw as$line){
                if(!is_string($line)||strlen($line)>65536||preg_match('/[\r\n\x00]/',$line)){continue;}
                $lines=preg_split('/,(?=\s*[A-Za-z0-9_-]{1,60}=)/',$line);
                foreach(array_slice($lines,0,80)as$header){
                    $parts=explode(';',$header);$pair=explode('=',trim(array_shift($parts)),2);if(count($pair)!==2){continue;}
                    $c=array('name'=>$pair[0],'value'=>$pair[1],'domain'=>$host,'path'=>$default_path,'expires'=>0,'host_only'=>true);$age=null;
                    foreach($parts as$part){$attr=explode('=',trim($part),2);$key=strtolower($attr[0]);$value=trim($attr[1]??'');
                        if($key==='domain'){$c['domain']=ltrim(strtolower($value),'.');$c['host_only']=false;}
                        elseif($key==='path'&&isset($value[0])&&$value[0]==='/'){$c['path']=$value;}
                        elseif($key==='expires'){$date=strtotime($value);if($date!==false){$c['expires']=$date;$c['delete']=$date<=time();}}
                        elseif($key==='max-age'&&preg_match('/^-?[0-9]{1,12}$/D',$value)){$age=(int)$value;}
                    }
                    if($age!==null){$c['expires']=$age>0?time()+min($age,2147483647):1;$c['delete']=$age<=0;}$records[]=$c;
                }
            }
        }else{
            // Compatibility for HTTP transports that do not expose raw headers.
            foreach(wp_remote_retrieve_cookies($response)as$c){$records[]=array('name'=>$c->name,'value'=>$c->value,'domain'=>ltrim(strtolower($c->domain?:$host),'.'),'path'=>$c->path?:$default_path,'expires'=>(int)$c->expires,'host_only'=>isset($c->host_only)?(bool)$c->host_only:empty($c->domain));}
        }
        foreach($records as$c){
            $d=$c['domain'];if(!$d||($host!==$d&&substr($host,-strlen('.'.$d))!=='.'.$d)||!in_array($d,array('weibo.com','www.weibo.com','passport.weibo.com','weibo.cn','passport.weibo.cn','sina.com.cn','login.sina.com.cn'),true)){continue;}
            if(!preg_match('/^[A-Za-z0-9_-]{1,60}$/D',$c['name'])||!is_string($c['value'])||strlen($c['value'])>24000||preg_match('/[\x00-\x20\x7f;,]/',$c['value'])||preg_match('/[\x00-\x20\x7f;]/',$c['path'])){continue;}
            $key=$d.'|'.$c['path'].'|'.$c['name'];
            if(!empty($c['delete'])||($c['expires']>0&&$c['expires']<=time())){unset($jar[$key]);continue;}
            unset($c['delete']);$jar[$key]=$c;
        }
        if(count($jar)>80){throw new RuntimeException('微博返回的会话内容异常，已停止。');}
    }
    public static function json($text) {
        $text=trim(preg_replace('/^\xEF\xBB\xBF/','',(string)$text));
        if(strpos($text,'/**/')===0){$text=trim(substr($text,4));}
        $d=json_decode($text,true,64,JSON_BIGINT_AS_STRING);
        if (!is_array($d)&&preg_match('/^[A-Za-z_$][A-Za-z0-9_.$]*\s*\(\s*(\{.*\})\s*\)\s*;?\s*$/sD',$text,$m)) { $d=json_decode($m[1],true,64,JSON_BIGINT_AS_STRING); }
        if (!is_array($d)) { throw new RuntimeException('微博未返回预期数据，可能需要重新登录或完成人工验证。'); }
        return $d;
    }
    public static function request($url,$method,$body,&$jar,$stage,$type='application/x-www-form-urlencoded',$extra=array(),$raw=false,&$trace=null) {
        if (!self::permitted($url)) { throw new RuntimeException('拒绝非预期的微博接口地址。'); }
        $headers=array('Accept'=>'application/json, text/plain, */*','User-Agent'=>self::UA,'Origin'=>'https://weibo.com','Referer'=>'https://weibo.com/','X-Requested-With'=>'XMLHttpRequest','Cookie'=>self::cookie_header($jar,$url));
        if(wp_parse_url($url,PHP_URL_HOST)==='passport.weibo.com'){
            $headers['Origin']='https://passport.weibo.com';$headers['Referer']=self::signin_url();
            $token=self::cookie_value($jar,$url,'X-CSRF-TOKEN');if($token!==''){$headers['X-CSRF-TOKEN']=$token;}
        }
        if($raw){$headers['Accept']='text/html,application/json;q=0.9,*/*;q=0.8';unset($headers['Origin'],$headers['X-Requested-With']);$headers['Referer']=self::signin_url();}
        if ($method==='POST') { $headers['Content-Type']=$type; }
        if (wp_parse_url($url,PHP_URL_HOST)==='weibo.com'&&self::csrf($jar)) { $headers['X-XSRF-TOKEN']=self::csrf($jar); }
        $headers=array_merge($headers,$extra);
        $args=array('method'=>$method,'headers'=>$headers,'timeout'=>$method==='GET'?12:25,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>2*1024*1024);
        if ($method==='POST') { $args['body']=$body; }
        $response=function_exists('wp_safe_remote_request')?wp_safe_remote_request($url,$args):wp_remote_request($url,$args);
        if (is_wp_error($response)) { if(is_array($trace)){self::trace($trace,$url,0,'network');}throw new RuntimeException($stage.'：网络请求未完成，请检查服务器到微博的连接。'); }
        $http=(int)wp_remote_retrieve_response_code($response);self::collect($jar,$response,$url);
        if(is_array($trace)){
            $text=wp_remote_retrieve_body($response);$parsed=null;try{$parsed=self::json($text);}catch(Throwable $e){}
            $format=is_array($parsed)?'JSON':(preg_match('/^\s*(?:\xEF\xBB\xBF)?\s*</',(string)$text)?'HTML':($text===''?'empty':'other'));
            self::trace($trace,$url,$http,$format,$parsed);
        }
        if ($raw&&$http>=200&&$http<400) { return array('body'=>wp_remote_retrieve_body($response),'location'=>wp_remote_retrieve_header($response,'location'),'http'=>$http); }
        if ($http<200||$http>=300) {
            $reasons=array(401=>'登录已失效，请重新扫码',403=>'平台拒绝访问，可能需要在微博完成验证',413=>'媒体超过平台限制',429=>'请求过于频繁，请稍后重试');
            throw new RuntimeException($stage.' · HTTP '.$http.'：'.($reasons[$http]??'接口请求失败，未自动重试'));
        }
        $text=wp_remote_retrieve_body($response);
        try{return self::json($text);}catch(Throwable $e){
            $content_type=strtolower(trim(explode(';',(string)wp_remote_retrieve_header($response,'content-type'))[0]));
            $format=preg_match('/^\s*(?:\xEF\xBB\xBF)?\s*</',(string)$text)||strpos($content_type,'html')!==false?'HTML 网页':($text===''?'空响应':'非 JSON 数据');
            $host=wp_parse_url($url,PHP_URL_HOST);$path=wp_parse_url($url,PHP_URL_PATH);
            throw new RuntimeException($stage.' · HTTP '.$http.' · '.$host.$path.'：返回'.$format.'，未完成本步骤。请检查平台登录验证或服务器到该登录域名的连接。');
        }
    }
    public static function accepted($d,$stage) {
        $code=$d['errno']??$d['error_code']??$d['retcode']??$d['code']??'';
        if ((isset($d['ok'])&&(int)$d['ok']!==1)||!empty($d['error'])||(!empty($d['errno'])&&(string)$d['errno']!=='100000')||(!empty($d['error_code']))||(isset($d['retcode'])&&!in_array((string)$d['retcode'],array('0','20000000'),true))||(isset($d['code'])&&!in_array((string)$d['code'],array('0','1','100000','20000000'),true))) {
            $safe=preg_match('/^[a-zA-Z0-9_-]{1,20}$/D',(string)$code)?(string)$code:'未知';
            throw new RuntimeException($stage.'：微博拒绝请求（业务码 '.$safe.'）。请检查登录、账号发布权限及平台验证提示。');
        }
        return $d;
    }
    private static function trace(&$rows,$url,$http,$format,$data=null) {
        $path=wp_parse_url($url,PHP_URL_PATH)?:'/';$known=array('/','/ajax/config/get_config','/ajax/config/get','/ajax/setting/getBasicInfo','/ajax/profile/info','/sso/signin','/sso/v2/crossdomain','/sso/crossdomain','/sso/login.php','/sso/login','/sso/crossdomain.php','/wbsso/login','/newlogin','/sso/v2/login','/crossdomain2.php','/ajaxlogin.php');
        $row=wp_parse_url($url,PHP_URL_HOST).(in_array($path,$known,true)?$path:'/[隐藏路径]').' HTTP='.$http.' format='.$format;
        if(is_array($data)){
            $d=is_array($data['data']??null)?$data['data']:$data;$login=$d['login']??$d['islogin']??null;
            $ok=$data['ok']??null;$row.=' ok='.(in_array($ok,array(1,'1',0,'0',-100,'-100'),true)?(string)$ok:($ok===null?'absent':'other'));
            $row.=' login='.(in_array($login,array(true,1,'1'),true)?'yes':(in_array($login,array(false,0,'0'),true)?'no':'absent'));
            $row.=' uid_field='.(self::uid($d['uid']??$d['idstr']??$d['id']??$d['user']['idstr']??$d['user']['id']??'')?'yes':'no');
            $name=$d['screen_name']??$d['user']['screen_name']??null;$row.=' name_field='.($name===null?'absent':(is_string($name)?'string':'other'));
            $code=(string)($data['error_code']??$data['errno']??$data['retcode']??$data['code']??'');
            if(in_array($code,array('0','1','100000','20000000','21301','21314','21327','50114001','50114002'),true)){$row.=' code='.$code;}
        }
        $rows[]=$row;if(count($rows)>24){array_shift($rows);}
    }
    public static function connection_error($message,$jar,$rows=array(),$home=null,$reason='unverified',$context=array()) {
        $lines=array('Publisher '.CROLORD_PUBLISHER_VERSION.' · 微博连接诊断','result='.$reason);
        $cookies=array();foreach(array('SUB','SUBP','WBPSESS','XSRF-TOKEN')as$name){$cookies[]=$name.'='.(self::cookie_value($jar,'https://weibo.com/',$name)!==''?'present':'absent');}$lines[]='desktop cookies: '.implode(' ',$cookies);
        if(is_array($context)){$lines[]='SSO steps='.count($context['visited']??array()).' blocked_target='.(!empty($context['blocked'])?'yes':'no');}
        $lines[]='homepage self_config='.(is_array($home)&&self::uid($home['uid']??'')?'present':'absent');
        foreach(array_slice($rows,-24)as$row){$lines[]=$row;}
        return new Crolord_Publisher_Weibo_Connection_Error($message,implode("\n",$lines));
    }
    /** Parse one static JSON assignment only. Never evaluate response scripts. */
    public static function home_identity($body) {
        $body=substr((string)$body,0,1048576);
        if(!preg_match('~(?:window\s*\.\s*)?\$CONFIG\s*=\s*(\{)~',$body,$match,PREG_OFFSET_CAPTURE)){return null;}
        $start=$match[1][1];$depth=0;$quoted=false;$escape=false;$end=min(strlen($body),$start+262144);
        for($i=$start;$i<$end;$i++){
            $c=$body[$i];if($quoted){if($escape){$escape=false;}elseif($c==='\\'){$escape=true;}elseif($c==='"'){$quoted=false;}continue;}
            if($c==='"'){$quoted=true;}elseif($c==='{'){$depth++;}elseif($c==='}'&&--$depth===0){
                $data=json_decode(substr($body,$start,$i-$start+1),true,64,JSON_BIGINT_AS_STRING);$user=$data['user']??null;
                if(!is_array($user)||!self::uid($user['idstr']??$user['id']??'')){return null;}
                $name=$user['screen_name']??$user['watermark']['nick']??'';
                return array('uid'=>(string)($user['idstr']??$user['id']),'name'=>is_string($name)?sanitize_text_field($name):'');
            }
        }
        return null;
    }
    public static function identity(&$jar,$home=null,$context=array()) {
        $uid='';$trace=$context['trace']??array();$reason='unverified';
        foreach(array('get_config','get') as $endpoint){
            try{
                $d=self::request('https://weibo.com/ajax/config/'.$endpoint,'GET',null,$jar,'核对微博本人会话','application/x-www-form-urlencoded',array(),false,$trace);self::accepted($d,'核对微博本人会话');
                $data=$d['data']??$d;$candidate=$data['uid']??$data['user']['idstr']??$data['user']['id']??'';$logged=$data['login']??$data['islogin']??false;
                if(in_array($logged,array(true,1,'1'),true)&&self::uid($candidate)){$uid=(string)$candidate;break;}
            }catch(Throwable $e){/* Only fixed read probes may fall back; diagnostics retain their outcome. */}
        }
        if($uid===''){
            try{
                if($home===null){$r=self::request('https://weibo.com/','GET',null,$jar,'读取微博首页身份','application/x-www-form-urlencoded',array(),true,$trace);$home=$r['http']===200?self::home_identity($r['body']):null;}
                if(is_array($home)&&self::uid($home['uid']??'')){
                    // This endpoint has no target UID: it must identify the
                    // authenticated self, unlike public /profile/info?uid=… .
                    $d=self::request('https://weibo.com/ajax/setting/getBasicInfo','GET',null,$jar,'交叉核验微博本人资料','application/x-www-form-urlencoded',array(),false,$trace);self::accepted($d,'交叉核验微博本人资料');$self=$d['data']??null;
                    if(in_array($d['ok']??null,array(1,'1'),true)&&is_array($self)){
                        $self_id=$self['uid']??$self['idstr']??$self['id']??$self['user']['idstr']??$self['user']['id']??'';$name=$self['screen_name']??$self['user']['screen_name']??'';
                        if(self::uid($self_id)){
                            if((string)$self_id!==$home['uid']){$reason='self_uid_conflict';}else{$uid=$home['uid'];}
                        }elseif(is_string($name)&&$name!==''&&($home['name']??'')!==''&&hash_equals($home['name'],sanitize_text_field($name))){$uid=$home['uid'];}
                        else{$reason='self_fields_unrecognized';}
                    }
                }else{$reason='homepage_self_missing';}
            }catch(Throwable $e){$reason='self_probe_failed';}
        }
        if($uid===''){
            $message=self::cookie_value($jar,'https://weibo.com/','SUB')===''?'微博未建立桌面登录 Cookie，请重新扫码；可复制下方诊断检查票据交换结果。':'微博本人会话尚未通过核验，未保存为已登录。请查看下方各步骤结果；Cookie 存在不代表已登录。';
            throw self::connection_error($message,$jar,$trace,$home,$reason,$context);
        }
        if(is_array($home)&&self::uid($home['uid']??'')&&$home['uid']!==$uid){throw self::connection_error('微博首页与本人接口返回不同账号，已停止保存连接。',$jar,$trace,$home,'self_uid_conflict',$context);}
        try{
            $info=self::request('https://weibo.com/ajax/profile/info?uid='.rawurlencode($uid),'GET',null,$jar,'读取微博资料','application/x-www-form-urlencoded',array(),false,$trace);self::accepted($info,'读取微博资料');$user=$info['data']['user']??array();
            if(!self::uid($user['idstr']??$user['id']??'')||(string)($user['idstr']??$user['id'])!==$uid){throw new RuntimeException('微博资料与登录账号不一致，已停止。');}
        }catch(Throwable $e){throw self::connection_error('微博本人资料复核未通过，未保存连接。',$jar,$trace,$home,'profile_unverified',$context);}
        return array('uid'=>$uid,'name'=>sanitize_text_field($user['screen_name']??'微博账号'));
    }
    public static function uid($id) { return (is_string($id)||is_int($id))&&preg_match('/^[1-9][0-9]{0,23}$/D',(string)$id); }
    public static function connection_generation($c=null) { $c=$c??self::config();return hash('sha256',$c['session'].'|'.(int)($c['revision']??0)); }
    public static function connected($jar,$identity,$generation=null,$qr=null,$native=false) {
        $result=Crolord_Publisher_Store::locked(function()use($jar,$identity,$generation,$qr,$native){
            $c=self::config();if($generation!==null&&!hash_equals($generation,self::connection_generation($c))){throw new RuntimeException('微博授权已改变，未覆盖新的连接设置。');}
            if($qr!==null){$state=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($qr['key'])),true);if(!is_array($state)||!hash_equals($state['handle'],$qr['handle'])||$state['expires']<=time()||(isset($qr['lease'])&&($state['lease']??'')!==$qr['lease'])){throw new RuntimeException('登录二维码已改变或已过期，未保存旧扫码结果。');}}
            $c['uid']=$identity['uid'];$c['name']=$identity['name'];$c['session']=Crolord_Publisher_Store::encrypt(wp_json_encode($jar));$c['verified']=time();$c['revision']=(int)$c['revision']+1;if($native){$c['protocol']=2;$c['enabled']=false;$c['status']='本人 UID 已核验；核对账号后启用插件内自动发布';}self::save($c);if($qr!==null){delete_transient($qr['key']);}return true;
        });if(is_wp_error($result)){throw new RuntimeException('账号设置正在更新，请稍后重新连接。');}
    }
    public static function refresh($jar,$uid,$generation=null) {
        $result=Crolord_Publisher_Store::locked(function()use($jar,$uid,$generation){$c=self::config();if((string)$c['uid']!==(string)$uid||!$c['session']){return false;}if($generation!==null&&!hash_equals($generation,hash('sha256',$c['session']))){return false;}$c['session']=Crolord_Publisher_Store::encrypt(wp_json_encode($jar));self::save($c);return true;});
        if(is_wp_error($result)){throw new RuntimeException('微博授权正在更新，请稍后再试。');}
    }
    public static function signin_url() {
        return add_query_arg(array('entry'=>'miniblog','source'=>'miniblog','disp'=>'popup','url'=>'https://weibo.com/'),'https://passport.weibo.com/sso/signin');
    }
    public static function login_url($url) {
        if(!is_string($url)||strlen($url)>16384||!self::permitted($url)||preg_match('/[\x00-\x20\x7f\\\\]/',$url)){return false;}
        $p=wp_parse_url($url);$host=strtolower($p['host']);$path=$p['path']??'/';
        $allowed=array(
            'passport.weibo.com'=>array('/sso/signin','/sso/v2/login','/sso/login','/sso/crossdomain','/sso/crossdomain.php','/wbsso/login'),
            'weibo.com'=>array('/','/sso/login.php','/ajaxlogin.php','/newlogin'),
            'www.weibo.com'=>array('/','/sso/login.php','/ajaxlogin.php','/newlogin'),
            // Passport v2 QR confirmation can exchange its ticket at this Sina route.
            'login.sina.com.cn'=>array('/sso/login.php','/sso/v2/crossdomain','/sso/crossdomain','/sso/crossdomain.php','/crossdomain2.php')
        );
        if(isset($allowed[$host])&&in_array($path,$allowed[$host],true)){return true;}
        return in_array($host,array('weibo.com','www.weibo.com'),true)&&preg_match('~^/(?:u/)?[1-9][0-9]{0,23}(?:/home)?/?$~D',$path);
    }
    public static function redirect_url($location,$base) {
        if(!is_string($location)||strlen($location)>16384){return '';}$location=trim($location);
        // Decode an outer URL encoding only; never decode the signed query itself.
        if(preg_match('~^https?%3a%2f%2f~i',$location)){$location=rawurldecode($location);}
        if(!$location||preg_match('/[\x00-\x20\x7f\\\\]/',$location)){return '';}
        $location=explode('#',$location,2)[0];if(!$location){return '';}
        if(strpos($location,'//')===0){$location='https:'.$location;}
        if(!preg_match('~^[a-z][a-z0-9+.-]*:~i',$location)){
            $b=wp_parse_url($base);if(!$b||empty($b['host'])){return '';}$origin='https://'.$b['host'];$path=$b['path']??'/';
            if($location[0]==='/'){$location=$origin.$location;}
            elseif($location[0]==='?'){$location=$origin.$path.$location;}
            else{$location=$origin.preg_replace('~/[^/]*$~','/',$path).$location;}
        }
        $p=wp_parse_url($location);
        if(!$p||!in_array(strtolower($p['scheme']??''),array('http','https'),true)||empty($p['host'])||isset($p['user'])||isset($p['pass'])){return '';}
        if(isset($p['port'])&&((strtolower($p['scheme'])==='http'&&(int)$p['port']!==80)||(strtolower($p['scheme'])==='https'&&(int)$p['port']!==443))){return '';}
        $parts=array();foreach(explode('/',$p['path']??'/')as$part){if($part==='..'){array_pop($parts);}elseif($part!=='.'&&$part!==''){$parts[]=$part;}}
        $path='/'.implode('/',$parts);if(substr($p['path']??'',-1)==='/'&&$path!=='/'){$path.='/';}
        // Some SSO responses advertise HTTP. Only its HTTPS equivalent is ever requested.
        return 'https://'.strtolower($p['host']).$path.(isset($p['query'])?'?'.$p['query']:'');
    }
    public static function safe_login_target($url) {
        $p=is_string($url)?wp_parse_url($url):false;if(!$p||empty($p['host'])){return '地址格式无效';}
        $host=strtolower($p['host']);
        if(!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)){return '域名格式无效';}
        if(!in_array($host,array('passport.weibo.com','weibo.com','www.weibo.com','login.sina.com.cn'),true)){return '非允许域名：'.$host;} // Host only; never include an external path or query.
        $known=array('sso','v2','login','login.php','signin','crossdomain','crossdomain.php','crossdomain2.php','wbsso','ajaxlogin.php','newlogin','u','home','visitor','visitor.php');$parts=array();
        foreach(array_slice(explode('/',trim($p['path']??'/','/')),0,6)as$part){if($part!==''){$parts[]=in_array($part,$known,true)?$part:'[已隐藏]';}}
        return $host.'/'.implode('/',$parts); // Deliberately no query, fragment or credentials.
    }
    private static function response_login_location($body) {
        $trim=ltrim(preg_replace('/^\xEF\xBB\xBF/','',$body));if($trim===''){return '';}
        if($trim[0]!=='<'){
            try{$data=self::json($body);}catch(Throwable $e){return '';}
            if(isset($data['retcode'])&&!in_array((string)$data['retcode'],array('0','20000000'),true)){self::accepted($data,'确认微博登录');}
            foreach(array($data['data']['location']??null,$data['data']['url']??null,$data['location']??null)as$url){if(is_string($url)&&$url!==''){return $url;}}
        }
        // Recognize only a declarative refresh tag; never execute response scripts.
        if(preg_match_all('~<meta\b[^>]{0,4096}>~i',$body,$tags)){
            foreach(array_slice($tags[0],0,16)as$tag){
                if(!preg_match('~\bhttp-equiv\s*=\s*(["\x27])\s*refresh\s*\1~i',$tag)||!preg_match('~\bcontent\s*=\s*(["\x27])(.*?)\1~is',$tag,$m)){continue;}
                $value=html_entity_decode($m[2],ENT_QUOTES,'UTF-8');if(preg_match('~^\s*[0-9]+(?:\.[0-9]+)?\s*;\s*url\s*=\s*(.*?)\s*$~i',$value,$m)){return trim($m[1]," \t\r\n\"'");}
            }
        }
        return '';
    }
    public static function follow_login($url,&$jar,$stage,$session_landing=false) {
        $url=self::redirect_url($url,'https://passport.weibo.com/');$visited=array();
        for($i=0;$i<=5;$i++){
            if(!self::login_url($url)){
                // A landing page is not needed to prove identity. Never request it.
                // Only QR completion may try already collected desktop cookies at fixed identity endpoints.
                if($session_landing&&$visited&&$url!==''&&!self::permitted($url)&&self::cookie_value($jar,'https://weibo.com/','SUB')!==''){
                    return array('body'=>'','location'=>'','http'=>0,'blocked'=>self::safe_login_target($url));
                }
                $previous=$visited?self::safe_login_target(array_keys($visited)[count($visited)-1]):'扫码返回地址';
                throw new RuntimeException($stage.'：登录跳转不属于允许的微博登录地址（'.self::safe_login_target($url).'；来自 '.$previous.'；第 '.($i+1).' 跳），已停止。');
            }
            if($i===5){break;}
            if(isset($visited[$url])){throw new RuntimeException($stage.'：登录跳转出现循环（'.self::safe_login_target($url).'），已停止。');}$visited[$url]=true;
            $r=self::request($url,'GET',null,$jar,$stage,'application/x-www-form-urlencoded',array(),true);
            if($r['http']>=300){$location=$r['location'];}
            else{$location=self::response_login_location($r['body']);if($location===''){return $r;}}
            $next=self::redirect_url($location,$url);
            if(!$next){throw new RuntimeException($stage.'：微博未返回有效的 HTTPS 登录跳转（'.self::safe_login_target($location).'）。');}
            $url=$next;
        }
        throw new RuntimeException($stage.'：登录跳转次数过多，已停止。');
    }
    public static function qr_image($url,&$jar) {
        if(strpos($url,'//')===0){$url='https:'.$url;}elseif(strpos($url,'http://')===0){$url='https://'.substr($url,7);}
        for($i=0;$i<3;$i++){
            if(!self::permitted($url,true)){throw new RuntimeException('二维码图片地址不属于预期的微博登录域名。');}
            $r=wp_remote_get($url,array('timeout'=>15,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>512*1024,'headers'=>array('User-Agent'=>self::UA,'Referer'=>self::signin_url(),'Cookie'=>self::cookie_header($jar,$url))));
            if(is_wp_error($r)){throw new RuntimeException('读取微博二维码：网络请求未完成。');}
            $http=(int)wp_remote_retrieve_response_code($r);
            if(in_array($http,array(301,302,303,307,308),true)){$url=self::redirect_url(wp_remote_retrieve_header($r,'location'),$url);continue;}
            $bytes=wp_remote_retrieve_body($r);$info=$bytes?@getimagesizefromstring($bytes):false;
            if($http!==200||!$info||!in_array($info['mime'],array('image/png','image/jpeg','image/gif'),true)){throw new RuntimeException('读取微博二维码 · HTTP '.$http.'：未返回有效图片，可能需要在微博完成验证。');}
            return 'data:'.$info['mime'].';base64,'.base64_encode($bytes);
        }
        throw new RuntimeException('读取微博二维码：跳转次数过多，已停止。');
    }
    private static function retain_new_qr($key,$state) {
        $result=Crolord_Publisher_Store::locked(function()use($key,$state){if(!hash_equals($state['generation'],self::connection_generation())){throw new RuntimeException('微博授权已改变，请重新生成二维码。');}set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($state)),180);return true;});
        if(is_wp_error($result)){throw new RuntimeException('登录状态正在更新，请稍后重试。');}
    }
    private static function retain_qr($key,$state) {
        $result=Crolord_Publisher_Store::locked(function()use($key,$state){
            $current=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);
            if(!is_array($current)||!hash_equals($current['handle'],$state['handle'])||$state['expires']<=time()){throw new RuntimeException('二维码已过期或已被替换，请重新生成。');}
            if(!hash_equals($state['generation'],self::connection_generation())){throw new RuntimeException('微博授权已改变，请重新生成二维码。');}
            set_transient($key,Crolord_Publisher_Store::encrypt(wp_json_encode($state)),max(1,$state['expires']-time()));return true;
        });if(is_wp_error($result)){throw new RuntimeException('登录状态正在更新，请稍后重试。');}
    }
    public static function qr_start() {
        $key='cp_weibo_qr_'.get_current_user_id();$handle=bin2hex(random_bytes(16));$state=array('handle'=>$handle,'provider'=>'passport-v2','qrid'=>'','jar'=>array(),'expires'=>time()+180,'generation'=>self::connection_generation());
        self::retain_new_qr($key,$state);
        $jar=array();self::follow_login(self::signin_url(),$jar,'初始化微博登录');
        $endpoint='https://passport.weibo.com/sso/v2/qrcode/image';
        if(self::cookie_value($jar,$endpoint,'X-CSRF-TOKEN')===''){throw new RuntimeException('初始化微博登录：Passport 未提供 X-CSRF-TOKEN 会话。请检查服务器到 passport.weibo.com 的连接或平台验证；没有请求旧版扫码接口。');}
        $d=self::request(add_query_arg(array('entry'=>'miniblog','size'=>180),$endpoint),'GET',null,$jar,'生成微博登录二维码');
        if ((int)($d['retcode']??0)!==20000000||empty($d['data']['qrid'])||empty($d['data']['image'])) { throw new RuntimeException('微博未提供有效二维码，请稍后重试。'); }
        if(!is_string($d['data']['qrid'])||strlen($d['data']['qrid'])>1024||!is_string($d['data']['image'])){throw new RuntimeException('微博二维码返回字段无效。');}
        $image=self::qr_image($d['data']['image'],$jar);
        $state['qrid']=$d['data']['qrid'];$state['jar']=$jar;self::retain_qr($key,$state);
        return array('handle'=>$handle,'image'=>$image,'expires'=>180,'message'=>'使用微博 App 扫码并确认登录；二维码三分钟有效。');
    }
    public static function qr_check($handle) {
        $key='cp_weibo_qr_'.get_current_user_id();$state=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);
        if(!is_array($state)||!hash_equals($state['handle'],(string)$handle)||$state['expires']<time()){throw new RuntimeException('二维码已过期或已被替换，请重新生成。');}
        if(($state['provider']??'')!=='passport-v2'){delete_transient($key);throw new RuntimeException('登录流程已更新，请重新生成微博二维码。');}
        if(!hash_equals($state['generation'],self::connection_generation())){delete_transient($key);throw new RuntimeException('微博授权已改变，请重新生成二维码。');}
        $jar=$state['jar'];$d=self::request(add_query_arg(array('entry'=>'miniblog','source'=>'miniblog','url'=>'https://weibo.com/','qrid'=>$state['qrid'],'rid'=>'','ver'=>'20250520'),'https://passport.weibo.com/sso/v2/qrcode/check'),'GET',null,$jar,'检查扫码状态');$state['jar']=$jar;self::retain_qr($key,$state);
        $code=(int)($d['retcode']??0);
        if(in_array($code,array(50114001,50114002),true)){return array('status'=>'waiting','message'=>$code===50114002?'已扫码，请在微博 App 确认。':'等待微博 App 扫码。');}
        if($code!==20000000){delete_transient($key);throw new RuntimeException('微博未完成登录（状态码 '.$code.'），请重新扫码或在微博 App 完成验证。');}
        if(!empty($d['data']['url'])){
            $login=self::follow_login($d['data']['url'],$jar,'建立微博会话',true);
        }elseif(!empty($d['data']['alt'])&&is_string($d['data']['alt'])){
            $url=add_query_arg(array('entry'=>'miniblog','alt'=>$d['data']['alt'],'returntype'=>'META'),'https://passport.weibo.com/sso/v2/login');
            $login=self::follow_login($url,$jar,'确认微博登录',true);
        }else{
            throw new RuntimeException('微博确认扫码，但没有提供登录跳转地址，尚未保存连接。');
        }
        if(empty($login['blocked'])){$login=self::follow_login('https://weibo.com/',$jar,'初始化微博会话',true);}
        try{$identity=self::identity($jar);}catch(Throwable $e){
            if(!empty($login['blocked'])){throw new RuntimeException('建立微博会话：已停止访问落地页（'.$login['blocked'].'），已有 Cookie 未通过账号核验；'.$e->getMessage());}
            throw $e;
        }
        $current=json_decode(Crolord_Publisher_Store::decrypt((string)get_transient($key)),true);
        if(!is_array($current)||!hash_equals($current['handle'],$state['handle'])){throw new RuntimeException('登录二维码已改变，未保存旧扫码结果。');}
        self::connected($jar,$identity,$state['generation'],array('key'=>$key,'handle'=>$state['handle']));
        return array('status'=>'connected','uid'=>$identity['uid'],'name'=>$identity['name'],'message'=>'微博已连接。此处核对身份，图片、视频与发表权限需实际任务验证。');
    }
    public static function import_cookie($value,$generation=null) {
        if(!is_string($value)||strlen($value)>24000||preg_match('/[\r\n\x00]/',$value)){throw new RuntimeException('Cookie 格式无效。');}
        $jar=array();$allowed=array('SUB','SUBP','ALF','SCF','SSOLoginState','XSRF-TOKEN','WBPSESS','wb_view');
        foreach(explode(';',$value)as$part){$pair=explode('=',trim($part),2);if(count($pair)!==2||!in_array($pair[0],$allowed,true)){continue;}$jar['.weibo.com|/|'.$pair[0]]=array('name'=>$pair[0],'value'=>$pair[1],'domain'=>'.weibo.com','path'=>'/','expires'=>0);}
        if(empty($jar['.weibo.com|/|SUB']['value'])){throw new RuntimeException('没有有效 SUB 会话，请使用本人已登录微博的 Cookie。');}
        self::request('https://weibo.com/','GET',null,$jar,'初始化微博会话','application/x-www-form-urlencoded',array(),true);$id=self::identity($jar);self::connected($jar,$id,$generation);return $id;
    }
}
