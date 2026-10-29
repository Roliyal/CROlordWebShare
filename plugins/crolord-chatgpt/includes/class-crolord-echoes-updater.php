<?php
/** Only deliver this installed plugin's code over the authenticated loopback bridge. */
if(!defined('ABSPATH'))exit;
final class Crolord_Echoes_Updater {
 const NOTICE='crolord_echoes_runtime_notice';
 public static function maybe_update($force=false){
  if(!current_user_can('update_plugins')||(!$force&&(wp_doing_ajax()||(function_exists('wp_doing_cron')&&wp_doing_cron()))))return;
  $source=FIELDNOTES_CHATGPT_DIR.'tools/';$program=@file_get_contents($source.'echoes-server.py');
  if(!is_string($program)||!preg_match('/^VERSION = "([0-9.]+)"/m',$program,$match))return;
  $version=$match[1];$cache='crolord_echoes_runtime_'.str_replace('.','_',$version);if(!$force&&get_transient($cache))return;set_transient($cache,1,60);
  $health=Crolord_Echoes_Account::request('health',array(),5);if(is_wp_error($health))return;
  if(empty($health['auto_update'])){set_transient(self::NOTICE,array('kind'=>'migration','message'=>'旧运行组件需首次启用自动更新。只迁移一次，以后上传新版插件、进入后台即可更新。'),DAY_IN_SECONDS);return;}
  if(($health['version']??'')===$version){delete_transient(self::NOTICE);set_transient($cache,1,5*MINUTE_IN_SECONDS);return;}
  $files=array();$hashes=array();$total=0;$iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
  foreach($iterator as $file){
   if(!$file->isFile()||$file->isLink())continue;$relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($file->getPathname(),strlen($source)));if(strpos($relative,'__pycache__/')!==false)continue;
   if(!preg_match('~^echoes[-_][a-zA-Z0-9_-]+\.py$~D',$relative)&&!preg_match('~^vendor/[a-zA-Z0-9_./-]+\.(py|txt|typed)$~D',$relative))continue;
   $data=file_get_contents($file->getPathname());$total+=strlen($data);if($total>8000000||count($files)>=300)return;$files[$relative]=base64_encode($data);$hashes[$relative]=hash('sha256',$data);
  }
  ksort($hashes,SORT_STRING);$result=Crolord_Echoes_Account::request('upgrade',array('version'=>$version,'digest'=>hash('sha256',wp_json_encode($hashes,JSON_UNESCAPED_SLASHES)),'files'=>$files),20);
  if(is_wp_error($result))set_transient(self::NOTICE,array('kind'=>'error','message'=>'自动更新未完成：'.$result->get_error_message()),HOUR_IN_SECONDS);
  else {delete_transient(Crolord_Echoes_Account::CACHE);set_transient(self::NOTICE,array('kind'=>'pending','message'=>'运行组件已准备更新，当前回复完成后自动切换。口令与账号授权继续保留。'),5*MINUTE_IN_SECONDS);}
 }
 public static function upgraded($upgrader,$options){if(($options['type']??'')==='plugin')self::maybe_update(true);}
 public static function notice(){if(!current_user_can('update_plugins'))return;$notice=get_transient(self::NOTICE);if(!is_array($notice)||($notice['kind']??'')!=='error')return;echo '<div class="notice notice-error"><p>'.esc_html($notice['message']).'</p></div>';}

}
add_action('admin_init',array('Crolord_Echoes_Updater','maybe_update'),30);
add_action('upgrader_process_complete',array('Crolord_Echoes_Updater','upgraded'),20,2);
add_action('admin_notices',array('Crolord_Echoes_Updater','notice'));
