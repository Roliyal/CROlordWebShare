<?php
/** Local static raster cache. Cold public pages never wait for Gravatar. */
if (!defined('ABSPATH')) exit;
function fct_avatar_cache_paths($hash,$rating) {
    $uploads=wp_upload_dir(null,false);if(!empty($uploads['error']))return null;
    $name=hash_hmac('sha256',$hash.'|'.$rating,wp_salt('auth'));
    return array('dir'=>trailingslashit($uploads['basedir']).'crolord-avatar-cache','url'=>trailingslashit($uploads['baseurl']).'crolord-avatar-cache','name'=>$name);
}
function fct_avatar_cached_static($hash,$rating) {
    $paths=fct_avatar_cache_paths($hash,$rating);if(!$paths)return null;
    foreach(array('webp','png','jpg','gif','avif') as $extension){$file=$paths['dir'].'/'.$paths['name'].'.'.$extension;if(is_file($file))return array('url'=>$paths['url'].'/'.$paths['name'].'.'.$extension.'?v='.filemtime($file),'updated'=>filemtime($file));}
    return null;
}
function fct_avatar_store_static($hash,$rating,$record) {
    $paths=fct_avatar_cache_paths($hash,$rating);if(!$paths||!wp_mkdir_p($paths['dir']))return;
    $extensions=array('image/webp'=>'webp','image/png'=>'png','image/jpeg'=>'jpg','image/gif'=>'gif','image/avif'=>'avif');
    if(($record['status']??'')!=='found'||!isset($extensions[$record['mime']??'']))return;
    $body=base64_decode($record['body']??'',true);$info=is_string($body)&&strlen($body)<=262144?@getimagesizefromstring($body):false;
    if(!$info||$info['mime']!==$record['mime'])return;
    $temporary=tempnam($paths['dir'],'avatar-');if(!$temporary)return;
    $file=$paths['dir'].'/'.$paths['name'].'.'.$extensions[$record['mime']];
    if(file_put_contents($temporary,$body)===strlen($body)){chmod($temporary,0644);rename($temporary,$file);foreach($extensions as $extension){$old=$paths['dir'].'/'.$paths['name'].'.'.$extension;if($old!==$file&&is_file($old))unlink($old);}}
    if(is_file($temporary))unlink($temporary);
}
function fct_avatar_queue_static($hash,$rating) {
    $paths=fct_avatar_cache_paths($hash,$rating);
    if($paths&&is_file($paths['dir'].'/'.$paths['name'].'.retry')&&filemtime($paths['dir'].'/'.$paths['name'].'.retry')>time()-300)return;
    static $scheduled=0;if($scheduled>=5||wp_next_scheduled('fct_avatar_warm_static',array($hash,$rating)))return;
    ++$scheduled;wp_schedule_single_event(time()+5,'fct_avatar_warm_static',array($hash,$rating));
}
function fct_avatar_warm_static($hash,$rating) {
    if(!preg_match('/^[a-f0-9]{32}(?:[a-f0-9]{32})?$/D',$hash)||!in_array($rating,array('g','pg','r','x'),true))return;
    $record=fct_avatar_fetch($hash,$rating);if(($record['status']??'')==='found')fct_avatar_store_static($hash,$rating,$record);
    else{$paths=fct_avatar_cache_paths($hash,$rating);if($paths&&wp_mkdir_p($paths['dir'])){touch($paths['dir'].'/'.$paths['name'].'.retry');if(($record['status']??'')==='missing')foreach(array('webp','png','jpg','gif','avif') as $extension){$old=$paths['dir'].'/'.$paths['name'].'.'.$extension;if(is_file($old))unlink($old);}}}
}
add_action('fct_avatar_warm_static','fct_avatar_warm_static',10,2);
function fct_fast_native_avatar($data,$subject) {
    if($subject instanceof WP_Comment){$selected=get_comment_meta($subject->comment_ID,'_fct_avatar',true);if($selected&&$selected!=='gravatar')return $data;}
    $url=$data['url']??'';$parsed=is_string($url)?wp_parse_url($url):null;
    // Existing local uploads, explicit custom avatars and other CDN providers retain control.
    if(!is_array($parsed)||!preg_match('/^(?:(?:secure|www|[0-9]+)\.)?gravatar\.com$/i',$parsed['host']??'')||!preg_match('~/avatar/([a-f0-9]{32}(?:[a-f0-9]{32})?)(?:$|/)~i',$parsed['path']??'',$match))return $data;
    $hash=strtolower($match[1]);$rating=fct_avatar_rating();$cached=fct_avatar_cached_static($hash,$rating);
    if(!$cached||$cached['updated']<time()-DAY_IN_SECONDS)fct_avatar_queue_static($hash,$rating);
    if($cached){$data['url']=$cached['url'];return $data;}
    // An instant local placeholder must not be treated as evidence in avatar lookup UI.
    if(wp_doing_ajax())return $data;
    $data['url']=FCT_URL.'assets/avatars/avatar-placeholder.svg';return $data;
}
add_filter('get_avatar_data','fct_fast_native_avatar',30,2);
