<?php
if (!defined('ABSPATH')) { exit; }

/** Server-rendered article metadata; no frontend JS, scraping or remote requests. */
final class Crolord_Publisher_Share_Metadata {
    const META='_crolord_publisher_link_meta';
    private static $data=null;
    public static function boot() {
        add_action('wp',array(__CLASS__,'page'),0);
        add_action('save_post_post',array(__CLASS__,'cache'),70,3);
        add_action('rest_after_insert_post',array(__CLASS__,'cache'),70,3);
    }
    private static function public_post($post) {return $post&&($post->post_type??'')==='post'&&$post->post_status==='publish'&&!$post->post_password;}
    private static function key($post) {return hash('sha256','article-125|'.get_option('site_icon').'|'.($post->post_modified_gmt??'').'|'.($post->post_name??'').'|'.$post->post_title.'|'.($post->post_excerpt??'').'|'.hash('sha256',(string)($post->post_content??'')).'|'.get_post_thumbnail_id($post->ID).'|'.get_bloginfo('name').'|'.get_bloginfo('description'));}
    private static function plain($text) {
        $text=preg_replace('~</(?:p|div|li|h[1-6]|figure)>|<br\s*/?>~i',' ',strip_shortcodes((string)$text));
        return trim(preg_replace('/\s+/u',' ',html_entity_decode(wp_strip_all_tags($text),ENT_QUOTES|ENT_HTML5,'UTF-8')));
    }
    public static function excerpt($post) {
        $text=self::plain($post->post_excerpt??'');
        if($text===''){$text=self::plain($post->post_content??'');}
        if($text===''){return wp_strip_all_tags(get_bloginfo('description'));}
        preg_match('/\A.{0,160}/us',$text,$m);$short=rtrim($m[0]??'');
        return $short.(strlen($short)<strlen($text)?'…':'');
    }
    public static function build($post) {
        $title=self::plain($post->post_title??'');
        $description=self::excerpt($post);
        $image_id=(int)get_post_thumbnail_id($post->ID);$image=$image_id?wp_get_attachment_image_url($image_id,'full'):'';
        $image_alt=$image_id?(string)get_post_meta($image_id,'_wp_attachment_image_alt',true):'';
        if(!$image&&preg_match('~<img\b[^>]*\bsrc\s*=\s*([\'"])(.*?)\1~is',(string)($post->post_content??''),$m)){$candidate=html_entity_decode($m[2],ENT_QUOTES|ENT_HTML5,'UTF-8');if(preg_match('~^https?://~i',$candidate)){$image=esc_url_raw($candidate);}}
        if(!$image){$image=function_exists('crolord_brand_image_url')?crolord_brand_image_url(512):get_site_icon_url(512);}
        return array('key'=>self::key($post),'title'=>$title?:wp_strip_all_tags(get_bloginfo('name')),'description'=>$description,'site'=>wp_strip_all_tags(get_bloginfo('name')),'url'=>get_permalink($post->ID),'image'=>$image,'image_alt'=>$image_alt?:$title,'published'=>$post->post_date_gmt??'','modified'=>$post->post_modified_gmt??'');
    }
    public static function cache($post_or_id,$unused=null,$update=null) {
        $post=is_object($post_or_id)?$post_or_id:get_post((int)$post_or_id);if(!self::public_post($post)){return;}
        $old=get_post_meta($post->ID,self::META,true);if(!is_array($old)||($old['key']??'')!==self::key($post)){update_post_meta($post->ID,self::META,self::build($post));}
    }
    private static function provider() {foreach(array('WPSEO_VERSION','RANK_MATH_VERSION','AIOSEO_VERSION','SEOPRESS_VERSION','THE_SEO_FRAMEWORK_VERSION')as$c){if(defined($c)){return true;}}return false;}
    public static function page() {
        if(!is_singular('post')){return;}$post=get_post(get_queried_object_id());if(!self::public_post($post)){return;}
        $cached=get_post_meta($post->ID,self::META,true);
        if(is_array($cached)&&($cached['key']??'')===self::key($post)){self::$data=$cached;}
        else{self::$data=self::build($post);update_post_meta($post->ID,self::META,self::$data);}
        // Keep the theme's homepage logo/identity, but avoid duplicate article tags.
        remove_action('wp_head','crolord_social_metadata',5);
        if(!self::provider()){add_action('wp_head',array(__CLASS__,'render'),5);return;}
        foreach(array('wpseo_opengraph_image','wpseo_twitter_image','rank_math/opengraph/facebook/image','rank_math/opengraph/twitter/image')as$hook){add_filter($hook,array(__CLASS__,'image'),99);}
        foreach(array('wpseo_opengraph_desc','wpseo_twitter_description','rank_math/opengraph/facebook/og_description','rank_math/opengraph/twitter/twitter_description')as$hook){add_filter($hook,array(__CLASS__,'description'),99);}
        foreach(array('wpseo_opengraph_title','wpseo_twitter_title','rank_math/opengraph/facebook/og_title','rank_math/opengraph/twitter/twitter_title')as$hook){add_filter($hook,array(__CLASS__,'title'),99);}
    }
    public static function image($old='') {return self::$data['image']?:$old;}
    public static function description($old='') {return self::$data['description']??$old;}
    public static function title($old='') {return self::$data['title']??$old;}
    public static function render() {
        $d=self::$data;if(!$d){return;}
        $tags=array('og:type'=>'article','og:site_name'=>$d['site'],'og:title'=>$d['title'],'og:description'=>$d['description'],'og:url'=>$d['url']);
        if($d['image']){$tags['og:image']=$d['image'];$tags['og:image:alt']=$d['image_alt'];}
        foreach(array('published'=>'article:published_time','modified'=>'article:modified_time')as$key=>$name){if(!empty($d[$key])){$tags[$name]=str_replace(' ','T',$d[$key]).'Z';}}
        printf("<meta name=\"description\" content=\"%s\">\n",esc_attr($d['description']));
        foreach($tags as$name=>$value){printf("<meta property=\"%s\" content=\"%s\">\n",esc_attr($name),esc_attr($value));}
        $twitter=array('twitter:card'=>$d['image']?'summary_large_image':'summary','twitter:title'=>$d['title'],'twitter:description'=>$d['description']);
        if($d['image']){$twitter['twitter:image']=$d['image'];$twitter['twitter:image:alt']=$d['image_alt'];}
        foreach($twitter as$name=>$value){printf("<meta name=\"%s\" content=\"%s\">\n",esc_attr($name),esc_attr($value));}
    }
}
