<?php
/** Server-owned official managed login. Browser and shared visitors never receive tokens. */
if (!defined('ABSPATH')) exit;
final class Crolord_Echoes_Account {
    const CACHE='crolord_echoes_account_state';
    private static $local=null;
    public static function request($operation,$payload=array(),$timeout=8) {
        if(!in_array($operation,array('status','health','upgrade','upload_begin','upload_chunk','upload_finish','login','logout','chat','start','poll','cancel','approve','upload','remove','reset','tools','discover','artifact_info','artifact_chunk','publisher_clock'),true))return new WP_Error('account_operation','账号操作无效。');
        $path=defined('CROLORD_ECHOES_BRIDGE_KEY_FILE')?CROLORD_ECHOES_BRIDGE_KEY_FILE:'/var/lib/crolord-echoes/bridge.key';
        if(!is_readable($path))return new WP_Error('account_runtime_missing','请先按插件说明在服务器安装账号运行组件。');
        $key=trim((string)file_get_contents($path));if(!preg_match('/^[a-f0-9]{64}$/D',$key))return new WP_Error('account_key','账号运行组件配置无效。');
        $port=defined('CROLORD_ECHOES_BRIDGE_PORT')?absint(CROLORD_ECHOES_BRIDGE_PORT):17683;
        if($port<1024||$port>65535)return new WP_Error('account_port','账号运行端口无效。');
        $response=wp_remote_post('http://127.0.0.1:'.$port.'/'.$operation,array('timeout'=>$timeout,'redirection'=>0,'limit_response_size'=>8388608,'headers'=>array('Authorization'=>'Bearer '.$key,'Content-Type'=>'application/json'),'body'=>wp_json_encode((object)$payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'data_format'=>'body'));
        if(is_wp_error($response))return new WP_Error('account_network','账号运行组件暂未响应，请检查服务器上的 crolord-echoes 服务。');
        $data=json_decode(wp_remote_retrieve_body($response),true);
        if(wp_remote_retrieve_response_code($response)!==200||!is_array($data))return new WP_Error('account_runtime',is_string($data['error']??null)?sanitize_text_field($data['error']):'账号服务未完成请求。');
        return $data;
    }
    public static function state($force=false) {
        if(!$force && self::$local!==null)return self::$local;
        $saved=get_transient(self::CACHE);if(!$force && is_array($saved))return self::$local=$saved;
        $result=self::request('status');
        if(is_wp_error($result)){$state=array('connected'=>false,'models'=>array(),'message'=>$result->get_error_message());set_transient(self::CACHE,$state,15);return self::$local=$state;}
        $state=array('connected'=>!empty($result['connected']),'version'=>sanitize_text_field($result['version']??''),'email'=>sanitize_email($result['email']??''),'plan'=>sanitize_text_field($result['plan']??''),'models'=>array());
        foreach(array_slice(is_array($result['models']??null)?$result['models']:array(),0,100) as $model)if(is_string($model['id']??null)&&preg_match('/^[a-zA-Z0-9._-]{2,100}$/D',$model['id']))$state['models'][]=array('id'=>$model['id'],'label'=>sanitize_text_field($model['label']??$model['id']),'default'=>!empty($model['default']),'efforts'=>array_values(array_filter(is_array($model['efforts']??null)?$model['efforts']:array(),function($v){return is_string($v)&&preg_match('/^[a-z]{1,24}$/D',$v);})), 'defaultEffort'=>sanitize_key($model['defaultEffort']??''));
        set_transient(self::CACHE,$state,60);return self::$local=$state;
    }
    public static function ready($ready){
        if($ready)return true;
        if(!is_admin()&&!wp_doing_ajax()){
            $saved=get_transient(self::CACHE);if(is_array($saved))return !empty($saved['connected'])&&!empty($saved['models']);
            $path=defined('CROLORD_ECHOES_BRIDGE_KEY_FILE')?CROLORD_ECHOES_BRIDGE_KEY_FILE:'/var/lib/crolord-echoes/bridge.key';return is_readable($path);
        }
        $state=self::state();return $state['connected']&&!empty($state['models']);
    }
    public static function servers() {
        $settings=get_option(Fieldnotes_ChatGPT::OPTION,array());$servers=array();
        foreach(is_array($settings['echoes_mcp']??null)?$settings['echoes_mcp']:array() as $server){
            $server['token']=self::token($server['secret']??'',false);unset($server['secret']);
            $url=wp_parse_url($server['url']??'');$site=wp_parse_url(home_url('/'));
            $same_site=is_array($url)&&is_array($site)&&empty($url['user'])&&empty($url['pass'])&&empty($url['fragment'])&&($url['scheme']??'')==='https'&&strtolower($url['host']??'')===strtolower($site['host']??'')&&($url['port']??443)===($site['port']??443);
            $route=$url['path']??'';$query=array();parse_str($url['query']??'',$query);
            if($same_site&&(substr($route,-strlen('/roliyal-mcp/v1/mcp'))==='/roliyal-mcp/v1/mcp'||($query['rest_route']??'')==='/roliyal-mcp/v1/mcp')){
                $server['wordpress_media']=true;$server['wordpress_media_limit']=min(10485760,wp_max_upload_size());
            }
            $servers[]=$server;
        }
        return $servers;
    }
    public static function token($value,$encrypt=true) {
        if($value==='')return '';
        if(!function_exists('openssl_encrypt'))return '';
        $key=hash('sha256',wp_salt('auth').'|crolord-echoes-mcp',true);
        if($encrypt){$iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);return $cipher===false?'':base64_encode($iv.$tag.$cipher);}
        $raw=base64_decode($value,true);if(!is_string($raw)||strlen($raw)<28)return '';
        $plain=openssl_decrypt(substr($raw,28),'aes-256-gcm',$key,OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));return is_string($plain)?$plain:'';
    }
    public static function reply($fallback,$input,$context) {
        if(!is_wp_error($fallback))return $fallback;
        $result=self::request('chat',array('messages'=>$input,'visitor'=>$context['visitor'],'request_id'=>$context['request_id'],'model'=>$context['model'],'instructions'=>$context['instructions'],'timeout'=>(int)$context['timeout']),min(135,max(25,(int)$context['timeout']+10)));
        if(is_wp_error($result))delete_transient(self::CACHE);
        return $result;
    }
    public static function ajax() {
        nocache_headers();
        if(!current_user_can('manage_options')||!check_ajax_referer('crolord_echoes_account','nonce',false))wp_send_json_error(array('message'=>'仅后台管理员可以操作账号。'),403);
        $operation=isset($_POST['operation'])&&is_string($_POST['operation'])?wp_unslash($_POST['operation']):'status';
        if(!in_array($operation,array('status','health','upgrade','upload_begin','upload_chunk','upload_finish','login','logout','discover'),true))wp_send_json_error(array('message'=>'账号操作无效。'),400);
        if($operation==='discover'){
            $url=isset($_POST['url'])&&is_string($_POST['url'])?esc_url_raw(trim(wp_unslash($_POST['url']))):'';
            $token=isset($_POST['token'])&&is_string($_POST['token'])?trim(wp_unslash($_POST['token'])):'';
            $id=isset($_POST['id'])&&is_string($_POST['id'])?sanitize_key(wp_unslash($_POST['id'])):'';
            if(strlen($token)>4096||preg_match('/[\r\n]/',$token))wp_send_json_error(array('message'=>'MCP 令牌格式无效。'),400);
            if($token==='')foreach(self::servers() as $server)if($server['id']===$id&&$server['url']===$url)$token=$server['token'];
            $result=self::request('discover',array('server'=>array('id'=>'probe','url'=>$url,'token'=>$token,'tools'=>array())),25);
        }
        elseif($operation==='status')$result=self::state(true);
        else {$result=self::request($operation,array(),30);delete_transient(self::CACHE);self::$local=null;}
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message()),502);
        wp_send_json_success($result);
    }
}
add_filter('crolord_echoes_shared_ready',array('Crolord_Echoes_Account','ready'),5);
add_filter('crolord_echoes_shared_reply',array('Crolord_Echoes_Account','reply'),5,3);
add_action('wp_ajax_crolord_echoes_account',array('Crolord_Echoes_Account','ajax'));
