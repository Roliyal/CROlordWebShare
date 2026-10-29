<?php
if (!defined('ABSPATH')) { exit; }
final class Crolord_Publisher_Platforms {
    public static function names() { return array('wechat' => '微信朋友圈', 'weibo' => '微博', 'x' => 'X', 'xiaohongshu' => '小红书'); }
    public static function selectable() { return array('weibo' => '微博', 'x' => 'X', 'xiaohongshu' => '小红书'); }
    public static function config($platform) {
        $all = get_option('crolord_publisher_accounts', array());
        return isset($all[$platform]) ? $all[$platform] : array();
    }
    public static function manual($platform, $payload) {
        if ($platform === 'xiaohongshu') { return false; }
        if ($platform==='weibo'&&Crolord_Publisher_Weibo_Native::route($payload)) { return false; }
        if ($platform==='weibo'&&(Crolord_Publisher_Weibo::web($payload)||Crolord_Publisher_Weibo_API::route($payload)||Crolord_Publisher_Weibo_Browser::route($payload))) { return false; }
        return 'x' !== $platform || ($payload['x_route'] ?? '') === 'native';
    }
    public static function weighted_length($text) {
        // Conservative limit: non-ASCII counts double, each HTTPS URL counts 23.
        $urls = 0;
        $text = preg_replace_callback('~https?://[^\s]+~u', function () use (&$urls) { $urls++; return ''; }, $text);
        preg_match_all('/./us', $text, $chars);
        $length = $urls * 23;
        foreach ($chars[0] as $char) { $length += strlen($char) > 1 ? 2 : 1; }
        return $length;
    }
    public static function text($payload) {
        // A location note is never a native platform tag. Existing frozen jobs keep their caption.
        if ((int) ($payload['format'] ?? 0) < 3) { return $payload['title'] . (!empty($payload['location']) ? "\n📍 " . $payload['location'] : '') . "\n" . $payload['url']; }
        return $payload['title'] . "\n\n" . $payload['url'];
    }
    public static function link_last($payload) {
        return ($payload['link_layout']??'')==='reply_last'&&(!empty($payload['images'])||($payload['weibo_format']??'')==='video');
    }
    public static function card($payload) { return ($payload['share_layout']??'')==='card'; }
    public static function main_card($payload) { return self::card($payload)&&($payload['x_card_mode']??'')==='main'&&($payload['link_layout']??'')==='inline'; }
    public static function x_card_selection($selection) { return ($selection['share_layout']??'')==='album'&&($selection['x_presentation']??'')==='card'; }
    /** Resolve the editor choice before freezing a platform job. Legacy jobs stay untouched. */
    public static function layout($selection,$platform) {
        if($platform==='x'&&self::x_card_selection($selection)){return 'card';}
        $layout=$selection['share_layout']??'legacy';
        return $layout==='platform'?'album':$layout;
    }
    public static function needs_images($selection) {
        if(in_array('xiaohongshu',$selection['platforms']??array(),true)){
            foreach($selection['platforms']as$platform){
                if($platform==='xiaohongshu'&&($selection['xhs_format']??'images')!=='video'){return true;}
                if($platform==='weibo'&&($selection['weibo_format']??'images')!=='video'){return true;}
                if($platform==='x'&&!self::x_card_selection($selection)&&($selection['share_layout']??'')!=='card'){return true;}
            }
            return false;
        }
        $layout=$selection['share_layout']??'legacy';
        return $layout!=='card'&&(!self::x_card_selection($selection)||in_array('weibo',$selection['platforms']??array('weibo','x'),true));
    }
    /** One ordered selection rule for preview and immutable platform snapshots. */
    public static function selected_images($images,$selected=null,$maximum=9) {
        if($selected===null){return array_slice($images,0,$maximum);}
        $indexed=array();foreach($images as$image){$indexed[(int)$image['id']]=$image;}
        $result=array();$seen=array();
        foreach($selected as$id){$id=(int)$id;if(isset($indexed[$id])&&!isset($seen[$id])){$result[]=$indexed[$id];$seen[$id]=true;if(count($result)>=$maximum){break;}}}
        return $result;
    }
    public static function main_text($payload) {
        return self::link_last($payload)?$payload['title']:self::text($payload);
    }
    public static function validate($platform, $payload) {
        if ($platform === 'xiaohongshu') { return Crolord_Publisher_XHS::validate($payload); }
        if ($platform === 'wechat') { return Crolord_Publisher_Wechat_History::unavailable(); }
        if ($platform === 'weibo'&&Crolord_Publisher_Weibo_Native::route($payload)) { return Crolord_Publisher_Weibo_Native::validate($payload); }
        if ($platform === 'weibo'&&Crolord_Publisher_Weibo_Browser::route($payload)) { return new WP_Error('weibo_browser_retired','浏览器扩展通道已停用，请显式迁移未发出的任务到插件内通道。'); }
        if ($platform === 'weibo'&&(Crolord_Publisher_Weibo_API::route($payload)||Crolord_Publisher_Weibo::web($payload))) { return new WP_Error('weibo_retired','旧微博 PHP 扫码与付费官方 API 通道已停用，请显式迁移确定未发出的任务到插件内通道。'); }
        if (empty($payload['title']) || empty($payload['url'])) { return new WP_Error('invalid', '请填写分享主标题，并先保存文章。'); }
        if (empty($payload['images'])&&empty($payload['allow_empty_media'])) { return new WP_Error('images', '请选择至少一张分享图片。'); }
        if (self::manual($platform, $payload) && empty(trim($payload['location'] ?? ''))) { return new WP_Error('location', '请填写地点备忘，发布时在平台内选择对应的原生定位。'); }
        if ('x' === $platform && ($payload['x_route'] ?? '') === 'buffer' && !empty($payload['place_id'])) { return new WP_Error('location', 'Buffer 不能传递 X Place ID。请选择平台内发布以添加原生定位，未降级成文字定位。'); }
        if ('x' === $platform && ($payload['x_route'] ?? '') === 'buffer' && (empty($payload['buffer_channel']) || empty($payload['account']))) { return new WP_Error('config', '请先在 Publisher 设置中连接 Buffer 并选择自己的 X 账号。'); }
        if ('x' === $platform && count($payload['images']) > 4) { return new WP_Error('images', 'X 单帖最多 4 张图片。'); }
        if (count($payload['images']) > 9) { return new WP_Error('images', '最多选择 9 张图片。'); }
        if ($platform === 'x' && !self::manual($platform, $payload) && self::weighted_length(self::main_text($payload)) > 280) {
            return new WP_Error('length', '分享文案超过保守的 280 加权字符限制，请缩短分享主标题。文章正文不会被修改。');
        }
        return true;
    }
    public static function image($image) {
        $real=Crolord_Publisher_Media::local($image['id']);
        if (!in_array(wp_get_image_mime($real), array('image/jpeg', 'image/png'), true)) { throw new RuntimeException('自动上传只支持 JPEG、PNG；请转换图片后重新建立任务。'); }
        if (filesize($real) > 5 * 1024 * 1024) { throw new RuntimeException('平台图片需不大于 5 MB，请先压缩图片。'); }
        if (hash_file('sha256', $real) !== $image['sha256']) { throw new RuntimeException('媒体文件在排队后发生变化；为避免错发已停止。'); }
        return $real;
    }
    /** Compatibility entry points: the retired direct APIs never make a network request. */
    public static function retired() { return new WP_Error('retired', '此旧接入已停用，请使用当前的微博连接或 X／Buffer 设置。原记录与发布 ID 保留。'); }
    public static function request($url, $args, $publishing = false) { return self::retired(); }
    public static function token($platform, $payload) { return self::retired(); }
    public static function identity($platform, $token, $uid) { return self::retired(); }
    public static function x_upload($image, $token) { return self::retired(); }
    public static function x_publish($payload, $media, $previous, $part, $token) { return self::retired(); }
    public static function weibo_publish($payload, $token) { return self::retired(); }
}
