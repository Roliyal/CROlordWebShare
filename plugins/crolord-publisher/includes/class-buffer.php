<?php
if (!defined('ABSPATH')) { exit; }

/** Buffer's supported GraphQL publishing API. No X website scripting or X API billing credentials. */
final class Crolord_Publisher_Buffer {
    const OPTION = 'crolord_publisher_buffer';
    const POST_FIELDS = 'id channelId status externalLink schedulingType allowedActions error { message } channel { service serviceId }';
    const CHANNEL_FIELDS = 'id name service serviceId organizationId isDisconnected isLocked isQueuePaused allowedActions';
    public static function config() { return get_option(self::OPTION, array()); }
    public static function route() { return 'buffer'; }
    private static function fields($payload) {
        return self::POST_FIELDS.(!empty($payload['link_layout'])?' text assets { id mimeType type } metadata { ... on TwitterPostMetadata { threadCount thread { text assets { id mimeType type } } } }':'');
    }
    public static function verify_media($payload,$post,&$seen=null) {
        if(empty($payload['link_layout'])){return null;} // Frozen old jobs keep their original verification.
        $seen=is_array($post['assets']??null)?count($post['assets']):null;
        if(Crolord_Publisher_Platforms::card($payload)){
            if(($post['text']??'')!==Crolord_Publisher_Platforms::text($payload)){throw new RuntimeException('Buffer 已发正文与文章标题／链接不一致，保留原帖待核对。');}
            $thread=$post['metadata']['thread']??array();
            if(!is_array($thread)||count($thread)>1||(count($thread)===1&&($thread[0]['text']??'')!==$post['text'])){throw new RuntimeException('Buffer 网页卡片任务被改成了额外回复，保留待核对。');}
            $assets=$post['assets']??null;
            if(!is_array($assets)||count($assets)>1){throw new RuntimeException('Buffer 网页卡片回执出现多张附件，保留待核对。');}
            foreach($assets as$asset){if(!is_array($asset)||($asset['type']??'')!=='image'){throw new RuntimeException('Buffer 网页卡片回执出现了其他媒体类型，保留待核对。');}}
            return count($assets);
        }
        $expected=count($payload['images']);
        if(Crolord_Publisher_Platforms::link_last($payload)){
            $thread=$post['metadata']['thread']??null;
            if(!is_array($thread)){throw new RuntimeException('Buffer 已发送，但未返回串帖结构；保留原 ID 待核对，不重新创建。');}
            if(count($thread)===2){$root=$thread[0];$tail=$thread[1];}
            elseif(count($thread)===1){$root=$post;$tail=$thread[0];}
            else{throw new RuntimeException('Buffer 串帖数量与标题配图＋最后网址不一致，保留待核对。');}
            if(($root['text']??'')!==$payload['title']||($tail['text']??'')!==$payload['url']||!empty($tail['assets'])){throw new RuntimeException('Buffer 未核对到标题配图在前、网址回复在后；保留原帖待核对。');}
        }else{
            $root=$post;
            if(($payload['link_layout']??'')==='inline'&&($post['text']??'')!==Crolord_Publisher_Platforms::text($payload)){throw new RuntimeException('Buffer 已发正文与文章标题／链接不一致，保留待核对。');}
        }
        $assets=$root['assets']??null;
        $seen=is_array($assets)?count($assets):null;
        if(!is_array($assets)||count($assets)!==$expected){throw new RuntimeException('Buffer 已发送，但媒体回执数量与计划 '.$expected.' 张不一致；保留原 ID，未重发。');}
        // Asset.id is nullable in Buffer's public schema. Use its required type
        // discriminator, never optional database IDs or a narrow MIME allowlist.
        foreach($assets as$asset){if(!is_array($asset)||($asset['type']??'')!=='image'){throw new RuntimeException('Buffer 已发回执中的素材类型不是图片，保留待核对。');}}
        return count($assets);
    }
    public static function recheck_media_receipts() {
        global $wpdb;$table=Crolord_Publisher_Store::table();
        $message='Buffer 媒体回执缺少真实图片标识，保留待核对。';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE platform='x' AND runner='php' AND state='external_paused' AND error_text=%s ORDER BY id DESC LIMIT 50",$message),ARRAY_A);
        $count=0;
        foreach(is_array($rows)?$rows:array()as$job){
            $progress=json_decode($job['progress'],true)?:array();$p=json_decode($job['payload'],true)?:array();
            if($job['platform']!=='x'||($job['runner']??'')!=='php'||$job['state']!=='external_paused'||$job['error_text']!==$message||!self::valid_id($progress['buffer']['id']??null)||($p['x_route']??'')!=='buffer'){continue;}
            // Schedule a read query of the same provider record, never a mutation.
            unset($progress['buffer']['retry_requested']);$progress['buffer']['checks']=0;$progress['buffer']['verification_upgrade']=CROLORD_PUBLISHER_VERSION;
            $changed=$wpdb->update($table,array('state'=>'external_pending','available'=>time(),'lease'=>'','error_text'=>'','progress'=>wp_json_encode($progress),'touched'=>time()),array('id'=>$job['id'],'state'=>'external_paused','lease'=>$job['lease'],'progress'=>$job['progress'],'remote_ids'=>$job['remote_ids'],'error_text'=>$message));
            if($changed){$count++;}
        }
        if($count){add_action('shutdown',array('Crolord_Publisher','kick'));}
        return $count;
    }
    public static function boot() {
        add_action('admin_post_crolord_publisher_buffer', array(__CLASS__, 'save'));
        add_action('wp_ajax_crolord_publisher_buffer_channels', array(__CLASS__, 'channels'));
        add_action('crolord_publisher_buffer_cleanup', array(__CLASS__, 'cleanup'));
    }
    private static function error($code, $message, $delay = 0) { return new WP_Error($code, $message, array('stage'=>'Buffer 同步','delay'=>$delay)); }
    private static function valid_id($id) { return is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id); }
    private static function key($config = null) {
        if ($config === null) { $config = self::config(); }
        return Crolord_Publisher_Store::decrypt($config['key'] ?? '');
    }
    private static function safe_message($message, $key) {
        if (!is_string($message)) { return ''; }
        $message = str_replace($key, '[已隐藏]', $message);
        $message = preg_replace('/(?:Bearer\s+|buf_)[A-Za-z0-9_.:=+\/-]{8,}/i', '[已隐藏]', $message);
        $message=wp_strip_all_tags($message);
        return function_exists('mb_substr')?mb_substr($message,0,500,'UTF-8'):substr($message,0,500);
    }
    public static function request($query, $variables, $key, $writing = false) {
        if (!$key) { return self::error('config', '请先在 Publisher 设置中连接 Buffer，并选择自己的 X 账号。'); }
        $fingerprint=hash_hmac('sha256',$key,wp_salt('nonce'));$cooldown=get_option('crolord_publisher_buffer_rate',array());
        if(($cooldown['key']??'')===$fingerprint&&(int)($cooldown['until']??0)>time())return self::error('rejected','Buffer 用量窗口尚未重置，请在等待结束后重试。',(int)$cooldown['until']-time());
        $response = wp_remote_post('https://api.buffer.com', array('timeout'=>20,'redirection'=>0,'limit_response_size'=>131072,'headers'=>array('Content-Type'=>'application/json','Authorization'=>'Bearer '.$key),'body'=>wp_json_encode(array('query'=>$query,'variables'=>(object)$variables))));
        if (is_wp_error($response)) { return self::error($writing?'unknown':'transport', $writing?'Buffer 请求中断，可能已经接收。请先核对 Buffer，未自动重发。':'无法读取 Buffer，请检查服务器到 api.buffer.com 的连接。'); }
        $http = (int) wp_remote_retrieve_response_code($response); $body = json_decode(wp_remote_retrieve_body($response), true);
        $wait=max(0,(int)wp_remote_retrieve_header($response,'retry-after'));
        $rate=wp_remote_retrieve_header($response,'ratelimit');if(is_array($rate))$rate=implode(',',$rate);
        if(is_string($rate)&&preg_match_all('/;\s*r=0\s*;\s*t=(\d+)/',$rate,$matches))$wait=max($wait,max(array_map('intval',$matches[1])));
        if($http===429)$wait=max(60,$wait);
        if($wait>0){$wait=min(31*DAY_IN_SECONDS,$wait);update_option('crolord_publisher_buffer_rate',array('key'=>$fingerprint,'until'=>time()+$wait),false);}
        if ($http < 200 || $http >= 300 || !is_array($body)) {
            $unknown = $writing && ($http >= 500 || $http === 408 || ($http >= 200 && $http < 300));
            $message = 'Buffer 请求未通过，请检查服务状态。';
            if ($http === 401 || $http === 403) { $message = 'Buffer Key 无效或没有对应频道权限，请重新连接。'; }
            if ($http === 402) { $message = 'Buffer 套餐或用量限制，请在 Buffer 账户检查；此请求没有使用本站的 X API Credits。'; }
            if ($http === 429) { $message = 'Buffer 请求限流，请等待后重试。'; }
            $delay = $http===429?$wait:0;
            return self::error($unknown?'unknown':'rejected', 'Buffer · HTTP '.$http.'：'.$message, $delay);
        }
        // A partial mutation can include a real ID. Return it for checkpointing before inspecting errors.
        if ($writing && (!empty($body['data']['createPost']['post']['id']) || !empty($body['data']['editPost']['post']['id']))) { return $body['data']; }
        if (!empty($body['errors'])) {
            $code = $body['errors'][0]['extensions']['code'] ?? '';
            $definite = array('UNAUTHORIZED','FORBIDDEN','NOT_FOUND','RATE_LIMIT_EXCEEDED','GRAPHQL_VALIDATION_FAILED','BAD_USER_INPUT');
            $message = 'Buffer 返回异常，请检查频道和服务状态。';
            if ($code==='UNAUTHORIZED'||$code==='FORBIDDEN') { $message='Buffer Key 无效或没有此频道权限，请重新保存连接。'; }
            if ($code==='RATE_LIMIT_EXCEEDED') { $message='Buffer API 请求额度或限流已达，请在 Buffer 检查用量后重试。'; }
            if ($code==='NOT_FOUND') { $message='Buffer 中找不到对应频道或帖子，请在 Buffer 核对。'; }
            if ($code==='GRAPHQL_VALIDATION_FAILED'||$code==='BAD_USER_INPUT') { $message='Buffer 未接受请求字段，请核对插件版本和平台当前接口。'; }
            if($code==='RATE_LIMIT_EXCEEDED'&&!$wait){$wait=300;update_option('crolord_publisher_buffer_rate',array('key'=>$fingerprint,'until'=>time()+$wait),false);}
            return self::error($writing&&!in_array($code,$definite,true)?'unknown':'rejected', $message, $code==='RATE_LIMIT_EXCEEDED'?$wait:0);
        }
        if (!isset($body['data']) || !is_array($body['data'])) { return self::error($writing?'unknown':'rejected', 'Buffer 未返回有效结果，请先核对平台；未自动重复创建。'); }
        return $body['data'];
    }
    public static function channel($id, $key) {
        if (!self::valid_id($id)) { return self::error('config','请选择 Buffer 中已连接的 X 频道。'); }
        $data = self::request('query PublisherChannel($id: ChannelId!) { channel(input: { id: $id }) { '.self::CHANNEL_FIELDS.' } }', array('id'=>$id), $key);
        if (is_wp_error($data)) { return $data; }
        $channel = $data['channel'] ?? array();
        if (($channel['id']??'') !== $id || ($channel['service']??'') !== 'twitter' || !preg_match('/^[0-9]{1,20}$/D',(string)($channel['serviceId']??''))) { return self::error('identity','请选择真实的 X 频道；未将其他平台当作 X。'); }
        if (!empty($channel['isDisconnected']) || !empty($channel['isLocked']) || !empty($channel['isQueuePaused'])) { return self::error('channel','Buffer 的 X 连接已断开、被锁定或队列已暂停，请在 Buffer 修复后重试。'); }
        if (!in_array('scheduleUpdates',$channel['allowedActions']??array(),true)) { return self::error('permission','当前 Buffer 用户没有此频道的发布权限。'); }
        return $channel;
    }
    public static function discover($key) {
        $data = self::request('query PublisherOrganizations { account { organizations { id name } } }', array(), $key);
        if (is_wp_error($data)) { return $data; }
        $result = array();
        foreach (array_slice($data['account']['organizations']??array(),0,5) as $organization) {
            $id=$organization['id']??''; if(!self::valid_id($id))continue;
            $data=self::request('query PublisherChannels($id: OrganizationId!) { channels(input: { organizationId: $id }) { '.self::CHANNEL_FIELDS.' } }',array('id'=>$id),$key);
            if(is_wp_error($data))return $data;
            foreach(array_slice($data['channels']??array(),0,100) as $channel){
                if(($channel['service']??'')!=='twitter'||!self::valid_id($channel['id']??null))continue;
                $result[]=array('id'=>$channel['id'],'name'=>sanitize_text_field($channel['name']??''),'uid'=>(string)($channel['serviceId']??''),'organization'=>sanitize_text_field($organization['name']??''),'unavailable'=>!empty($channel['isDisconnected'])||!empty($channel['isLocked'])||!empty($channel['isQueuePaused']));
            }
        }
        return $result;
    }
    public static function channels() {
        check_ajax_referer('crolord_publisher_ajax','nonce');
        if(!current_user_can('manage_options')||!current_user_can('crolord_publish_social'))wp_send_json_error(array('message'=>'没有权限。'),403);
        $key=trim(wp_unslash($_POST['key']??'')); if(!$key)$key=self::key();
        $channels=self::discover($key);
        if(is_wp_error($channels))wp_send_json_error(array('message'=>$channels->get_error_message()),400);
        wp_send_json_success(array('channels'=>$channels,'message'=>$channels?'请选择自己的 X 账号，再保存连接。':'没有发现 X 频道，请先在 Buffer 连接 X。'));
    }
    public static function configure($input) {
        return Crolord_Publisher_Store::locked(function() use($input){
            $old=self::config();$key=trim((string)($input['cp_buffer_key']??''));if(!$key)$key=self::key($old);
            if(!preg_match('/^[\x21-\x7e]{8,4096}$/D',$key))return self::error('key','请填写有效的 Buffer API Key。');
            $channel=self::channel((string)($input['cp_buffer_channel']??''),$key);if(is_wp_error($channel))return $channel;
            $all=get_option('crolord_publisher_accounts',array());$uid=(string)$channel['serviceId'];
            if(!empty($all['x']['uid'])&&$all['x']['uid']!==$uid)return self::error('identity','此 Buffer 频道与原 X UID 不一致，未切换，也不会将旧任务发到另一个账号。');
            $config=array('key'=>Crolord_Publisher_Store::encrypt($key),'channel_id'=>$channel['id'],'name'=>sanitize_text_field($channel['name']),'uid'=>$uid,'organization_id'=>$channel['organizationId'],'verified_at'=>time());
            if(!update_option(self::OPTION,$config,false)&&$old!==$config)return self::error('storage','Buffer 连接保存失败，请重试。');
            $all['x']['uid']=$uid;update_option('crolord_publisher_accounts',$all,false);
            update_option('crolord_publisher_x_route','buffer',false);
            $migrated=!empty($input['cp_buffer_migrate'])?self::migrate($config):0;
            return $migrated;
        });
    }
    public static function save() {
        if(!current_user_can('manage_options')||!current_user_can('crolord_publish_social'))wp_die('没有权限。','',array('response'=>403));
        check_admin_referer('cp_buffer');
        try{$result=self::configure(wp_unslash($_POST));}catch(Throwable $e){$result=self::error('storage','Buffer 配置未保存，请检查 OpenSSL 和数据库写入。');}
        set_transient('crolord_publisher_buffer_notice_'.get_current_user_id(),is_wp_error($result)?$result->get_error_message():((int)$result?'已保存 Buffer 连接，并迁移 '.(int)$result.' 条尚未发出的旧任务。':'Buffer 连接已保存。'),120);
        wp_safe_redirect(admin_url('options-general.php?page=crolord-publisher'));exit;
    }
    public static function migrate($config) {
        global $wpdb;$last=0;$count=0;$table=Crolord_Publisher_Store::table();
        do{
            $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE platform='x' AND state IN ('queued','scheduled','failed','cancelled') AND id>%d ORDER BY id LIMIT 100",$last),ARRAY_A);
            foreach($rows as $job){
                $last=(int)$job['id'];$payload=json_decode($job['payload'],true);$progress=json_decode($job['progress'],true)?:array();
                if(!current_user_can('edit_post',$job['post_id'])||json_decode($job['remote_ids'],true)||!empty($progress['buffer']['id'])||!is_array($payload)||!empty($payload['account'])&&$payload['account']!==$config['uid'])continue;
                if (($payload['x_route']??'')==='native'||!empty($payload['place_id'])) continue; // Native tags cannot be silently dropped.
                $payload['format']=3;
                $payload['account']=$config['uid'];$payload['x_route']='buffer';$payload['buffer_channel']=$config['channel_id'];
                $at=max(time(),(int)($payload['not_before']??0),(int)($progress['retry_after']??0));
                $valid=Crolord_Publisher_Platforms::validate('x',$payload);
                $state=$job['state']==='cancelled'?'cancelled':(is_wp_error($valid)?'failed':($at>time()?'scheduled':'queued'));
                $kept=isset($progress['retry_after'])?array('retry_after'=>(int)$progress['retry_after']):new stdClass();
                $values=array('payload'=>wp_json_encode($payload),'progress'=>wp_json_encode($kept),'state'=>$state,'error_text'=>is_wp_error($valid)?$valid->get_error_message():'','lease'=>'','available'=>$at,'touched'=>time());
                $changed=$wpdb->update($table,$values,array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease']));
                if($changed){$count++;Crolord_Publisher::due($job['id'],$at);}
            }
        }while(count($rows)===100);
        if($count)add_action('shutdown',array('Crolord_Publisher','kick'));
        return $count;
    }
    /** Export immutable selected originals; Buffer fetches these and uploads real media to X. */
    public static function assets($job, $payload) {
        if(empty($payload['images'])){return array();}
        $uploads=wp_get_upload_dir();$directory=$uploads['basedir'].'/crolord-publisher-media/'.(int)$job['id'];
        if(!wp_mkdir_p($directory)||is_link($directory)||is_link(dirname($directory)))throw new RuntimeException('无法准备 Buffer 图片，请检查 uploads 写入权限。');
        if(!is_file($directory.'/index.html'))file_put_contents($directory.'/index.html','');
        $assets=array();
        foreach($payload['images'] as $index=>$image){
            $source=Crolord_Publisher_Platforms::image($image);$name=($index+1).'-'.$image['sha256'].(wp_get_image_mime($source)==='image/png'?'.png':'.jpg');$file=$directory.'/'.$name;
            if(!is_file($file)||hash_file('sha256',$file)!==$image['sha256']){
                $tmp=tempnam($directory,'cp-');
                if(!$tmp||!copy($source,$tmp)||hash_file('sha256',$tmp)!==$image['sha256']||!rename($tmp,$file)){if($tmp&&is_file($tmp))unlink($tmp);throw new RuntimeException('无法保存原图快照，未提交到 Buffer。');}
                chmod($file,0644);
            }
            $url=$uploads['baseurl'].'/crolord-publisher-media/'.(int)$job['id'].'/'.$name;
            $local=wp_get_environment_type()==='local'&&in_array(wp_parse_url($url,PHP_URL_HOST),array('127.0.0.1','localhost'),true);
            if(wp_parse_url($url,PHP_URL_SCHEME)!=='https'&&!$local)throw new RuntimeException('Buffer 图片需要可公开访问的 HTTPS 地址，请检查 WordPress uploads 地址。');
            $assets[]=array('image'=>array('url'=>$url));
        }
        if(!wp_next_scheduled('crolord_publisher_buffer_cleanup',array((int)$job['id'])))wp_schedule_single_event(time()+7*DAY_IN_SECONDS,'crolord_publisher_buffer_cleanup',array((int)$job['id']));
        return $assets;
    }
    public static function cleanup($id) {
        $job=Crolord_Publisher_Store::get($id);
        if($job&&!in_array($job['state'],array('succeeded','cancelled','failed'),true)){wp_schedule_single_event(time()+DAY_IN_SECONDS,'crolord_publisher_buffer_cleanup',array((int)$id));return;}
        $uploads=wp_get_upload_dir();$directory=$uploads['basedir'].'/crolord-publisher-media/'.absint($id);
        if(!is_dir($directory)||is_link($directory)||is_link(dirname($directory)))return;
        foreach(glob($directory.'/*')?:array() as $file){if(is_file($file)&&!is_link($file)&&(basename($file)==='index.html'||preg_match('/^[1-4]-[a-f0-9]{64}\.(png|jpg)$/D',basename($file))))unlink($file);}
        @rmdir($directory);
    }
    private static function persist($job,$progress,$state,$message='',$delay=0,$ids=null) {
        if($state==='failed'&&$delay>0)$progress['retry_after']=time()+$delay;
        $values=array('progress'=>wp_json_encode($progress),'state'=>$state,'lease'=>'','error_text'=>$message,'available'=>time()+$delay);
        if($ids!==null)$values['remote_ids']=wp_json_encode($ids);
        Crolord_Publisher_Store::write($job,$values);
        if($state==='external_pending')Crolord_Publisher::due($job['id'],time()+$delay);
    }
    private static function next_check($job,$progress,$message='',$minimum=0) {
        $checks=(int)($progress['buffer']['checks']??0)+1;$progress['buffer']['checks']=$checks;
        $delays=array(60,120,300,900,1800,3600,3600,3600);
        if($checks>count($delays)){self::persist($job,$progress,'external_paused','Buffer 已接收，状态尚未确认。自动查询已暂停以节省请求，请手动检查同一任务。');return;}
        self::persist($job,$progress,'external_pending',$message,max($minimum,$delays[$checks-1]));
    }
    private static function receive($job,$payload,$progress,$post,$key) {
        if(($post['id']??'')!==($progress['buffer']['id']??'')||($post['channelId']??'')!==$payload['buffer_channel']||($post['channel']['service']??'')!=='twitter'||(string)($post['channel']['serviceId']??'')!==$payload['account']){
            self::persist($job,$progress,'external_paused','Buffer 回执与原任务账号/频道不一致，已停止操作，请核对。');return;
        }
        $status=$post['status']??'';$progress['buffer']['status']=$status;
        if($status==='sent'&&($post['schedulingType']??'')==='automatic'){
            $url=$post['externalLink']??'';$parts=is_string($url)?wp_parse_url($url):false;
            if($parts&&in_array(strtolower($parts['host']??''),array('x.com','www.x.com','twitter.com','www.twitter.com'),true)&&($parts['scheme']??'')==='https'&&preg_match('~/status/([0-9]{1,24})(?:/|$)~',$parts['path']??'',$match)){
                $previous=json_decode($job['remote_ids'],true)?:array();
                if($previous&&(string)$previous[0]!==$match[1]){self::persist($job,$progress,'external_paused','Buffer 回执中的 X 主帖 ID 与已保存记录不同；原 ID 保留，未覆盖或重发。');return;}
                $progress['buffer']['external_link']='https://x.com/i/status/'.$match[1];
                $seen=null;
                try{$progress['buffer_media']['receipt']=self::verify_media($payload,$post,$seen);$progress['buffer_media']['source']='Buffer sent receipt';$progress['buffer_media']['verified']=true;}
                catch(Throwable $e){if($seen!==null){$progress['buffer_media']['receipt']=$seen;}$progress['buffer_media']['verified']=false;self::persist($job,$progress,'external_paused',$e->getMessage(),0,array($match[1]));return;}
                if(Crolord_Publisher_Platforms::card($payload)){$progress['buffer_card']=array('link_in_body'=>true,'rendering'=>'platform');}
                self::persist($job,$progress,'succeeded','',0,array($match[1]));return;
            }
            self::next_check($job,$progress,'Buffer 报告已发送，正在等待 X 帖子链接；未重复提交。');return;
        }
        if($status==='error'){
            $reason=self::safe_message($post['error']['message']??'',$key);
            if(Crolord_Publisher_Platforms::link_last($payload)){
                $known=null;$parts=wp_parse_url((string)($post['externalLink']??''));
                if(is_array($parts)&&($parts['scheme']??'')==='https'&&in_array(strtolower($parts['host']??''),array('x.com','www.x.com','twitter.com','www.twitter.com'),true)&&preg_match('~/status/([0-9]{1,24})(?:/|$)~',$parts['path']??'',$match)){$old=json_decode($job['remote_ids'],true)?:array();$known=$old?:array($match[1]);}
                self::persist($job,$progress,'external_paused','Buffer 串帖未完整结束，请检查同一 Buffer 记录。主帖可能已经发布，未重新创建或重发：'.($reason?:'请核对主帖与最后的链接回复。'),0,$known);return;
            }
            self::persist($job,$progress,'external_failed','Buffer 发布失败：'.($reason?:'请在 Buffer 修复 X 连接或媒体后重试。'));return;
        }
        if(!in_array($status,array('scheduled','sending'),true)||($post['schedulingType']??'')!=='automatic'){
            self::persist($job,$progress,'external_paused','Buffer 内容尚未自动发布（'.$status.'），请在 Buffer 检查审核、草稿或通知发布设置。');return;
        }
        self::next_check($job,$progress);
    }
    public static function step($job,$payload,$progress) {
        $config=self::config();$key=self::key($config);
        if(!empty($progress['buffer']['id'])){
            $id=$progress['buffer']['id'];
            $data=self::request('query PublisherPost($id: PostId!) { post(input: { id: $id }) { '.self::fields($payload).' } }',array('id'=>$id),$key);
            if(is_wp_error($data)){self::next_check($job,$progress,$data->get_error_message(),(int)($data->get_error_data()['delay']??0));return;}
            $post=$data['post']??array();
            if(!empty($progress['buffer']['retry_requested'])&&($post['status']??'')==='error'){
                unset($progress['buffer']['retry_requested']);
                if(($post['channelId']??'')!==$payload['buffer_channel']||(string)($post['channel']['serviceId']??'')!==$payload['account']||!in_array('publishPostNow',$post['allowedActions']??array(),true)){self::persist($job,$progress,'external_failed','Buffer 当前不允许重试此帖，请在 Buffer 修复后重试；未新建帖子。');return;}
                Crolord_Publisher_Store::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));
                $data=self::request('mutation PublisherRetry($input: EditPostInput!) { editPost(input: $input) { __typename ... on PostActionSuccess { post { '.self::fields($payload).' } } ... on MutationError { message } } }',array('input'=>array('id'=>$id,'mode'=>'shareNow','schedulingType'=>'automatic','saveToDraft'=>false)),$key,true);
                if(is_wp_error($data)){self::next_check($job,$progress,'同一 Buffer 帖的重试结果尚待确认，未新建帖子。');return;}
                $post=$data['editPost']['post']??array();
                if(!$post){self::persist($job,$progress,'external_failed','Buffer 未接受重试：'.self::safe_message($data['editPost']['message']??'请在 Buffer 查看。',$key));return;}
            }
            unset($progress['buffer']['retry_requested']);self::receive($job,$payload,$progress,$post,$key);return;
        }
        if(Crolord_Publisher_Platforms::card($payload)&&!Crolord_Publisher_Platforms::main_card($payload)){
            self::persist($job,$progress,'failed','这是旧版 X 网页卡片任务，没有正文原图附件。请在文章保存新版设置后，使用“采用当前设置并重试”；未发送旧卡片，也未重发已有帖子。');return;
        }
        if(empty($config['channel_id'])||($payload['buffer_channel']??'')!==$config['channel_id']||$payload['account']!==($config['uid']??''))throw new RuntimeException('Buffer 连接与原任务不一致，请先连接原账号；未发送到其他账号。');
        $channel=self::channel($payload['buffer_channel'],$key);if(is_wp_error($channel)){self::persist($job,$progress,'failed',$channel->get_error_message(),(int)($channel->get_error_data()['delay']??0));return;}
        if((string)$channel['serviceId']!==$payload['account'])throw new RuntimeException('Buffer X UID 与任务不一致，已停止同步。');
        $input=array('channelId'=>$payload['buffer_channel'],'text'=>Crolord_Publisher_Platforms::main_text($payload),'schedulingType'=>'automatic','mode'=>'shareNow','saveToDraft'=>false,'needsApproval'=>false,'assets'=>self::assets($job,$payload),'source'=>'crolord-publisher');
        $progress['buffer_media']=array('prepared'=>count($input['assets']),'submitted'=>count($input['assets']));
        if(Crolord_Publisher_Platforms::link_last($payload)){
            $input['metadata']=array('twitter'=>array('thread'=>array(array('text'=>$input['text'],'assets'=>$input['assets']),array('text'=>$payload['url'],'assets'=>array()))));
            unset($input['assets']);
        }elseif(!$input['assets']&&!Crolord_Publisher_Platforms::main_card($payload)){unset($input['assets']);}
        Crolord_Publisher_Store::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));
        $data=self::request('mutation PublisherCreate($input: CreatePostInput!) { createPost(input: $input) { __typename ... on PostActionSuccess { post { '.self::fields($payload).' } } ... on MutationError { message } } }',array('input'=>$input),$key,true);
        if(is_wp_error($data)){self::persist($job,$progress,$data->get_error_code()==='unknown'?'unknown':'failed',$data->get_error_message(),(int)($data->get_error_data()['delay']??0));return;}
        $result=$data['createPost']??array();$post=$result['post']??array();
        if(!self::valid_id($post['id']??null)){
            $known=!empty($result['__typename'])&&$result['__typename']!=='PostActionSuccess';
            self::persist($job,$progress,$known?'failed':'unknown',$known?'Buffer 未接收：'.self::safe_message($result['message']??'请检查频道、图片或套餐限制。',$key):'Buffer 未返回帖子 ID，请先核对平台；未自动重发。');return;
        }
        $progress['buffer']=array('id'=>$post['id'],'checks'=>0,'created_at'=>time(),'status'=>$post['status']??'');
        // Checkpoint the provider ID before further actions; a queued provider post is not X success.
        Crolord_Publisher_Store::write($job,array('state'=>'external_pending','progress'=>wp_json_encode($progress)));
        self::receive($job,$payload,$progress,$post,$key);
    }
    public static function resume($job,$retry=false) {
        global $wpdb;$progress=json_decode($job['progress'],true)?:array();
        if(empty($progress['buffer']['id'])||!in_array($job['state'],array('external_failed','external_paused','external_pending','unknown','failed','queued'),true))return 0;
        if($retry&&$job['state']!=='external_failed')return 0;
        if((int)($progress['buffer']['last_manual_check']??0)>time()-30)return 0;
        $progress['buffer']['last_manual_check']=time();$progress['buffer']['checks']=0;
        if($retry)$progress['buffer']['retry_requested']=true;
        return $wpdb->update(Crolord_Publisher_Store::table(),array('state'=>'external_pending','progress'=>wp_json_encode($progress),'available'=>time(),'error_text'=>'','lease'=>'','touched'=>time()),array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease']));
    }
    public static function render() {
        $config=self::config();$key='crolord_publisher_buffer_notice_'.get_current_user_id();$notice=get_transient($key);delete_transient($key);
        if($notice)echo '<p role="status" class="cp-oauth-notice">'.esc_html($notice).'</p>';
        echo '<section class="cp-buffer"><h2>X</h2><p>先在 <a href="https://buffer.com/" target="_blank" rel="noopener noreferrer">Buffer</a> 连接 X，再从 <a href="https://publish.buffer.com/settings/api" target="_blank" rel="noopener noreferrer">Settings → API</a> 创建 Key。</p><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="crolord_publisher_buffer">';
        wp_nonce_field('cp_buffer');
        echo '<label>Buffer API Key<input name="cp_buffer_key" type="password" autocomplete="new-password" value="" placeholder="'.(!empty($config['key'])?'已加密保存；留空保持':'仅在自己的后台填写').'" class="cp-buffer-key"></label><button type="button" class="button cp-buffer-load">读取已连接的 X 账号</button><label>X 账号<select name="cp_buffer_channel" class="cp-buffer-channel">';
        if(!empty($config['channel_id']))echo '<option value="'.esc_attr($config['channel_id']).'">'.esc_html($config['name'].' · UID '.$config['uid']).'</option>';else echo '<option value="">请先读取账号</option>';
        echo '</select></label><p class="cp-buffer-result" role="status"></p>';
        submit_button('保存并使用 Buffer','secondary');echo '</form></section>';
    }
}
