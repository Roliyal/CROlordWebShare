<?php
if (!defined('ABSPATH')) { exit; }
/** Bounded, locally generated diagnostics; never expose filesystem paths. */
final class Crolord_Publisher_Media_Error extends RuntimeException {
    public $issues;
    public function __construct($issues) {
        $this->issues=array_slice($issues,0,12);
        parent::__construct('图片检查未通过：'.implode('；',array_map(array('Crolord_Publisher_Media','issue_text'),array_slice($this->issues,0,3))));
    }
}
/** Read local originals in article order without rendering blocks or fetching URLs. */
final class Crolord_Publisher_Media {
    private static function relative($path) {
        if(!is_string($path)||$path===''||preg_match('~[\x00-\x1f\x7f\\\\:]~',$path)||$path[0]==='/'){return false;}
        foreach(explode('/',$path)as$part){if($part===''||$part==='.'||$part==='..'){return false;}}
        return $path;
    }
    public static function local($id) {
        $base=realpath(wp_get_upload_dir()['basedir']);
        if(!$base||!is_dir($base)){throw new RuntimeException('WordPress 上传目录不可用。');}
        $primary=get_attached_file((int)$id);$real=is_string($primary)&&$primary!==''?realpath($primary):false;
        if($real&&strpos($real,$base.DIRECTORY_SEPARATOR)===0&&is_file($real)&&is_readable($real)){return $real;}
        $candidates=array($primary);$denied=false;$outside=false;
        // Filters and migrated absolute paths must not hide the attachment's
        // own existing full file. No thumbnail guessing or network download.
        $candidates[]=get_attached_file((int)$id,true);
        $raw=get_post_meta((int)$id,'_wp_attached_file',true);$meta=get_post_meta((int)$id,'_wp_attachment_metadata',true);
        foreach(array($raw,is_array($meta)?($meta['file']??''):null)as$relative){if(self::relative($relative)!==false){$candidates[]=$base.'/'.$relative;}}
        foreach(array_unique(array_filter($candidates,'is_string'))as$file){
            if($file===''){continue;}$real=realpath($file);if(!$real){continue;}
            if(strpos($real,$base.DIRECTORY_SEPARATOR)!==0){$outside=true;continue;}
            if(!is_file($real)){continue;}if(!is_readable($real)){$denied=true;continue;}return $real;
        }
        if($denied){throw new RuntimeException('本地原图存在，但网站 PHP 无法读取；需检查该文件的读取权限。');}
        if($outside){throw new RuntimeException('附件路径指向本站上传目录之外，未读取其他目录。');}
        throw new RuntimeException('未找到该附件的本地完整图片；网页可显示缩略图不代表原图文件仍在。');
    }
    private static function label($id,$url='') {
        $meta=$id?get_post_meta((int)$id,'_wp_attachment_metadata',true):array();
        $file=is_array($meta)?($meta['file']??''):'';
        if(!is_string($file)||$file===''){$file=$url?(wp_parse_url($url,PHP_URL_PATH)?:''):'';}
        return preg_replace('/[\x00-\x1f\x7f]/u','',basename((string)$file));
    }
    private static function inspect($id,$owner) {
        if(!$id||!wp_attachment_is_image($id)){return array('code'=>'attachment','reason'=>'未识别到有效的媒体库图片附件。');}
        if($owner&&!user_can($owner,'edit_post',$id)){return array('code'=>'permission','reason'=>'本次同步的发布者没有使用此附件的权限。');}
        try{$file=self::local($id);}catch(Throwable $e){return array('code'=>'file','reason'=>$e->getMessage());}
        $mime=wp_get_image_mime($file);
        if(!in_array($mime,array('image/jpeg','image/png'),true)){return array('code'=>'mime','reason'=>$mime?'原图实际格式为 '.$mime.'；此上传通道需要 JPEG／PNG。':'原图不是可识别的 JPEG／PNG 图片，可能为空或已损坏。');}
        return array('file'=>$file,'mime'=>$mime);
    }
    public static function issue_text($issue) {
        return '第 '.(int)$issue['position'].' 处图片'.(!empty($issue['id'])?' · 附件 #'.(int)$issue['id']:'').(!empty($issue['name'])?'（'.$issue['name'].'）':'').'：'.$issue['reason'];
    }
    public static function freeze($id,$owner,$position) {
        $check=self::inspect((int)$id,(int)$owner);
        if(!empty($check['reason'])){throw new Crolord_Publisher_Media_Error(array(array('position'=>$position,'id'=>(int)$id,'name'=>self::label($id),'code'=>$check['code'],'reason'=>$check['reason'])));}
        return array('id'=>(int)$id,'sha256'=>hash_file('sha256',$check['file']));
    }
    private static function upload_url($url) {
        if(!is_string($url)||$url===''||strlen($url)>8192||preg_match('/[\x00-\x20\x7f]/',$url)){return false;}
        $base=wp_get_upload_dir()['baseurl'];$b=wp_parse_url($base);if(!$b||empty($b['host'])){return false;}
        if(strpos($url,'//')===0){$url=($b['scheme']??'https').':'.$url;}
        elseif($url[0]==='/'){$url=($b['scheme']??'https').'://'.$b['host'].(isset($b['port'])?':'.$b['port']:'').$url;}
        $u=wp_parse_url($url);if(!$u||!in_array(strtolower($u['scheme']??''),array('http','https'),true)||strtolower($u['host']??'')!==strtolower($b['host'])||isset($u['user'])||isset($u['pass'])){return false;}
        if(isset($u['port'])&&$u['port']!==($b['port']??(($u['scheme']??'')==='https'?443:80))){return false;}
        $prefix=rtrim(rawurldecode($b['path']??''),'/').'/';$path=rawurldecode($u['path']??'');
        if(strpos($path,$prefix)!==0){return false;}$relative=substr($path,strlen($prefix));
        if(self::relative($relative)===false){return false;}
        return rtrim($base,'/').'/'.$relative;
    }
    private static function url_id($url) {
        $url=self::upload_url($url);if(!$url){return 0;}
        $id=attachment_url_to_postid($url);if($id){return (int)$id;}
        $full=preg_replace('/-[0-9]+x[0-9]+(?=\.[a-zA-Z0-9]+$)/','',$url);
        if($full!==$url){$id=attachment_url_to_postid($full);if($id){return (int)$id;}}
        // WordPress big-image handling stores *-scaled while sub-size names
        // still use the original stem. Require that attachment's own metadata.
        $scaled=preg_replace('/(?=\.[a-zA-Z0-9]+$)/','-scaled',$full);$id=$scaled!==$full?attachment_url_to_postid($scaled):0;
        if($id){$meta=get_post_meta((int)$id,'_wp_attachment_metadata',true);$sizes=is_array($meta)?($meta['sizes']??array()):array();foreach($sizes as$size){if(($size['file']??'')===basename(wp_parse_url($url,PHP_URL_PATH))){return (int)$id;}}}
        return 0;
    }
    private static function attribute($html,$name) {
        if(preg_match('~\b'.preg_quote($name,'~').'\s*=\s*(["\x27])(.*?)\1~is',$html,$m)){return html_entity_decode($m[2],ENT_QUOTES,'UTF-8');}return '';
    }
    public static function discover($post,$owner=0,$maximum=9) {
        $ids=array();$seen=array();$visited=array();$unavailable=0;$blocked=0;$occurrences=0;$duplicates=0;$unique=0;$blocks_seen=0;$issues=array();$resolved=array();
        $maximum=max(1,min(9,(int)$maximum));$source='body';
        $add=function($id,$body=true,$url='')use(&$ids,&$seen,&$unavailable,&$blocked,&$occurrences,&$duplicates,&$unique,&$issues,$owner,$maximum){
            if($body&&++$occurrences>1024){throw new RuntimeException('正文图片超过扫描上限，请手动选择同步图片。');}
            $id=(int)$id;$key=$id?'id:'.$id:'url:'.hash('sha256',$url);
            if(isset($seen[$key])){if($body){$duplicates++;}return;}$seen[$key]=true;
            $check=self::inspect($id,$owner);
            if(!empty($check['reason'])){$unavailable++;if(count($ids)<$maximum){$blocked++;if(count($issues)<12){$issues[]=array('position'=>$occurrences?:1,'id'=>$id,'name'=>self::label($id,$url),'code'=>$check['code'],'reason'=>$check['reason']);}}return;}
            $unique++;if(count($ids)<$maximum){$ids[]=$id;}
        };
        $html=function($text,$fallback=0)use($add,&$resolved){
            preg_match_all('~<img\b[^>]{0,8192}>|\[gallery\b[^\]]{0,4096}\]~i',$text,$matches);
            foreach($matches[0]as$tag){
                if(stripos($tag,'[gallery')===0){foreach(explode(',',self::attribute($tag,'ids'))as$id){if(preg_match('/^[1-9][0-9]*$/D',trim($id))){$add((int)$id);}}continue;}
                $url=self::attribute($tag,'data-src')?:self::attribute($tag,'src');
                if(preg_match('/\bwp-image-([1-9][0-9]*)\b/',self::attribute($tag,'class'),$m)){$add($m[1],true,$url);continue;}
                $id=self::attribute($tag,'data-id');if(preg_match('/^[1-9][0-9]*$/D',$id)){$add((int)$id,true,$url);continue;}
                if($fallback){$add((int)$fallback,true,$url);$fallback=0;continue;}
                if(!array_key_exists($url,$resolved)){$resolved[$url]=self::url_id($url);}
                $add($resolved[$url],true,$url);
            }
        };
        $walk=null;$walk=function($blocks,$depth=0)use(&$walk,&$visited,&$blocks_seen,$add,$html){
            if($depth>12){throw new RuntimeException('正文嵌套过深，请手动选择同步图片。');}
            foreach($blocks as$block){
                if(++$blocks_seen>2048){throw new RuntimeException('正文区块超过扫描上限，请手动选择同步图片。');}
                $name=$block['blockName']??'';$attrs=$block['attrs']??array();$inner=$block['innerHTML']??'';
                // Attribute IDs are fallbacks, not a second occurrence of the same img.
                if(stripos($inner,'<img')===false){
                    if(($name==='core/image'||$name==='core/cover')&&!empty($attrs['id'])){$add($attrs['id']);}
                    if($name==='core/media-text'&&($attrs['mediaType']??'')==='image'){$add($attrs['mediaId']??0);}
                    if($name==='core/gallery'&&empty($block['innerBlocks'])){foreach(($attrs['ids']??array())as$id){$add($id);}}
                }
                if($name==='core/block'&&!empty($attrs['ref'])&&$depth<3&&!isset($visited[(int)$attrs['ref']])){
                    $visited[(int)$attrs['ref']]=true;$ref=get_post((int)$attrs['ref']);if($ref){$walk(parse_blocks($ref->post_content),$depth+1);}
                }
                $children=$block['innerBlocks']??array();$index=0;
                $fallback=in_array($name,array('core/image','core/cover'),true)?(int)($attrs['id']??0):($name==='core/media-text'&&($attrs['mediaType']??'')==='image'?(int)($attrs['mediaId']??0):0);
                foreach(($block['innerContent']??array($inner))as$part){if(is_string($part)){$html($part,$fallback);if(stripos($part,'<img')!==false){$fallback=0;}}elseif(isset($children[$index])){$walk(array($children[$index++]),$depth+1);}}
                while(isset($children[$index])){$walk(array($children[$index++]),$depth+1);}
            }
        };
        $content=(string)($post->post_content??'');if(strlen($content)>4*1024*1024){throw new RuntimeException('文章内容超过自动取图扫描上限，请手动选择同步图片。');}
        $walk(parse_blocks($content));
        // An unreadable body must never silently turn into one cover image.
        if(!$occurrences){$source='featured';$cover=get_post_thumbnail_id($post->ID);if($cover){$add($cover,false);}else{$source='none';}}
        return array('ids'=>$ids,'unavailable'=>$unavailable,'blocked'=>$blocked,'occurrences'=>$occurrences,'duplicates'=>$duplicates,'unique'=>$unique,'source'=>$source,'issues'=>$issues);
    }
    public static function maximum($selection) {
        if(in_array('xiaohongshu',$selection['platforms']??array(),true)&&($selection['xhs_format']??'images')!=='video'){return 9;}
        return in_array('weibo',$selection['platforms']??array('weibo','x'),true)&&($selection['weibo_format']??'images')!=='video'?9:4;
    }
    public static function hydrate($post,$selection) {
        if(($selection['media_mode']??'manual')!=='article'){return $selection;}
        $found=self::discover($post,(int)$selection['requested_by'],self::maximum($selection));$selection['images']=$found['ids'];
        if($found['blocked']){throw new Crolord_Publisher_Media_Error($found['issues']);}
        $selection['platform_images']=array('weibo'=>$found['ids'],'x'=>array_slice($found['ids'],0,4));
        if(in_array('xiaohongshu',$selection['platforms']??array(),true)){$selection['platform_images']['xiaohongshu']=$found['ids'];}
        $selection['media_audit']=array_diff_key($found,array('ids'=>true));
        $selection['media_warnings']=$found['blocked'];$selection['allow_empty_media']=true;return $selection;
    }
}
