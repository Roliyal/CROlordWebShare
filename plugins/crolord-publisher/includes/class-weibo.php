<?php
if (!defined('ABSPATH')) { exit; }

final class Crolord_Publisher_Weibo {
    const SOURCE='339644097';
    const STATEMENTS=array(0=>'未声明',1=>'AI 生成',2=>'虚构演绎',3=>'转载',4=>'自主创作');
    public static function statement($value) {
        // Do not coerce unknown, boolean or malformed choices into no declaration.
        if((!is_int($value)&&!is_string($value))||!preg_match('/^[0-4]$/D',(string)$value)){
            throw new RuntimeException('微博内容声明选项无效，请重新选择并保存文章；未改成“未声明”。');
        }
        return (int)$value;
    }
    public static function web($p) { return ($p['weibo_route']??'')==='web'; }
    public static function video_snapshot($id,$owner,$settings=null) {
        if (!$id) { return null; }
        if (!user_can($owner,'edit_post',$id)||get_post_type($id)!=='attachment') { throw new RuntimeException('没有微博视频的使用权限。'); }
        $file=Crolord_Publisher_Media::local($id);$header=file_get_contents($file,false,null,0,12);
        if (wp_check_filetype_and_ext($file,basename($file))['type']!=='video/mp4'||substr($header,4,4)!=='ftyp') { throw new RuntimeException('微博视频需要本地媒体库中的 MP4 文件。'); }
        $c=$settings??Crolord_Publisher_Weibo_Browser::config();$size=filesize($file);
        if (!$size||$size>(int)$c['video_mb']*1024*1024) { throw new RuntimeException('视频超过后台设置的大小上限，或文件为空。'); }
        return array('id'=>(int)$id,'size'=>$size,'sha256'=>hash_file('sha256',$file));
    }
    public static function validate($p) {
        try{self::statement($p['weibo_statement']??0);}catch(Throwable $e){return new WP_Error('weibo_statement',$e->getMessage());}
        $c=Crolord_Publisher_Weibo_Session::config();
        if (!$c['session']||!$c['verified']||!$c['uid']||empty($p['account'])) { return new WP_Error('weibo_auth','请先在 Publisher 后台扫码连接微博，再采用当前设置建立任务。'); }
        if ((string)$c['uid']!==(string)$p['account']) { return new WP_Error('weibo_account','微博账号已改变，旧任务不会改绑新账号。'); }
        if (empty($p['title'])||empty($p['url'])||!preg_match('~^https://~i',$p['url'])) { return new WP_Error('weibo_text','微博需要主标题和本篇公开文章的 HTTPS 网址。'); }
        if (strlen(Crolord_Publisher_Platforms::text($p))>6000) { return new WP_Error('weibo_text','微博标题与网址过长，请缩短分享标题。'); }
        if (($p['weibo_format']??'images')==='video') {
            if (empty($p['weibo_video'])||!empty($p['images'])) { return new WP_Error('weibo_media','微博视频必须单独发布，不能与图片混选。'); }
            preg_match_all('/./us',($p['weibo_video_title']??'')?:$p['title'],$characters);if(count($characters[0])>30){return new WP_Error('weibo_title','微博视频标题请控制在 30 字以内。可单独填写视频标题，完整分享标题仍保留在正文中。');}
        } elseif ((empty($p['images'])&&empty($p['allow_empty_media']))||count($p['images'])>9) { return new WP_Error('weibo_media','微博请选择 1–9 张图片。'); }
        return true;
    }
    public static function query($base,$params) { return $base.'?'.http_build_query($params,'','&',PHP_QUERY_RFC3986); }
    public static function file($media,$video=false) {
        if (!user_can($GLOBALS['cp_weibo_owner']??0,'edit_post',$media['id'])) { throw new RuntimeException('发布者的媒体权限已撤销。'); }
        $file=Crolord_Publisher_Media::local($media['id']);
        if (!hash_equals($media['sha256'],hash_file('sha256',$file))) { throw new RuntimeException('媒体在排队后发生变化，已停止以免错发。'); }
        $c=Crolord_Publisher_Weibo_Session::config();
        if (!filesize($file)||filesize($file)>(int)$c[$video?'video_mb':'image_mb']*1024*1024) { throw new RuntimeException('媒体超过当前设置的大小上限。'); }
        if (!$video&&!in_array(wp_get_image_mime($file),array('image/jpeg','image/png'),true)) { throw new RuntimeException('微博图片需要 JPEG 或 PNG。'); }
        if($video&&(wp_check_filetype_and_ext($file,basename($file))['type']!=='video/mp4'||substr(file_get_contents($file,false,null,0,12),4,4)!=='ftyp')){throw new RuntimeException('视频内容或格式已改变，未上传。');}
        return $file;
    }
    public static function upload_image($media,$identity,&$jar) {
        $file=self::file($media);$url=self::query('https://picupload.weibo.com/interface/upload.php',array('file_source'=>1,'cs'=>sprintf('%u',hexdec(hash_file('crc32b',$file))),'ent'=>'miniblog','appid'=>self::SOURCE,'uid'=>$identity['uid'],'raw_md5'=>md5_file($file),'ori'=>1,'mpos'=>1,'nick'=>$identity['name'],'pri'=>0,'request_id'=>(string)round(microtime(true)*1000),'file_size'=>filesize($file)));
        $d=Crolord_Publisher_Weibo_Session::request($url,'POST',file_get_contents($file),$jar,'上传微博图片','application/octet-stream');
        Crolord_Publisher_Weibo_Session::accepted($d,'上传微博图片');$pid=$d['pic']['pid']??'';
        if (!is_string($pid)||!preg_match('/^[a-zA-Z0-9_-]{5,160}$/D',$pid)) { throw new RuntimeException('图片上传未返回有效图片 ID，未发表微博。'); }
        return array('pid'=>$pid,'type'=>wp_get_image_mime($file));
    }
    public static function video_init($file,&$jar) {
        $boundary='Crolord'.bin2hex(random_bytes(12));$body='--'.$boundary."\r\nContent-Disposition: form-data; name=\"biz_file\"\r\n\r\n".wp_json_encode(array('mediaprops'=>wp_json_encode(array('screenshot'=>1))))."\r\n--".$boundary."--\r\n";
        $params=array('source'=>self::SOURCE,'size'=>filesize($file),'name'=>'video.mp4','type'=>'video','client'=>'web','session_id'=>bin2hex(random_bytes(16)));
        $d=Crolord_Publisher_Weibo_Session::request(self::query('https://fileplatform.api.weibo.com/2/fileplatform/init.json',$params),'POST',$body,$jar,'建立微博视频上传','multipart/mixed; boundary='.$boundary);
        Crolord_Publisher_Weibo_Session::accepted($d,'建立微博视频上传');
        foreach(array('upload_id','media_id','auth')as$key){if(!is_scalar($d[$key]??null)||!strlen((string)$d[$key])||strlen((string)$d[$key])>4096){throw new RuntimeException('微博未返回完整的视频上传凭据，未开始发表。');}}
        if(!preg_match('/^[A-Za-z0-9_:.-]{1,160}$/D',(string)$d['media_id'])||!preg_match('/^[A-Za-z0-9_-]{1,160}$/D',(string)$d['upload_id'])||preg_match('/[\x00-\x1f\x7f]/',(string)$d['auth'])){throw new RuntimeException('微博视频上传标识无效。');}
        return array('upload_id'=>(string)$d['upload_id'],'media_id'=>(string)$d['media_id'],'auth'=>Crolord_Publisher_Store::encrypt((string)$d['auth']),'started'=>time(),'stage'=>'upload');
    }
    public static function binary_video($file,$v) {
        $url=self::query('https://up.video.weibocdn.com/2/fileplatform/upload.json',array('source'=>self::SOURCE,'upload_id'=>$v['upload_id'],'media_id'=>$v['media_id'],'upload_protocol'=>'binary','type'=>'video','client'=>'web','check'=>md5_file($file),'index'=>0,'size'=>filesize($file),'start_loc'=>0,'count'=>1));
        $auth=Crolord_Publisher_Store::decrypt($v['auth']);if(!$auth){throw new RuntimeException('视频上传凭据无法解密，请重新建立上传。');}
        $headers=array('Content-Type: application/octet-stream','Content-Length: '.filesize($file),'Origin: https://weibo.com','Referer: https://weibo.com/','X-Up-Auth: '.$auth,'Expect:');
        // Tests may replace this binary transport. Production uses a bounded file stream.
        $mock=apply_filters('crolord_publisher_weibo_binary_response',null,$url,$file,$headers);
        if ($mock!==null) { if(is_wp_error($mock)){throw new RuntimeException('视频上传连接未完成，未发表微博。');}return Crolord_Publisher_Weibo_Session::accepted($mock,'上传微博视频'); }
        if(!function_exists('curl_init')){throw new RuntimeException('视频上传需要 PHP cURL 扩展；无需安装 Mac 执行器。');}
        $handle=fopen($file,'rb');$curl=curl_init($url);
        if(!$handle||!$curl){if($handle){fclose($handle);}throw new RuntimeException('无法打开本地视频流。');}
        $options=array(CURLOPT_UPLOAD=>true,CURLOPT_CUSTOMREQUEST=>'POST',CURLOPT_INFILE=>$handle,CURLOPT_INFILESIZE=>filesize($file),CURLOPT_HTTPHEADER=>$headers,CURLOPT_USERAGENT=>Crolord_Publisher_Weibo_Session::UA,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>110,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_WRITEFUNCTION=>function($ch,$bytes)use(&$response){if(strlen($response??'')+strlen($bytes)>1024*1024){return 0;}$response=($response??'').$bytes;return strlen($bytes);});
        if(defined('CURLOPT_PROTOCOLS')){$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTPS;}
        $response='';curl_setopt_array($curl,$options);
        try{$ok=curl_exec($curl);$http=(int)curl_getinfo($curl,CURLINFO_HTTP_CODE);if($ok===false||$http<200||$http>=300){throw new RuntimeException('视频上传 · HTTP '.$http.'：连接未完成或平台拒绝，未执行微博发表。');}return Crolord_Publisher_Weibo_Session::accepted(Crolord_Publisher_Weibo_Session::json($response),'上传微博视频');}
        finally{curl_close($curl);fclose($handle);}
    }
    public static function checkpoint($job,$progress,$delay=0,$extra=array()) {
        Crolord_Publisher_Store::write($job,array_merge(array('state'=>$delay?'external_pending':'queued','lease'=>'','progress'=>wp_json_encode($progress),'error_text'=>'','available'=>time()+$delay),$extra));
    }
    public static function checked_identity(&$jar,$payload) {
        $identity=Crolord_Publisher_Weibo_Session::identity($jar);
        if($identity['uid']!==(string)$payload['account']){throw new RuntimeException('微博登录账号与排队时绑定的 UID 不一致，已停止。');}return $identity;
    }
    /** Native website field, shared by image and video requests. No extra HTTP. */
    public static function post_data($payload,$progress) {
        $statement=self::statement($payload['weibo_statement']??0);
        $data=array('content'=>Crolord_Publisher_Platforms::main_text($payload),'visible'=>0,'vote'=>'','media'=>'','pic_id'=>'');
        if($statement!==0){$data['mblog_statement']=$statement;}
        if(($payload['weibo_format']??'images')==='video'){
            $data['media']=wp_json_encode(array('titles'=>array(array('title'=>($payload['weibo_video_title']??'')?:$payload['title'],'default'=>true)),'covers'=>array(array('url'=>'')),'type'=>'video','media_id'=>$progress['video']['media_id']));
        }else{$data['pic_id']=!empty($progress['pictures'])?wp_json_encode($progress['pictures']):'';}
        return $data;
    }
    public static function verify_statement($payload,$status) {
        $expected=self::statement($payload['weibo_statement']??0);if($expected===0){return;}
        $codes=array();$candidates=array($status['mblog_statement']??null);
        // Only this status's native label fields count. A retweeted post's
        // labels or matching words in the caption cannot prove our declaration.
        if(is_array($status['content_auth_list']??null)){
            foreach(array_slice($status['content_auth_list'],0,20)as$item){if(is_array($item)){$candidates[]=$item['mblog_statement']??null;}}
        }
        foreach($candidates as$code){if((is_int($code)||is_string($code))&&preg_match('/^[1-4]$/D',(string)$code)){$codes[]=(int)$code;}}
        if(!in_array($expected,$codes,true)){
            throw new RuntimeException('已取得微博 ID，但回执尚未核对到“'.self::STATEMENTS[$expected].'”内容声明；保留同一帖待检查，不会重复发表。');
        }
    }
    public static function receipt($payload,$id,&$jar,$uploaded=array(),&$audit=null) {
        if(!Crolord_Publisher_Weibo_Session::uid($id)){throw new RuntimeException('微博帖子 ID 无效。');}
        $d=Crolord_Publisher_Weibo_Session::request('https://weibo.com/ajax/statuses/show?id='.rawurlencode((string)$id),'GET',null,$jar,'核对微博发布结果');
        Crolord_Publisher_Weibo_Session::accepted($d,'核对微博发布结果');$status=$d['data']??$d;
        $sid=$status['idstr']??$status['id']??'';$uid=$status['user']['idstr']??$status['user']['id']??'';
        if(!Crolord_Publisher_Weibo_Session::uid($sid)||(string)$sid!==(string)$id||(string)$uid!==(string)$payload['account']){throw new RuntimeException('微博回执的帖子或账号不一致，未登记成功。');}
        if(!empty($status['isLongText'])){$long=Crolord_Publisher_Weibo_Session::request('https://weibo.com/ajax/statuses/longtext?id='.rawurlencode((string)$id),'GET',null,$jar,'核对微博长文回执');if(!empty($long['data']['longTextContent'])){$status['text_raw']=$long['data']['longTextContent'];$status['url_struct']=$long['data']['url_struct']??($status['url_struct']??array());}}
        $text=html_entity_decode(wp_strip_all_tags($status['text_raw']??$status['text']??''),ENT_QUOTES,'UTF-8');
        if(strpos($text,$payload['title'])!==0){throw new RuntimeException('微博回执未在开头核对到完整分享标题，保留待核对。');}
        $url_ok=in_array($payload['url'],preg_split('/\s+/u',$text),true);
        foreach(($status['url_struct']??array())as$link){if(($link['long_url']??$link['ori_url']??'')===$payload['url']){$url_ok=true;}}
        if(!$url_ok&&!Crolord_Publisher_Platforms::link_last($payload)){throw new RuntimeException('微博回执未核对到本篇文章网址；短链可能尚未展开，请稍后检查。');}
        if(($payload['weibo_format']??'images')==='video'){
            $page=$status['page_info']??array();if(($page['object_type']??'')!=='video'&&($page['type']??'')!=='video'&&empty($page['media_info']['playback_list'])){throw new RuntimeException('微博回执未核对到视频，保留待核对。');}
        }elseif(Crolord_Publisher_Platforms::card($payload)){
            $pics=array_values($status['pic_ids']??array_keys($status['pic_infos']??array()));$audit=array('count'=>count($pics),'ids'=>$pics,'kind'=>'card');
            if(count($pics)>1){throw new RuntimeException('微博网页卡片任务出现多张相册附件，保留原帖待核对。');}
        }else{
            $pics=array_values($status['pic_ids']??array_keys($status['pic_infos']??array()));$audit=array('count'=>count($pics),'ids'=>$pics);
            if(count($pics)!==count($payload['images'])){throw new RuntimeException('微博最终附件为 '.count($pics).' 张，计划为 '.count($payload['images']).' 张；保留同一帖待核对，未登记完成。');}
            if(!empty($payload['link_layout'])&&$uploaded&&array_column($uploaded,'pid')!==$pics){throw new RuntimeException('微博最终图片 ID 或顺序与上传记录不一致，保留待核对。');}
        }
        self::verify_statement($payload,$status);
        $bid=$status['mblogid']??'';$url=is_string($bid)&&preg_match('/^[A-Za-z0-9]{4,30}$/D',$bid)?'https://weibo.com/'.$payload['account'].'/'.$bid:'https://weibo.com/detail/'.$id;
        return array('id'=>(string)$id,'url'=>$url,'card'=>Crolord_Publisher_Platforms::card($payload)?self::card_evidence($status,$payload['url']):null);
    }
    public static function card_evidence($status,$url) {
        $page=$status['page_info']??array();$kind=strtolower((string)($page['object_type']??$page['type']??''));
        $title=trim(wp_strip_all_tags($page['page_title']??$page['title']??''));
        $image=$page['page_pic']['url']??$page['page_pic']??$page['pic_info']['pic_big']['url']??'';
        $matched=false;foreach(($status['url_struct']??array())as$link){if(($link['long_url']??$link['ori_url']??'')===$url){$matched=true;}}
        $recognized=$matched&&in_array($kind,array('webpage','web','article','link'),true)&&$title!==''&&is_string($image)&&$image!=='';
        return array('state'=>$recognized?'recognized':'requested','source'=>'weibo_native_receipt');
    }
    public static function step($job,$p,$progress) {
        if(function_exists('set_time_limit')){@set_time_limit(120);}
        $generation=hash('sha256',Crolord_Publisher_Weibo_Session::config()['session']);
        $jar=Crolord_Publisher_Weibo_Session::jar();$ids=json_decode($job['remote_ids'],true)?:array();$w=$progress['weibo']??array();$GLOBALS['cp_weibo_owner']=(int)$p['requested_by'];
        try{
            $valid=self::validate($p);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}
            if($ids){
                $received=null;
                try{
                    $receipt=self::receipt($p,$ids[0],$jar,$w['pictures']??array(),$received);$w['url']=$receipt['url'];
                    if($received!==null){$w['receipt_images']=$received['count'];}if($receipt['card']!==null){$w['card']=$receipt['card'];}$w['main_verified']=true;$progress['weibo']=$w;
                    if(Crolord_Publisher_Platforms::link_last($p)&&!Crolord_Publisher_Weibo_Link_Tail::step($job,$p,$progress,$jar,$ids[0])){return;}
                    $progress['weibo']['phase']='verified';self::checkpoint($job,$progress,0,array('state'=>'succeeded'));
                }catch(Throwable $e){
                    $w=$progress['weibo']??$w;if($received!==null){$w['receipt_images']=$received['count'];}$w['verify_tries']=(int)($w['verify_tries']??0)+1;$progress['weibo']=$w;
                    self::checkpoint($job,$progress,60,array('state'=>$w['verify_tries']>=5?'external_paused':'external_pending','error_text'=>$e->getMessage()));
                }
                return;
            }
            if(!empty($w['publish_started'])){self::checkpoint($job,$progress,0,array('state'=>'unknown','error_text'=>'上次发表结果不明确；未再次提交。请核对微博或登记真实帖子 ID。'));return;}
            if(Crolord_Publisher_Platforms::card($p)){
                self::checkpoint($job,$progress,0,array('state'=>'failed','error_text'=>'旧微博卡片任务没有上传配图，已停止发送。请保存新版文章设置后选择“采用当前设置并重试”；已有帖子不会重发。'));return;
            }
            $identity=self::checked_identity($jar,$p);
            if(($p['weibo_format']??'images')==='video'){
                $file=self::file($p['weibo_video'],true);$v=$w['video']??array();
                if(!$v){$w['video']=self::video_init($file,$jar);$w['phase']='video_init';$progress['weibo']=$w;self::checkpoint($job,$progress);return;}
                if(time()-$v['started']>1800){throw new RuntimeException('视频处理超过三十分钟，请核对微博后重新建立上传。');}
                if($v['stage']==='upload'){self::binary_video($file,$v);$v['stage']='finish';$w['video']=$v;$w['phase']='video_uploaded';$progress['weibo']=$w;self::checkpoint($job,$progress);return;}
                if($v['stage']==='finish'){
                    $auth=Crolord_Publisher_Store::decrypt($v['auth']);if(!$auth){throw new RuntimeException('视频上传凭据已失效。');}
                    $data=array('source'=>self::SOURCE,'upload_id'=>$v['upload_id'],'media_id'=>$v['media_id'],'upload_protocol'=>'binary','count'=>1,'action'=>'finish','size'=>filesize($file),'client'=>'web','status'=>'');
                    $d=Crolord_Publisher_Weibo_Session::request('https://fileplatform.api.weibo.com/2/fileplatform/check.json','POST',$data,$jar,'完成微博视频上传','application/x-www-form-urlencoded',array('X-Up-Auth'=>$auth));Crolord_Publisher_Weibo_Session::accepted($d,'完成微博视频上传');
                    $v['stage']='transcode';$w['video']=$v;$w['phase']='video_processing';$progress['weibo']=$w;self::checkpoint($job,$progress,20);return;
                }
                if($v['stage']==='transcode'){
                    $d=Crolord_Publisher_Weibo_Session::request(self::query('https://weibo.com/ajax/multimedia/output',array('source'=>self::SOURCE,'ids'=>$v['media_id'],'labels'=>'screenshot')),'GET',null,$jar,'检查微博视频转码');Crolord_Publisher_Weibo_Session::accepted($d,'检查微博视频转码');
                    if(empty($d['data'][$v['media_id']])){$w['phase']='video_processing';$progress['weibo']=$w;self::checkpoint($job,$progress,20);return;}
                    $v['stage']='ready';unset($v['auth']);$w['video']=$v;$w['phase']='video_ready';$progress['weibo']=$w;self::checkpoint($job,$progress);return;
                }
            }else{
                $uploads=$w['pictures']??array();$index=count($uploads);
                if($index<count($p['images'])){$uploads[]=self::upload_image($p['images'][$index],$identity,$jar);$w['pictures']=$uploads;$w['phase']='images_'.count($uploads);$progress['weibo']=$w;self::checkpoint($job,$progress);return;}
            }
            // Recheck publication state and media immediately before the non-idempotent POST.
            $article=get_post($job['post_id']);if(!$article||$article->post_status!=='publish'||$article->post_password||!user_can($p['requested_by'],'edit_post',$job['post_id'])||!user_can($p['requested_by'],'crolord_publish_social')){throw new RuntimeException('文章状态或发布者权限已改变，未发表。');}
            foreach($p['images'] as$media){self::file($media);}if(!empty($p['weibo_video'])){self::file($p['weibo_video'],true);}
            $data=self::post_data($p,$w);
            $w['phase']='publishing';$w['publish_started']=time();$progress['weibo']=$w;
            if(Crolord_Publisher_Weibo_Native::route($p)){$permit=Crolord_Publisher_Store::locked(function()use($p,$job,$progress){$valid=Crolord_Publisher_Weibo_Native::validate($p);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}$fresh=Crolord_Publisher_Store::get($job['id']);if(!$fresh||$fresh['state']!=='running'||!hash_equals($fresh['lease'],$job['lease'])){throw new RuntimeException('任务租约已改变，未发表。');}Crolord_Publisher_Store::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));return true;});if(is_wp_error($permit)){throw new RuntimeException('授权正在变更，未发表。');}}
            else{Crolord_Publisher_Store::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));}
            try{$d=Crolord_Publisher_Weibo_Session::request('https://weibo.com/ajax/statuses/update','POST',$data,$jar,'发表微博');}
            catch(Throwable $e){self::checkpoint($job,$progress,0,array('state'=>'unknown','error_text'=>$e->getMessage().'；发表结果未知，未自动重发。'));return;}
            try{Crolord_Publisher_Weibo_Session::accepted($d,'发表微博');}
            catch(Throwable $e){$w['rejected']=time();$progress['weibo']=$w;self::checkpoint($job,$progress,0,array('state'=>'unknown','error_text'=>$e->getMessage().'；已提交过发表请求，请核对后处理，未自动重发。'));return;}
            $posted=$d['data']['status']??$d['data']??$d['status']??$d;$id=$posted['idstr']??$posted['id']??'';
            if(!Crolord_Publisher_Weibo_Session::uid($id)){self::checkpoint($job,$progress,0,array('state'=>'unknown','error_text'=>'微博未返回可核对的帖子 ID，未自动重发。'));return;}
            $w['phase']='receipt';$progress['weibo']=$w;self::checkpoint($job,$progress,10,array('remote_ids'=>wp_json_encode(array((string)$id))));
        }finally{unset($GLOBALS['cp_weibo_owner']);Crolord_Publisher_Weibo_Session::refresh($jar,$p['account'],$generation);}
    }
    private static function action_checkpoint($job,$progress,$extra=array()) {
        global $wpdb;
        $values=array_merge(array('state'=>'external_pending','progress'=>wp_json_encode($progress),'error_text'=>'','lease'=>'','available'=>time(),'touched'=>time()),$extra);
        $changed=$wpdb->update(Crolord_Publisher_Store::table(),$values,array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease'],'remote_ids'=>$job['remote_ids']));
        if(!$changed){throw new RuntimeException('任务状态已变化，请刷新后检查，未重复发表。');}
    }
    public static function action($job,$op) {
        $ids=json_decode($job['remote_ids'],true)?:array();$p=json_decode($job['payload'],true);$progress=json_decode($job['progress'],true)?:array();
        if($op==='weibo_migrate'){
            if(self::web($p)||$ids||!in_array($job['state'],array('manual_ready','failed','cancelled'),true)||!empty($progress['weibo']['publish_started'])){return new WP_Error('weibo_migrate','只能切换尚未发布、没有平台 ID 的旧素材任务。');}
            $s=get_post_meta($job['post_id'],'_crolord_publisher_selection',true);
            if(!is_array($s)||!in_array('weibo',$s['platforms']??array(),true)||($s['weibo_delivery']??'')!=='web'){return new WP_Error('weibo_migrate','先在文章中选择微博“服务器自动发布”，保存后再切换此任务。');}
            $s['requested_by']=get_current_user_id();$prepared=Crolord_Publisher::bind(Crolord_Publisher::for_platform(Crolord_Publisher::snapshot($job['post_id'],$s),'weibo'),'weibo');$valid=self::validate($prepared);
            if(is_wp_error($valid)){return $valid;}
            $audit=array('weibo_migration'=>array('by'=>get_current_user_id(),'at'=>time(),'previous_payload'=>$p,'previous_progress'=>$progress,'previous_state'=>$job['state']));
            $when=max(time(),(int)$prepared['not_before']);self::action_checkpoint($job,$audit,array('payload'=>wp_json_encode($prepared),'state'=>$when>time()?'scheduled':'queued','available'=>$when));Crolord_Publisher::due($job['id'],$when);return 1;
        }
        if($op==='weibo_check'){
            if(!$ids){return new WP_Error('weibo_id','尚无微博帖子 ID，请先在平台核对；不会自动重复发表。');}
            if(in_array($job['state'],array('running','publishing'),true)){return new WP_Error('weibo_busy','任务正在运行，请等待当前核对完成。');}
            self::action_checkpoint($job,$progress);return 1;
        }
        if($op==='reconcile'){
            if($job['state']!=='unknown'||$ids){return new WP_Error('weibo_state','此任务不能登记其他帖子 ID。');}
            $id=trim((string)wp_unslash($_POST['receipt']??''));$jar=Crolord_Publisher_Weibo_Session::jar();$receipt=self::receipt($p,$id,$jar);
            $progress['weibo']['url']=$receipt['url'];$progress['weibo']['phase']='verified';self::action_checkpoint($job,$progress,array('state'=>'succeeded','remote_ids'=>wp_json_encode(array($id))));return 1;
        }
        if($op==='reset_unknown'&&$job['state']==='unknown'&&!$ids&&!empty($_POST['absent'])){unset($progress['weibo']['publish_started']);$progress['weibo']['absence_confirmed_by']=get_current_user_id();$progress['weibo']['absence_confirmed_at']=time();self::action_checkpoint($job,$progress,array('state'=>'queued'));return 1;}
        if($ids&&in_array($op,array('retry','refresh','reset_unknown','cancel','reschedule'),true)){return new WP_Error('weibo_received','已有微博帖子 ID，只能检查同一帖的结果，不能再次发表。');}
        return null;
    }
}
