<?php
if (!defined('ABSPATH')) { exit; }

/** A separate native comment puts the article URL after the main post's media. */
final class Crolord_Publisher_Weibo_Link_Tail {
    public static function has_url($item,$url) {
        $text=html_entity_decode(wp_strip_all_tags($item['text_raw']??$item['text']??''),ENT_QUOTES,'UTF-8');
        if(in_array($url,preg_split('/\s+/u',trim($text)),true)){return true;}
        foreach(($item['url_struct']??array())as$link){if(($link['long_url']??$link['ori_url']??'')===$url){return true;}}
        $html=(string)($item['text']??'');preg_match_all('~\bhref=["\x27]([^"\x27]+)["\x27]~i',$html,$links);
        foreach($links[1]as$link){if(html_entity_decode($link,ENT_QUOTES,'UTF-8')===$url){return true;}}
        return false;
    }
    private static function find($payload,$root,&$jar,$expected='') {
        $url=Crolord_Publisher_Weibo::query('https://weibo.com/ajax/statuses/buildComments',array('id'=>$root,'uid'=>$payload['account'],'is_reload'=>1,'is_show_bulletin'=>2,'is_mix'=>0,'count'=>50,'fetch_level'=>0,'flow'=>1));
        $d=Crolord_Publisher_Weibo_Session::request($url,'GET',null,$jar,'核对文章链接评论');
        Crolord_Publisher_Weibo_Session::accepted($d,'核对文章链接评论');
        foreach(array_slice(is_array($d['data']??null)?$d['data']:array(),0,50)as$comment){
            $id=(string)($comment['idstr']??$comment['id']??'');$uid=(string)($comment['user']['idstr']??$comment['user']['id']??'');
            if(!Crolord_Publisher_Weibo_Session::uid($id)||$uid!==(string)$payload['account']||($expected!==''&&$id!==$expected)){continue;}
            $parent=(string)($comment['status']['idstr']??$comment['status']['id']??$root);
            if($parent===(string)$root&&self::has_url($comment,$payload['url'])){return $id;}
        }
        return '';
    }
    public static function step($job,$payload,&$progress,&$jar,$root) {
        $w=$progress['weibo'];$expected=(string)($w['link_comment']??'');
        $found=self::find($payload,$root,$jar,$expected);
        if($found){$progress['weibo']['link_comment']=$found;$progress['weibo']['link_verified']=true;return true;}
        if(!empty($w['link_started'])){throw new RuntimeException('主帖与媒体已核对，链接评论尚未核对到；保留同一主帖和评论记录，不重复发送。');}
        Crolord_Publisher_Weibo::checked_identity($jar,$payload);
        $valid=Crolord_Publisher_Weibo_Native::validate($payload);if(is_wp_error($valid)){throw new RuntimeException($valid->get_error_message());}
        $progress['weibo']['link_started']=time();$progress['weibo']['phase']='link_comment';
        Crolord_Publisher_Store::write($job,array('state'=>'publishing','progress'=>wp_json_encode($progress)));
        try{
            $d=Crolord_Publisher_Weibo_Session::request('https://weibo.com/ajax/comments/create','POST',array('id'=>(string)$root,'comment'=>$payload['url'],'pic_id'=>'','is_repost'=>0,'comment_ori'=>0,'is_comment'=>0),$jar,'发表文章链接评论');
        }catch(Throwable $e){
            Crolord_Publisher_Weibo::checkpoint($job,$progress,60,array('state'=>'external_pending','error_text'=>'主帖已发布；链接评论结果待核对，没有重发主帖或评论。'));return false;
        }
        try{Crolord_Publisher_Weibo_Session::accepted($d,'发表文章链接评论');}
        catch(Throwable $e){
            $progress['weibo']['link_rejected']=true;
            Crolord_Publisher_Weibo::checkpoint($job,$progress,0,array('state'=>'external_paused','error_text'=>'主帖已发布；微博未接受链接评论。可仅重试链接评论，不重新发帖。'));return false;
        }
        $comment=$d['data']??array();$id=(string)($comment['idstr']??$comment['id']??'');
        if(Crolord_Publisher_Weibo_Session::uid($id)){$progress['weibo']['link_comment']=$id;}
        Crolord_Publisher_Weibo::checkpoint($job,$progress,10,array('state'=>'external_pending','error_text'=>'主帖已发布，正在核对最后的文章链接评论。'));return false;
    }
}
