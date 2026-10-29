<?php
if (!defined('ABSPATH')) exit;
/** Password-protected shared Echoes UI. No personal-plan or API-key fallback. */
final class Fieldnotes_ChatGPT {
    const OPTION = 'fieldnotes_chatgpt_settings';
    const COOKIE = 'fieldnotes_chat_access';
    const WORKSPACE_COOKIE = 'fieldnotes_chat_workspace';
    const GROUP = 'fieldnotes_chatgpt_group';
    const SHORTCODE = 'fieldnotes_chatgpt';
    const PAGE_SLUG = 'chatgpt';
    private static $instance = null;
    public static function instance() { if (self::$instance === null) self::$instance = new self(); return self::$instance; }
    private static function contains_chat_shortcode($content) {
        // Activation can run before init registers the callback; has_shortcode() then returns false.
        if(!is_string($content)||!preg_match_all('/'.get_shortcode_regex(array(self::SHORTCODE)).'/s',$content,$matches,PREG_SET_ORDER))return false;
        foreach($matches as $match)if($match[1]!=='['||$match[6]!==']')return true;
        return false;
    }
    public static function activate() {
        $page = get_post((int) get_option('fieldnotes_chatgpt_page_id'));
        if (!$page || $page->post_type !== 'page') $page = get_page_by_path('echoes', OBJECT, 'page');
        if (!$page) $page = get_page_by_path(self::PAGE_SLUG, OBJECT, 'page');
        if (!$page) {
            $page_id = wp_insert_post(array(
                'post_type' => 'page',
                'post_status' => 'publish',
                'post_title' => __('ChatGPT 对话', 'crolord-chatgpt'),
                'post_name' => self::PAGE_SLUG,
                'post_content' => '[' . self::SHORTCODE . ']',
            ));
            if (!is_wp_error($page_id) && $page_id) {
                self::set_theme_template($page_id);
                update_option('fieldnotes_chatgpt_page_id', (int) $page_id, false);
            }
        } else {
            $page_id = (int) $page->ID;
            if (!self::contains_chat_shortcode($page->post_content)) {
                wp_update_post(array(
                    'ID' => $page_id,
                    'post_content' => trim($page->post_content . "\n\n[" . self::SHORTCODE . "]"),
                ));
            }
            self::set_theme_template($page_id);
            update_option('fieldnotes_chatgpt_page_id', $page_id, false);
        }
        flush_rewrite_rules(false);
    }

    private static function set_theme_template($page_id) {
        if (in_array(get_option('stylesheet'), array('fieldnotes-journal', 'crolord'), true)) {
            update_post_meta($page_id, '_wp_page_template', 'templates/page-chatgpt.php');
        }
    }

    private function __construct() {
        add_action('init', array($this, 'register_shortcode'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 20);
        add_action('template_redirect', array($this, 'prevent_page_caching'), 0);
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts',array($this,'account_assets'));
        add_filter('body_class', array($this, 'body_classes'));
        add_filter('the_content', array($this, 'deduplicate_chat_output'), PHP_INT_MAX);
        foreach (array('unlock','chat','lock','start','poll','cancel','upload','remove_file','approve','reset','tools','models','upload_begin','upload_chunk','upload_finish','stream','artifact') as $action) {
            add_action('wp_ajax_fieldnotes_chatgpt_' . $action, array($this, 'ajax_' . $action));
            add_action('wp_ajax_nopriv_fieldnotes_chatgpt_' . $action, array($this, 'ajax_' . $action));
        }
    }
    private function settings() {
        $stored=get_option(self::OPTION,array());$stored=is_array($stored)?$stored:array();
        $settings=wp_parse_args($stored,array('password_hash'=>'','model'=>'','instructions'=>'你是一个清晰、友好、可靠的中文助手。','request_timeout'=>900,'allow_http_testing'=>1,'uploads_enabled'=>1,'effort'=>'','max_file_mb'=>16,'max_files'=>4,'web_search_enabled'=>1,'artifact_mb'=>64,'artifact_days'=>7));
        // Releases through 1.12 capped even background turns at 300 seconds.
        // Migrate those legacy limits once; explicitly saved new limits remain configurable.
        if(empty($stored['request_timeout_policy'])&&(int)$settings['request_timeout']<=300)$settings['request_timeout']=900;
        $settings['request_timeout']=min(1800,max(15,(int)$settings['request_timeout']));
        return $settings;
    }
    public function account_assets($hook) {
        if($hook!=='settings_page_fieldnotes-chatgpt')return;
        wp_enqueue_script('crolord-echoes-account',FIELDNOTES_CHATGPT_URL.'assets/js/account.min.js',array(),FIELDNOTES_CHATGPT_VERSION,true);
        wp_localize_script('crolord-echoes-account','crolordEchoesAccount',array('ajaxUrl'=>admin_url('admin-ajax.php','relative'),'nonce'=>wp_create_nonce('crolord_echoes_account'),'models'=>Crolord_Echoes_Account::state()['models']??array()));
    }
    private function is_chat_page() {
        $post=get_queried_object();return is_singular() && $post instanceof WP_Post && has_shortcode($post->post_content,self::SHORTCODE);
    }
    private function shared_ready() { return (bool) apply_filters('crolord_echoes_shared_ready',false) && has_filter('crolord_echoes_shared_reply'); }
    private function http_testing_allowed($settings = null) {
        if (defined('FIELDNOTES_CHATGPT_ALLOW_HTTP')) return (bool) FIELDNOTES_CHATGPT_ALLOW_HTTP;
        $settings = is_array($settings) ? $settings : $this->settings();
        return !empty($settings['allow_http_testing']);
    }
    private function transport_allowed() {
        return is_ssl() || $this->http_testing_allowed();
    }
    private function chat_page() {
        $page = get_post((int) get_option('fieldnotes_chatgpt_page_id'));
        if (!$page || $page->post_type !== 'page' || $page->post_status !== 'publish' || !has_shortcode($page->post_content, self::SHORTCODE)) {
            $page = get_page_by_path(self::PAGE_SLUG, OBJECT, 'page');
        }
        return $page && $page->post_type === 'page' && $page->post_status === 'publish' && has_shortcode($page->post_content, self::SHORTCODE) ? $page : null;
    }
    public function body_classes($classes) {
        $post = get_queried_object();
        if (is_singular() && $post instanceof WP_Post && has_shortcode($post->post_content, self::SHORTCODE)) $classes[] = 'fnchat-page';
        return array_values(array_unique($classes));
    }
    public function register_shortcode() {
        add_shortcode(self::SHORTCODE, array($this, 'render_shortcode'));
    }
    public function prevent_page_caching() {
        $post = get_queried_object();
        if (is_singular() && $post instanceof WP_Post && has_shortcode($post->post_content, self::SHORTCODE)) {
            if (!defined('DONOTCACHEPAGE')) { define('DONOTCACHEPAGE', true); }
            if($this->has_access((int)$post->ID))$this->visitor_owner((int)$post->ID);
            nocache_headers();
            if (!headers_sent()) {
                header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true);
            }
        }
    }
    public function deduplicate_chat_output($content) {
        if (!is_string($content) || strpos($content, '<!--fieldnotes-chatgpt:start-->') === false) return $content;
        $seen = false;
        $result = preg_replace_callback('~<!--fieldnotes-chatgpt:start-->.*?<!--fieldnotes-chatgpt:end-->~s', function ($match) use (&$seen) {
            if ($seen) return '';
            $seen = true;
            return $match[0];
        }, $content);
        return is_string($result) ? $result : $content;
    }
    public function render_shortcode() {
        $page=$this->chat_page();$page_id=$page?(int)$page->ID:0;$settings=$this->settings();
        $password_ready=!empty($settings['password_hash']);$authorized=$password_ready && $page_id && $this->has_access($page_id);
        $backend_ready=$authorized && $this->shared_ready();
        $settings['uploads_enabled']=1;
        ob_start();include FIELDNOTES_CHATGPT_DIR.'views/chat.php';
        return '<!--fieldnotes-chatgpt:start-->'.ob_get_clean().'<!--fieldnotes-chatgpt:end-->';
    }
    public function enqueue_assets() {
        if(!$this->is_chat_page())return;
        $page=$this->chat_page();$authorized=$page && $this->has_access((int)$page->ID) && $this->transport_allowed();
        wp_enqueue_style('fieldnotes-chatgpt',FIELDNOTES_CHATGPT_URL.'assets/css/'.($authorized?'chat':'gate').'.min.css',array(),FIELDNOTES_CHATGPT_VERSION);
        if($authorized){
            wp_enqueue_script('fieldnotes-chatgpt-parser',FIELDNOTES_CHATGPT_URL.'assets/vendor/markdown-it.min.js',array(),FIELDNOTES_CHATGPT_VERSION,true);
            wp_enqueue_script('fieldnotes-chatgpt-rich-text',FIELDNOTES_CHATGPT_URL.'assets/js/rich-text.min.js',array('fieldnotes-chatgpt-parser'),FIELDNOTES_CHATGPT_VERSION,true);
            wp_enqueue_script('fieldnotes-chatgpt-tool-picker',FIELDNOTES_CHATGPT_URL.'assets/js/tool-picker.min.js',array(),FIELDNOTES_CHATGPT_VERSION,true);
            wp_enqueue_script('fieldnotes-chatgpt-reply-actions',FIELDNOTES_CHATGPT_URL.'assets/js/reply-actions.min.js',array(),FIELDNOTES_CHATGPT_VERSION,true);
        }
        wp_enqueue_script('fieldnotes-chatgpt',FIELDNOTES_CHATGPT_URL.'assets/js/'.($authorized?'chat':'gate').'.min.js',$authorized?array('fieldnotes-chatgpt-rich-text','fieldnotes-chatgpt-tool-picker','fieldnotes-chatgpt-reply-actions'):array(),FIELDNOTES_CHATGPT_VERSION,true);
        wp_localize_script('fieldnotes-chatgpt','fieldnotesChatGPT',array(
            'ajaxUrl'=>admin_url('admin-ajax.php','relative'),'nonce'=>wp_create_nonce('fieldnotes_chatgpt'),
            'ready'=>$authorized && $this->shared_ready(),'timeout'=>$this->settings()['request_timeout'],'pendingLifetime'=>5400,
            'uploadMax'=>min(128,max(1,(int)$this->settings()['max_file_mb']))*1048576,'maxFiles'=>min(12,max(1,(int)$this->settings()['max_files'])),'chunkSize'=>min(1048576,max(65536,(int)(wp_max_upload_size()/2))),'historyScope'=>hash('sha256',(string)($page?$page->ID:0).'|'.$this->settings()['password_hash']),'visitorScope'=>$authorized?$this->visitor_owner((int)$page->ID):'',
            'strings'=>array('copy'=>'复制回复','copied'=>'已复制','copyFailed'=>'复制未完成，请选择文字复制。','networkError'=>'请求未完成，消息没有自动重发。',
                'passwordRequired'=>'请先输入访问口令。','working'=>'正在连接…','enter'=>'进入对话')));
    }
    private function require_access($download=false) {
        nocache_headers();$this->verify_nonce($download);$page=$this->chat_page();
        if(!$this->transport_allowed() || !$page || !$this->has_access((int)$page->ID))wp_send_json_error(array('message'=>'访问已锁定，请刷新页面后重新输入口令。','code'=>'session_expired'),403);
        return $this->visitor_owner((int)$page->ID);
    }
    /** Persistent workspace identity survives re-unlock; the separate 12-hour gate is still required. */
    private function visitor_owner($page_id) {
        $settings=$this->settings();$value=$_COOKIE[self::WORKSPACE_COOKIE]??'';
        if(is_string($value)&&preg_match('/^([0-9]{10})\.([a-f0-9]{64})\.([a-f0-9]{64})$/D',$value,$parts)){
            $expires=(int)$parts[1];$signature=hash_hmac('sha256','workspace|'.$parts[1].'|'.$parts[2].'|'.$page_id.'|'.$settings['password_hash'],wp_salt('auth'));
            if($expires>=time()&&$expires<=time()+30*DAY_IN_SECONDS+60&&hash_equals($signature,$parts[3]))return $parts[2];
        }
        $owner=hash_hmac('sha256',$page_id.'|'.$_COOKIE[self::COOKIE],wp_salt('auth'));$expires=time()+30*DAY_IN_SECONDS;
        $signature=hash_hmac('sha256','workspace|'.$expires.'|'.$owner.'|'.$page_id.'|'.$settings['password_hash'],wp_salt('auth'));
        $value=$expires.'.'.$owner.'.'.$signature;
        if(!headers_sent())$this->write_cookie($value,$expires,self::WORKSPACE_COOKIE);
        $_COOKIE[self::WORKSPACE_COOKIE]=$value;
        return $owner;
    }
    /** A single byte range keeps video seeking bounded; multipart ranges are rejected. */
    public static function artifact_range($header,$size) {
        if(!is_int($size)||$size<1||$size>268435456)return false;
        if($header==='')return array(0,$size-1,200);
        if(!is_string($header)||!preg_match('/^bytes=([0-9]{0,12})-([0-9]{0,12})$/D',$header,$m)||($m[1]===''&&$m[2]===''))return false;
        if($m[1]===''){$length=(int)$m[2];if(!$length)return false;$start=max(0,$size-$length);$end=$size-1;}
        else{$start=(int)$m[1];$end=$m[2]===''?$size-1:min($size-1,(int)$m[2]);if($start>$end||$start>=$size)return false;}
        return array($start,$end,206);
    }
    public function ajax_artifact() {
        if(!in_array($_SERVER['REQUEST_METHOD']??'',array('GET','HEAD'),true)){status_header(405);exit;}
        $owner=$this->require_access(true);$id=is_string($_GET['file_id']??null)?wp_unslash($_GET['file_id']):'';
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))wp_send_json_error(array('message'=>'文件标识无效。'),400);
        $meta=Crolord_Echoes_Account::request('artifact_info',array('visitor'=>$owner,'file_id'=>$id),8);
        if(is_wp_error($meta))wp_send_json_error(array('message'=>'文件已过期、不可读取或不属于当前访问。'),404);
        $size=$meta['size']??null;$range=self::artifact_range(is_string($_SERVER['HTTP_RANGE']??null)?$_SERVER['HTTP_RANGE']:'',$size);
        if($range===false){status_header(416);if(is_int($size))header('Content-Range: bytes */'.$size);exit;}
        list($start,$end,$status)=$range;
        $inline=($_GET['inline']??'')==='1'&&in_array($meta['mime']??'',array('image/png','image/jpeg','image/gif','image/webp','video/mp4','video/webm','audio/mpeg','audio/wav','audio/ogg'),true);
        $mime=$inline?$meta['mime']:'application/octet-stream';$name=is_string($meta['name']??null)?$meta['name']:'echoes-file';
        $name=preg_replace('/[\x00-\x1f\x7f\/\\\\]/u','_',$name);$fallback=sanitize_file_name(remove_accents($name));
        $fallback=preg_replace('/[^a-zA-Z0-9._-]/','_',$fallback);if(!$fallback)$fallback='echoes-file';
        if(function_exists('set_time_limit'))@set_time_limit(300);
        while(ob_get_level())ob_end_clean();
        status_header($status);header('Content-Type: '.$mime);header('Content-Length: '.($end-$start+1));
        header('Accept-Ranges: bytes');if($status===206)header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);
        header('Cache-Control: private, no-store, no-cache, max-age=0, no-transform');header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');header("Content-Security-Policy: sandbox; default-src 'none'");
        header('Content-Disposition: '.($inline?'inline':'attachment').'; filename="'.$fallback.'"; filename*=UTF-8\'\''.rawurlencode($name));
        if(($_SERVER['REQUEST_METHOD']??'')==='HEAD')exit;
        for($offset=$start;$offset<=$end&&!connection_aborted();){
            $length=min(1048576,$end-$offset+1);
            $chunk=Crolord_Echoes_Account::request('artifact_chunk',array('visitor'=>$owner,'file_id'=>$id,'offset'=>$offset,'length'=>$length),20);
            if(is_wp_error($chunk)||($chunk['offset']??null)!==$offset||!is_string($chunk['data']??null))break;
            $bytes=base64_decode($chunk['data'],true);
            if(!is_string($bytes)||strlen($bytes)!==$length||!is_string($chunk['sha256']??null)||!hash_equals(hash('sha256',$bytes),$chunk['sha256']))break;
            echo $bytes;flush();$offset+=$length;
        }
        exit;
    }
    public function unsupported_capability() {
        $this->require_access();wp_send_json_error(array('message'=>'共享服务尚未接通这项功能。','code'=>'shared_capability_unavailable'),503);
    }
    public function ajax_start() { $this->ajax_chat(true); }
    public function ajax_poll() { $this->job_action('poll'); }
    public function ajax_cancel() { $this->job_action('cancel'); }
    public function ajax_approve() { $this->job_action('approve'); }
    private function job_action($operation) {
        $owner=$this->require_access();
        $id=isset($_POST['request_id'])&&is_string($_POST['request_id'])?wp_unslash($_POST['request_id']):'';
        if(!preg_match('/^[a-f0-9]{32,64}$/D',$id))wp_send_json_error(array('message'=>'请求标识无效。'),400);
        $payload=array('visitor'=>$owner,'request_id'=>$id);
        if($operation==='approve'){
            $call=isset($_POST['call_id'])&&is_string($_POST['call_id'])?wp_unslash($_POST['call_id']):'';
            if(!preg_match('/^[a-f0-9]{32}$/D',$call))wp_send_json_error(array('message'=>'工具确认标识无效。'),400);
            $payload['call_id']=$call;$payload['allow']=($_POST['allow']??'')==='1';
        }
        $this->respond(Crolord_Echoes_Account::request($operation,$payload,12));
    }
    private function respond($result) {
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message(),'code'=>$result->get_error_code()),502);
        wp_send_json_success($result);
    }
    public function ajax_models(){
        $this->require_access();$account=Crolord_Echoes_Account::state(true);$settings=$this->settings();
        if(!empty($account['models'])&&version_compare((string)($account['version']??'0'),'1.17.0','<'))wp_send_json_error(array('code'=>'runtime_outdated','message'=>'Echoes 运行组件正在更新，请稍后刷新。'),409);
        wp_send_json_success(array('models'=>$account['models']??array(),'model'=>$settings['model'],'effort'=>$settings['effort'],'runtime'=>$account['version']??''));
    }
    public function ajax_upload_begin(){
        $owner=$this->require_access();$settings=$this->settings();$rate='crolord_echoes_upload_'.substr($owner,0,32);$count=(int)get_transient($rate);
        if($count>=128)wp_send_json_error(array('message'=>'上传次数较多，请稍后重试。'),429);set_transient($rate,$count+1,10*MINUTE_IN_SECONDS);
        $name=is_string($_POST['name']??null)?sanitize_file_name(wp_unslash($_POST['name'])):'';$size=is_scalar($_POST['size']??null)?absint($_POST['size']):0;
        $this->respond(Crolord_Echoes_Account::request('upload_begin',array('visitor'=>$owner,'name'=>$name,'size'=>$size,'limit'=>(int)$settings['max_file_mb']*1048576)));
    }
    public function ajax_upload_chunk(){
        $owner=$this->require_access();$id=is_string($_POST['upload_id']??null)?wp_unslash($_POST['upload_id']):'';$file=$_FILES['file']??null;
        if(!preg_match('/^[a-f0-9]{32}$/D',$id)||!is_array($file)||($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||filesize($file['tmp_name'])>1048576)wp_send_json_error(array('message'=>'上传分片未完成，请重试。'),400);
        $offset=is_scalar($_POST['offset']??null)?absint($_POST['offset']):0;
        $this->respond(Crolord_Echoes_Account::request('upload_chunk',array('visitor'=>$owner,'upload_id'=>$id,'offset'=>$offset,'data'=>base64_encode(file_get_contents($file['tmp_name']))),20));
    }
    public function ajax_upload_finish(){
        $owner=$this->require_access();$id=is_string($_POST['upload_id']??null)?wp_unslash($_POST['upload_id']):'';
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))wp_send_json_error(array('message'=>'上传标识无效。'),400);
        $this->respond(Crolord_Echoes_Account::request('upload_finish',array('visitor'=>$owner,'upload_id'=>$id),30));
    }
    /** Short, authenticated event streams; ordinary polling remains the fallback. */
    public function ajax_stream(){
        $owner=$this->require_access();$id=is_string($_POST['request_id']??null)?wp_unslash($_POST['request_id']):'';
        if(!preg_match('/^[a-f0-9]{32,64}$/D',$id))wp_send_json_error(array('message'=>'请求标识无效。'),400);
        if(function_exists('set_time_limit'))@set_time_limit(16);
        @ini_set('zlib.output_compression','0');while(ob_get_level())ob_end_clean();
        header('Content-Type: text/event-stream; charset=utf-8');header('Cache-Control: private, no-cache, no-store, no-transform');header('X-Accel-Buffering: no');
        echo ": ready\n\n";flush();$deadline=microtime(true)+10;$last='';$partial='';$heartbeat=microtime(true);$metadata=array();$compact=is_string($_POST['stream_protocol']??null)&&$_POST['stream_protocol']==='2';
        while(microtime(true)<$deadline&&!connection_aborted()){
            $result=Crolord_Echoes_Account::request('poll',array('visitor'=>$owner,'request_id'=>$id),2);
            if(is_wp_error($result)){echo 'data: '.wp_json_encode(array('stream_error'=>$result->get_error_message()))."\n\n";flush();break;}
            $encoded=wp_json_encode($result);if($encoded!==$last){
                $last=$encoded;$event=$result;$current=is_string($result['partial']??null)?$result['partial']:'';
                if($partial!==''&&strpos($current,$partial)===0){unset($event['partial']);$event['delta']=substr($current,strlen($partial));}
                $partial=$current;
                if($compact&&!in_array($result['status']??'',array('done','failed','cancelled'),true)){
                    foreach(array('activity','sources','references','phase','stage','timeout','artifacts','artifact_notices') as $field){
                        if(!array_key_exists($field,$event))continue;$value=wp_json_encode($event[$field]);
                        if(array_key_exists($field,$metadata)&&$metadata[$field]===$value)unset($event[$field]);else $metadata[$field]=$value;
                    }
                }
                echo 'data: '.wp_json_encode($event)."\n\n";flush();$heartbeat=microtime(true);
            }elseif(microtime(true)-$heartbeat>2){echo ": heartbeat\n\n";flush();$heartbeat=microtime(true);}
            if(in_array($result['status']??'',array('done','failed','cancelled'),true))break;
            usleep(100000);
        }
        exit;
    }
    public function ajax_tools() {
        $this->require_access();
        $this->respond(Crolord_Echoes_Account::request('tools',array('mcp_servers'=>Crolord_Echoes_Account::servers()),110));
    }
    public function ajax_upload() {
        $owner=$this->require_access();$rate='crolord_echoes_upload_'.substr($owner,0,32);$count=(int)get_transient($rate);
        if($count>=30)wp_send_json_error(array('message'=>'上传次数较多，请稍后重试。'),429);
        $file=$_FILES['file']??null;
        if(!is_array($file)||!is_scalar($file['error']??null)||is_array($file['tmp_name']??null)||($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)wp_send_json_error(array('message'=>'上传未完成，请检查文件大小与服务器上传上限。'),400);
        $path=$file['tmp_name'];$size=filesize($path);
        if(!is_uploaded_file($path)||!$size||$size>min((int)$this->settings()['max_file_mb']*1048576,wp_max_upload_size()))wp_send_json_error(array('message'=>'附件超过当前上传上限。'),400);
        $name=sanitize_file_name($file['name']);$ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
        if(!in_array($ext,array('jpg','jpeg','png','webp','gif','pdf','txt','md','csv','tsv','json','log','docx','xlsx','pptx'),true))wp_send_json_error(array('message'=>'暂不支持此格式。可上传图片、PDF、文本、DOCX、XLSX 或 PPTX。'),400);
        if(in_array($ext,array('jpg','jpeg','png','webp','gif'),true)){
            $image=@getimagesize($path);
            if(!$image||$image[0]*$image[1]>32000000)wp_send_json_error(array('message'=>'图片无效或超过 3200 万像素，请缩小后上传。'),400);
        }
        set_transient($rate,$count+1,10*MINUTE_IN_SECONDS);
        $this->respond(Crolord_Echoes_Account::request('upload',array('visitor'=>$owner,'name'=>$name,'data'=>base64_encode(file_get_contents($path))),25));
    }
    public function ajax_remove_file() {
        $owner=$this->require_access();$id=isset($_POST['file_id'])&&is_string($_POST['file_id'])?wp_unslash($_POST['file_id']):'';
        if(!preg_match('/^[a-f0-9]{32}$/D',$id))wp_send_json_error(array('message'=>'附件标识无效。'),400);
        $this->respond(Crolord_Echoes_Account::request('remove',array('visitor'=>$owner,'file_id'=>$id)));
    }
    public function ajax_reset() {
        $owner=$this->require_access();$this->respond(Crolord_Echoes_Account::request('reset',array('visitor'=>$owner)));
    }
    public function ajax_unlock() {
        $this->verify_nonce();
        
        if (!$this->transport_allowed()) {
            wp_send_json_error(array('message' => __('页面口令只会通过 HTTPS 验证，请先启用 HTTPS。', 'crolord-chatgpt')), 403);
        }
        $settings = $this->settings();
        $page = $this->chat_page();
        if (!$page || empty($settings['password_hash'])) {
            wp_send_json_error(array('message' => __('此页面尚未完成口令设置。', 'crolord-chatgpt')), 503);
        }

        $rate_key = $this->rate_key('unlock');
        $attempts = (int) get_transient($rate_key);
        if ($attempts >= 5) {
            wp_send_json_error(array('message' => __('尝试次数过多，请 15 分钟后再试。', 'crolord-chatgpt')), 429);
        }

        $password = isset($_POST['password']) && is_string($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
        if (strlen($password) > 512 || !password_verify($password, $settings['password_hash'])) {
            if (0 === $attempts) { set_transient($rate_key, 1, 15 * MINUTE_IN_SECONDS); }
            else { set_transient($rate_key, $attempts + 1, 15 * MINUTE_IN_SECONDS); }
            wp_send_json_error(array('message' => __('口令不正确，请重试。', 'crolord-chatgpt')), 403);
        }

        delete_transient($rate_key);
        $this->set_access_cookie((int) $page->ID, $settings['password_hash']);
        wp_send_json_success(array('message' => __('验证成功。', 'crolord-chatgpt')));
    }
    public function ajax_chat($async=false) {
        $owner=$this->require_access();
        if(!$this->shared_ready())wp_send_json_error(array('message'=>'共享对话服务尚未接通，请联系站主。','code'=>'shared_provider_missing'),503);
        $settings=$this->settings();
        if(!is_ssl()&&empty($settings['allow_http_testing']))wp_send_json_error(array('message'=>'当前站点仅允许通过 HTTPS 发送消息。'),403);
        $raw=isset($_POST['messages'])&&is_string($_POST['messages'])?wp_unslash($_POST['messages']):'';
        $id=isset($_POST['request_id'])&&is_string($_POST['request_id'])?wp_unslash($_POST['request_id']):'';
        if(!preg_match('/^[a-f0-9-]{32,64}$/D',$id)||strlen($raw)>65000)wp_send_json_error(array('message'=>'消息格式无效或内容过长。'),400);
        $messages=json_decode($raw,true);$input=array();$previous='';
        if(!is_array($messages)||count($messages)<1||count($messages)>25)wp_send_json_error(array('message'=>'请开启一段新对话后重试。'),400);
        foreach($messages as $message){
            if(!is_array($message)||!is_string($message['role']??null)||!is_string($message['content']??null)||!in_array($message['role'],array('user','assistant'),true)||$message['role']===$previous||trim($message['content'])===''||strlen($message['content'])>($message['role']==='user'?18000:65000))wp_send_json_error(array('message'=>'消息格式无效。'),400);
            $item=array('role'=>$message['role'],'content'=>$message['content']);$files=$message['files']??array();
            if(!is_array($files)||count($files)>min(12,max(1,(int)$settings['max_files']))||($files&&$message['role']!=='user'))wp_send_json_error(array('message'=>'附件列表无效。'),400);
            foreach($files as $file)if(!is_string($file)||!preg_match('/^[a-f0-9]{32}$/D',$file))wp_send_json_error(array('message'=>'附件标识无效。'),400);
            if($files)$item['files']=array_values(array_unique($files));
            $input[]=$item;$previous=$message['role'];
        }
        if($input[0]['role']!=='user'||$previous!=='user')wp_send_json_error(array('message'=>'最后一条消息需要来自你。'),400);
        $tools=isset($_POST['tools'])&&is_string($_POST['tools'])?json_decode(wp_unslash($_POST['tools']),true):array();
        if(!is_array($tools)||count($tools)>32)wp_send_json_error(array('message'=>'工具选择无效。'),400);
        foreach($tools as $tool){
            if(is_string($tool)&&preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$tool))continue;
            if(!is_array($tool)||!is_string($tool['server']??null)||!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$tool['server'])||!is_array($tool['tools']??null)||count($tool['tools'])>512)wp_send_json_error(array('message'=>'工具标识无效。'),400);
            foreach($tool['tools'] as $name)if(!is_string($name)||strlen($name)>128||preg_match('/[\x00-\x1f\x7f]/',$name))wp_send_json_error(array('message'=>'工具名无效。'),400);
        }
        $selected_model=is_string($_POST['model']??null)?sanitize_text_field(wp_unslash($_POST['model'])):$settings['model'];
        if($selected_model!==''&&!preg_match('/^[a-zA-Z0-9._-]{2,100}$/D',$selected_model))wp_send_json_error(array('message'=>'模型标识无效。'),400);
        $selected_effort=is_string($_POST['effort']??null)?sanitize_key(wp_unslash($_POST['effort'])):$settings['effort'];
        $conversation=is_string($_POST['conversation_id']??null)?wp_unslash($_POST['conversation_id']):null;
        if($conversation!==null&&!preg_match('/^[a-f0-9]{32}$/D',$conversation))wp_send_json_error(array('message'=>'对话标识无效。'),400);
        // Search follows the owner setting. A stale browser toggle must not disable it.
        $web_mode=!empty($settings['web_search_enabled'])?'live':'disabled';
        $runtime=Crolord_Echoes_Account::state();
        if(version_compare((string)($runtime['version']??'0'),'1.17.0','<'))wp_send_json_error(array('code'=>'runtime_outdated','message'=>'Echoes 运行组件正在更新，请稍后刷新。'),409);
        $key='crolord_echoes_turn_'.hash('sha256',$owner.'|'.$id);$fingerprint=hash('sha256',wp_json_encode(array($input,$tools,$selected_model,$selected_effort,$conversation,$web_mode)));$existing=get_transient($key);
        if(is_array($existing)){
            if(!hash_equals($existing['fingerprint'],$fingerprint))wp_send_json_error(array('message'=>'重复请求的内容不一致。'),409);
            if(($existing['status']??'')==='done')wp_send_json_success($existing['result']);
            wp_send_json_error(array('message'=>'这条消息已提交或结果尚未确认，没有重复发送。'),409);
        }
        $lock='crolord_echoes_turn_lock_'.substr($owner,0,32);$until=get_option($lock);
        if(is_numeric($until)&&(int)$until<time())delete_option($lock);
        if(!add_option($lock,time()+180,'',false))wp_send_json_error(array('message'=>'上一条消息还在处理，请稍候。'),429);
        $result=null;
        try{
            $existing=get_transient($key);
            if(is_array($existing))$result=new WP_Error('duplicate','这条消息已提交，没有重复发送。');
            else{
                $rate='crolord_echoes_shared_rate_'.substr($owner,0,32);$count=(int)get_transient($rate);
                if($count>=20)$result=new WP_Error('rate_limited','发送次数较多，请稍后再试。');
                else {
                set_transient($rate,$count+1,10*MINUTE_IN_SECONDS);
                set_transient($key,array('status'=>'pending','fingerprint'=>$fingerprint),90*MINUTE_IN_SECONDS);
                if($async){
                    $result=Crolord_Echoes_Account::request('start',array('visitor'=>$owner,'request_id'=>$id,'messages'=>$input,'tools'=>$tools,'mcp_servers'=>Crolord_Echoes_Account::servers(),'model'=>$selected_model,'effort'=>$selected_effort,'conversation_id'=>$conversation,'web_search'=>$web_mode,'instructions'=>$settings['instructions'],'timeout'=>$settings['request_timeout'],'outputs_enabled'=>true,'artifact_limit'=>min(256,max(1,(int)$settings['artifact_mb']))*1048576,'artifact_days'=>min(30,max(1,(int)$settings['artifact_days']))),15);
                    set_transient($key,array('status'=>is_wp_error($result)?'uncertain':'done','fingerprint'=>$fingerprint,'result'=>is_wp_error($result)?null:$result),90*MINUTE_IN_SECONDS);
                }else{
                $result=apply_filters('crolord_echoes_shared_reply',new WP_Error('shared_provider_missing','共享对话服务尚未接通。'),$input,array('visitor'=>$owner,'request_id'=>$id,'model'=>$settings['model'],'instructions'=>$settings['instructions'],'timeout'=>$settings['request_timeout']));
                if(!is_wp_error($result) && (!is_array($result) || !is_string($result['text']??null) || trim($result['text'])==='' || strlen($result['text'])>262144 || ($result['completed']??false)!==true))$result=new WP_Error('shared_incomplete','共享服务未返回完整回复，本次没有保存为成功。');
                if(!is_wp_error($result))$result=array('text'=>$result['text']);
                set_transient($key,array('status'=>is_wp_error($result)?'uncertain':'done','fingerprint'=>$fingerprint,'result'=>is_wp_error($result)?null:$result),90*MINUTE_IN_SECONDS);
                }
                }
            }
        }finally{delete_option($lock);}
        if(is_wp_error($result))wp_send_json_error(array('message'=>$result->get_error_message(),'code'=>$result->get_error_code()),$result->get_error_code()==='rate_limited'?429:502);
        wp_send_json_success($result);
    }
    public function admin_menu() { add_options_page('Echoes','Echoes','manage_options','fieldnotes-chatgpt',array($this,'render_settings_page')); }
    public function register_settings() { register_setting(self::GROUP,self::OPTION,array('type'=>'array','sanitize_callback'=>array($this,'sanitize_settings'))); }
    public function sanitize_settings($input) {
        $new=$this->settings();$input=is_array($input)?$input:array();
        $new['allow_http_testing']=!empty($input['allow_http_testing'])?1:0;
        $new['web_search_enabled']=!empty($input['web_search_enabled'])?1:0;
        $password=is_string($input['page_password']??null)?$input['page_password']:'';
        if($password!=='') {
            $length=function_exists('mb_strlen')?mb_strlen($password,'UTF-8'):strlen($password);
            if(!$this->http_testing_allowed($new) && !is_ssl())add_settings_error(self::OPTION,'https_required','请使用 HTTPS 保存口令，或启用 HTTP 测试。');
            elseif($length<8 || strlen($password)>512)add_settings_error(self::OPTION,'password_length','页面口令至少 8 个字符，且不超过 512 字节。');
            else $new['password_hash']=password_hash($password,PASSWORD_DEFAULT);
        }
        if(is_string($input['model']??null) && ($input['model']===''||preg_match('/^[a-zA-Z0-9._-]{2,100}$/D',$input['model'])))$new['model']=$input['model'];
        if(is_string($input['instructions']??null) && strlen($input['instructions'])<=6000)$new['instructions']=sanitize_textarea_field($input['instructions']);
        $new['request_timeout']=min(1800,max(15,is_scalar($input['request_timeout']??null)?absint($input['request_timeout']):$new['request_timeout']));
        $new['request_timeout_policy']=1;
        $new['effort']=is_string($input['effort']??null)?sanitize_key($input['effort']):$new['effort'];
        $new['max_file_mb']=min(128,max(1,absint($input['max_file_mb']??$new['max_file_mb'])));$new['max_files']=min(12,max(1,absint($input['max_files']??$new['max_files'])));
        $new['artifact_mb']=min(256,max(1,absint($input['artifact_mb']??$new['artifact_mb'])));$new['artifact_days']=min(30,max(1,absint($input['artifact_days']??$new['artifact_days'])));
        if(isset($input['echoes_mcp_json'])&&is_string($input['echoes_mcp_json'])){
            if(strlen($input['echoes_mcp_json'])>4000000||!is_array($decoded=json_decode($input['echoes_mcp_json'],true)))add_settings_error(self::OPTION,'mcp_json','MCP 数据未完整提交，保留原配置。');
            else $input['echoes_mcp']=$decoded;
        }
        if(isset($input['echoes_mcp'])&&is_array($input['echoes_mcp'])){
            $servers=array();$ids=array();$invalid=false;$old=array_column(is_array($new['echoes_mcp']??null)?$new['echoes_mcp']:array(),null,'id');
            foreach(array_slice($input['echoes_mcp'],0,32) as $row){
                if(!is_array($row)||empty($row['url']))continue;
                $id=is_string($row['id']??null)?$row['id']:'';$url=is_string($row['url'])?esc_url_raw(trim($row['url'])):'';
                $tools=is_string($row['tools']??null)?array_values(array_filter(array_map('trim',preg_split('/[,\r\n]+/',$row['tools'])))):array();
                $parsed=wp_parse_url($url);
                if(!preg_match('/^[a-zA-Z0-9_-]{1,40}$/D',$id)||isset($ids[$id])||!is_array($parsed)||($parsed['scheme']??'')!=='https'||!empty($parsed['user'])||!empty($parsed['pass'])||!empty($parsed['fragment'])||!$tools||count($tools)>512){$invalid=true;add_settings_error(self::OPTION,'mcp_invalid','MCP 配置未保存：需要唯一标识、公网 HTTPS 地址和明确允许的工具名。');continue;}
                $valid=true;foreach($tools as $tool)if(strlen($tool)>128||preg_match('/[\x00-\x1f\x7f]/',$tool))$valid=false;
                if(!$valid){$invalid=true;add_settings_error(self::OPTION,'mcp_tools','MCP 工具名无效。');continue;}
                // Credentials never silently move to another service address.
                $secret=empty($row['clear_token'])&&($old[$id]['url']??'')===$url?($old[$id]['secret']??''):'';
                $token=is_string($row['token']??null)?trim($row['token']):'';
                if($token!==''){
                    if(strlen($token)>4096||preg_match('/[\r\n]/',$token)){$invalid=true;add_settings_error(self::OPTION,'mcp_token','MCP 令牌格式无效。');continue;}
                    $secret=Crolord_Echoes_Account::token($token);
                    if($secret===''){$invalid=true;add_settings_error(self::OPTION,'mcp_encrypt','服务器缺少令牌加密支持，MCP 配置未保存。');continue;}
                }
                $ids[$id]=true;$servers[]=array('id'=>$id,'name'=>sanitize_text_field($row['name']??$id),'url'=>$url,'tools'=>array_values(array_unique($tools)),'secret'=>$secret);
            }
            if(!$invalid)$new['echoes_mcp']=$servers;
        }
        return $new; // Preserve legacy credentials and unsupported settings without executing them.
    }
    public function render_settings_page() {
        if(!current_user_can('manage_options'))return;
        $settings=$this->settings();$page=$this->chat_page();$ready=$this->shared_ready();$account=Crolord_Echoes_Account::state();
        include FIELDNOTES_CHATGPT_DIR.'views/settings-shared.php';
    }

    public function ajax_lock() {
        $this->verify_nonce();
        $this->clear_access_cookie();
        wp_send_json_success(array('message' => __('页面已锁定。', 'crolord-chatgpt')));
    }
    private function verify_nonce($download=false) {
        $source=$download?$_GET:$_POST;
        $nonce = isset($source['nonce']) && is_string($source['nonce']) ? sanitize_text_field(wp_unslash($source['nonce'])) : '';
        if (!wp_verify_nonce($nonce, 'fieldnotes_chatgpt')) {
            wp_send_json_error(array('message' => __('页面已过期，请刷新后重试。', 'crolord-chatgpt')), 403);
        }
    }
    private function has_access($page_id) {
        $settings = $this->settings();
        if (empty($settings['password_hash']) || empty($_COOKIE[self::COOKIE]) || !is_string($_COOKIE[self::COOKIE])) { return false; }
        $token = sanitize_text_field(wp_unslash($_COOKIE[self::COOKIE]));
        // Each unlock gets a random session identity; two visitors unlocking in
        // the same second must never share workspace ownership or file access.
        if (!preg_match('/^([0-9]{10})\.([a-f0-9]{32})\.([a-f0-9]{64})$/D', $token, $matches)) { return false; }
        $expires = (int) $matches[1];
        if ($expires < time() || $expires > time() + 12 * HOUR_IN_SECONDS + 60) { return false; }
        $expected = hash_hmac('sha256', $expires . '|' . $matches[2] . '|' . (int) $page_id . '|' . $settings['password_hash'], wp_salt('auth'));
        return hash_equals($expected, $matches[3]);
    }
    private function set_access_cookie($page_id, $password_hash) {
        $expires = time() + 12 * HOUR_IN_SECONDS;
        $session = bin2hex(random_bytes(16));
        $signature = hash_hmac('sha256', $expires . '|' . $session . '|' . (int) $page_id . '|' . $password_hash, wp_salt('auth'));
        $value = $expires . '.' . $session . '.' . $signature;
        $this->write_cookie($value, $expires);
        $_COOKIE[self::COOKIE] = $value;
    }
    private function clear_access_cookie() {
        $this->write_cookie('', time() - HOUR_IN_SECONDS);
        unset($_COOKIE[self::COOKIE]);
    }
    private function write_cookie($value, $expires, $cookie_name=self::COOKIE) {
        $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        // The cookie must reach both the public page and admin-ajax.php.
        $ajax_path = wp_parse_url(admin_url('admin-ajax.php', 'relative'), PHP_URL_PATH);
        if (!is_string($ajax_path) || strpos($ajax_path, trailingslashit($path)) !== 0) $path = '/';
        $domain = defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '';
        setcookie($cookie_name, $value, array(
            'expires' => (int) $expires,
            'path' => $path,
            'domain' => $domain,
            'secure' => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }
    private function rate_key($kind) {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown';
        return 'fnchat_' . sanitize_key($kind) . '_' . substr(wp_hash($ip), 0, 32);
    }
}
