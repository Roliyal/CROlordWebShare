<?php
if (!defined('ABSPATH')) { exit; }
final class Crolord_Publisher_Admin {
    public static function boot() {
        add_action('admin_menu', function () { add_options_page('Publisher', 'Publisher', 'manage_options', 'crolord-publisher', array(__CLASS__, 'settings')); add_management_page('Publisher', 'Publisher', 'crolord_publish_social', 'crolord-publisher-jobs', array(__CLASS__, 'dashboard')); });
        add_action('add_meta_boxes_post', function () { if (current_user_can('crolord_publish_social')) { add_meta_box('crolord-publisher', 'Publisher', array(__CLASS__, 'box'), 'post', 'normal', 'default'); } });
        add_action('admin_enqueue_scripts', array(__CLASS__, 'assets'));
        add_action('admin_post_crolord_publisher_accounts', array(__CLASS__, 'save_accounts'));
        add_action('admin_post_crolord_publisher_pack', array(__CLASS__, 'pack'));
        add_action('wp_ajax_crolord_publisher_status', array(__CLASS__, 'status'));
        add_action('wp_ajax_crolord_publisher_action', array(__CLASS__, 'action'));
    }
    public static function assets($hook) {
        $screen = get_current_screen();
        $editor = $screen && $screen->post_type === 'post' && in_array($hook, array('post.php','post-new.php'), true);
        if (!current_user_can('crolord_publish_social') || !($editor || strpos($hook, 'crolord-publisher') !== false)) { return; }
        if ($editor) { wp_enqueue_media(); }
        wp_enqueue_style('crolord-publisher', plugins_url('assets/admin.css', CROLORD_PUBLISHER_FILE), array(), CROLORD_PUBLISHER_VERSION);
        $dependencies=$editor?array('media-views'):array();if($editor&&$screen->is_block_editor()){$dependencies[]='wp-data';$dependencies[]='wp-editor';}
        wp_enqueue_script('crolord-publisher', plugins_url('assets/admin.js', CROLORD_PUBLISHER_FILE), $dependencies, CROLORD_PUBLISHER_VERSION, true);
        wp_localize_script('crolord-publisher', 'CrolordPublisher', array('ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('crolord_publisher_ajax'), 'timezone' => wp_timezone_string()));
        if(!$editor){wp_enqueue_script('crolord-publisher-xhs',plugins_url('assets/xiaohongshu.js',CROLORD_PUBLISHER_FILE),array('crolord-publisher'),CROLORD_PUBLISHER_VERSION,true);}
    }
    public static function parse_date($value) {
        $value = trim((string) $value);
        if ($value === '') { return 0; }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, wp_timezone());
        if (!$date || $date->format('Y-m-d\TH:i') !== $value) { throw new RuntimeException('定时日期无效，请按站点时区重新选择。'); }
        return $date->getTimestamp();
    }
    public static function selection($input,$previous=array()) {
        $previous=Crolord_Publisher_Automatic::defaults($previous);
        if (in_array('wechat', (array) ($input['cp_platforms'] ?? array()), true)) { throw new RuntimeException(Crolord_Publisher_Wechat_History::unavailable()->get_error_message()); }
        $platforms=isset($input['cp_platforms'])&&is_array($input['cp_platforms'])?array_values(array_intersect(array_keys(Crolord_Publisher_Platforms::selectable()),$input['cp_platforms'])):(isset($input['cp_choices_present'])?array():$previous['platforms']);
        $images = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) ($input['cp_images'] ?? ''))))));
        if (count($images) > 9) { throw new RuntimeException('公共图片库最多九张，请重新选择。'); }
        $images = array_values(array_filter($images, function ($id) { return wp_attachment_is_image($id) && current_user_can('edit_post', $id); }));
        $picks = array();
        foreach (Crolord_Publisher_Platforms::selectable() as $key => $label) {
            $maximum = $key === 'x' ? 4 : 9;
            if (empty($input['cp_choices_present'])) { $picks[$key] = array_slice($images, 0, $maximum); continue; }
            $requested = array_values(array_unique(array_filter(array_map('absint', explode(',', (string) ($input['cp_images_' . $key] ?? ''))))));
            if (count($requested) > $maximum) { throw new RuntimeException($label . ' 最多选择 ' . $maximum . ' 张图片。'); }
            $picks[$key] = array_values(array_intersect($requested, $images));
        }
        $value = array('platforms' => $platforms, 'title' => sanitize_text_field($input['cp_title'] ?? ''), 'automatic_schema'=>1, 'share_layout'=>'album', 'x_presentation'=>($input['cp_x_presentation']??$previous['x_presentation'])==='photos'?'photos':'card', 'auto_sync'=>isset($input['cp_auto_present'])?!empty($input['cp_sync_enabled']):!empty($previous['auto_sync']), 'media_mode'=>($input['cp_media_mode']??(isset($input['cp_images'])?'manual':$previous['media_mode']))==='manual'?'manual':'article', 'location' => '', 'place_id' => '', 'images' => $images, 'platform_images' => $picks, 'x_delivery' => 'buffer', 'weibo_delivery' => 'php', 'weibo_format' => (($input['cp_share_layout']??$previous['share_layout'])==='album'&&($input['cp_weibo_format']??'')==='video')?'video':'images', 'weibo_statement'=>Crolord_Publisher_Weibo::statement($input['cp_weibo_statement']??$previous['weibo_statement']), 'weibo_video_id'=>absint($input['cp_weibo_video']??0), 'weibo_video_title'=>sanitize_text_field($input['cp_weibo_video_title']??''), 'not_before' => self::parse_date($input['cp_schedule'] ?? ''));
        if(in_array('xiaohongshu',$platforms,true)){
            $value['xhs_title']=sanitize_text_field($input['cp_xhs_title']??$previous['xhs_title']??'');
            $text=$input['cp_xhs_text']??$previous['xhs_text']??'';
            if(!is_string($text)||strlen($text)>16000){throw new RuntimeException('小红书文案过大或格式无效。');}
            $value['xhs_text']=Crolord_Publisher_XHS::plain_text($text);
            $value['xhs_format']=($input['cp_xhs_format']??$previous['xhs_format']??'images')==='video'?'video':'images';
            $value['xhs_video_id']=absint($input['cp_xhs_video']??$previous['xhs_video_id']??0);
            $value['xhs_cover_id']=absint($input['cp_xhs_cover']??$previous['xhs_cover_id']??0);
        }
        return $value;
    }
    private static function xhs_fields($value) {
        $video=($value['xhs_format']??'images')==='video';
        echo '<div class="cp-xhs-editor" '.(in_array('xiaohongshu',$value['platforms'],true)?'':'hidden').'><div class="cp-fields"><label>小红书标题<input name="cp_xhs_title" value="'.esc_attr($value['xhs_title']??'').'" placeholder="默认使用分享标题，最多 20 字"><span class="description">标题只出现一次，正文不再重复，文章链接始终置于文案末尾。</span></label><label>小红书媒体<select name="cp_xhs_format"><option value="images" '.selected($video,false,false).'>前九张原图</option><option value="video" '.selected($video,true,false).'>单个 MP4 ＋封面</option></select></label></div><label class="cp-xhs-text-label">小红书文案 · 可留空自动生成<textarea name="cp_xhs_text" rows="5" placeholder="留空：自动提取文章摘要或正文前 300 字、最多三段；纯图片文章只附原文链接。填写后使用此处文案，末尾自动追加文章网址。">'.esc_html($value['xhs_text']??'').'</textarea><span class="description">保留自然分段；文案与自动追加的链接合计最多 1000 字。只影响后续新任务。</span></label><div class="cp-xhs-video" '.($video?'':'hidden').'><input type="hidden" name="cp_xhs_video" value="'.(int)($value['xhs_video_id']??0).'"><input type="hidden" name="cp_xhs_cover" value="'.(int)($value['xhs_cover_id']??0).'"><div class="cp-xhs-video-preview">';
        if(!empty($value['xhs_video_id'])){echo '<video controls preload="none" src="'.esc_url(wp_get_attachment_url($value['xhs_video_id'])).'"></video>';}
        echo '</div><button type="button" class="button cp-xhs-video-choose">选择小红书 MP4</button><div class="cp-xhs-cover-preview">';
        if(!empty($value['xhs_cover_id'])){echo wp_get_attachment_image($value['xhs_cover_id'],'thumbnail');}
        echo '</div><button type="button" class="button cp-xhs-cover-choose">选择视频封面</button></div></div>';
    }
    public static function box($post) {
        $value=Crolord_Publisher_Automatic::defaults(get_post_meta($post->ID,'_crolord_publisher_selection',true));
        wp_nonce_field('crolord_publisher_edit_'.$post->ID,'crolord_publisher_nonce');
        echo '<div class="cp-workspace cp-auto-workspace" data-post="'.esc_attr($post->ID).'" data-url="'.esc_url(get_permalink($post)).'"><p class="cp-intro">发布文章，同时分享到已选择的平台。</p><input type="hidden" name="cp_auto_present" value="1"><label class="cp-auto-toggle"><input type="checkbox" name="cp_sync_enabled" value="1" '.checked(!empty($value['auto_sync']),true,false).'> 自动同步</label><p class="description">微博保持正文多图；X 默认在主帖分享标题与网页卡片。</p><input type="hidden" name="cp_share_layout" value="album"><div class="cp-fields"><label>同步时间 · '.esc_html(wp_timezone_string()).'<input type="datetime-local" name="cp_schedule" value="'.esc_attr($value['not_before']?wp_date('Y-m-d\TH:i',$value['not_before']):'').'"><span class="description">留空跟随文章发布，也可设为更晚的时间。</span></label></div><p><button type="button" class="button cp-preview-button">预览同步内容</button></p><details class="cp-adjust"><summary>调整本次同步</summary><div class="cp-platforms">';
        foreach(Crolord_Publisher_Platforms::selectable()as$key=>$label){echo '<label><input type="checkbox" name="cp_platforms[]" value="'.esc_attr($key).'" '.checked(in_array($key,$value['platforms'],true),true,false).'> '.esc_html($label).'</label>';}
        echo '</div><input type="hidden" name="cp_choices_present" value="1"><div class="cp-fields"><label>分享标题<input name="cp_title" value="'.esc_attr($value['title']).'" placeholder="自动使用文章标题"></label><label>图片来源<select name="cp_media_mode"><option value="article" '.selected($value['media_mode'],'article',false).'>按正文顺序自动读取</option><option value="manual" '.selected($value['media_mode'],'manual',false).'>手动调整图片</option></select></label><label>X 展示<select name="cp_x_presentation"><option value="card" '.selected($value['x_presentation'],'card',false).'>主帖网页卡片</option><option value="photos" '.selected($value['x_presentation'],'photos',false).'>前四张原图＋正文网址</option></select><span class="description">网页卡片使用文章封面；四图模式保留正文网址。</span></label><label>微博媒体<select name="cp_weibo_format"><option value="images" '.selected($value['weibo_format'],'images',false).'>图片</option><option value="video" '.selected($value['weibo_format'],'video',false).'>单个 MP4</option></select></label></div><div class="cp-weibo-video" '.($value['weibo_format']==='video'?'':'hidden').'><input type="hidden" name="cp_weibo_video" value="'.(int)$value['weibo_video_id'].'"><label>视频标题<input name="cp_weibo_video_title" value="'.esc_attr($value['weibo_video_title']).'"></label><div class="cp-weibo-video-preview">';
        if($value['weibo_video_id']){echo '<video controls preload="none" src="'.esc_url(wp_get_attachment_url($value['weibo_video_id'])).'"></video>';}
        echo '</div><button type="button" class="button cp-weibo-video-choose">选择 MP4</button></div>';
        self::xhs_fields($value);
        echo '<div class="cp-manual-images" '.($value['media_mode']==='manual'?'':'hidden').'><input type="hidden" name="cp_images" value="'.esc_attr(implode(',',$value['images'])).'"><div class="cp-images">';
        foreach($value['images']as$id){echo '<figure data-id="'.(int)$id.'">'.wp_get_attachment_image($id,'thumbnail').'<button type="button" class="cp-remove" aria-label="移除图片">×</button></figure>';}
        echo '</div><div class="cp-toolbar"><button type="button" class="button cp-choose">管理图片</button><button type="button" class="button cp-import">读取正文图片</button></div><div class="cp-pick-groups">';
        foreach(Crolord_Publisher_Platforms::selectable()as$key=>$label){$max=$key==='x'?4:9;$chosen=$value['platform_images'][$key]??array_slice($value['images'],0,$max);echo '<fieldset class="cp-picks" data-platform="'.esc_attr($key).'" data-maximum="'.$max.'" '.(in_array($key,$value['platforms'],true)?'':'hidden').'><legend>'.esc_html($label).' · 最多 '.$max.' 张</legend><input type="hidden" name="cp_images_'.esc_attr($key).'" value="'.esc_attr(implode(',',$chosen)).'"><div class="cp-pick-list">';foreach($value['images']as$i=>$id){echo '<label><input type="checkbox" value="'.(int)$id.'" '.checked(in_array($id,$chosen,true),true,false).'>'.wp_get_attachment_image($id,'thumbnail').'<span>'.($i+1).'</span></label>';}echo '</div></fieldset>';}
        echo '</div></div><p><button type="button" class="button cp-enqueue">为已公开文章补建任务</button></p></details><p class="cp-notice" role="status"></p><div class="cp-previews" hidden></div><div class="cp-slug-host">'.Crolord_Publisher_Poetic_Slug::summary($post->ID)['html'].'</div><div class="cp-status" aria-live="polite">';self::rows(Crolord_Publisher_Store::jobs($post->ID),$post->ID);echo '</div></div>';
    }
    public static function states() { return array('preparing'=>'正在准备链接与媒体','prepare_failed'=>'准备失败 · 尚未提交','sdk_pending'=>'平台已接收 · 核对朋友圈详情','sdk_paused'=>'朋友圈详情待核对 · 已暂停','phone_queued'=>'等待安卓真机','phone_running'=>'真机处理微信界面','phone_blocked'=>'真机已停止 · 请检查','phone_unknown'=>'微信发表结果待核对','phone_verified'=>'真机界面已核验 · 无官方回执','scheduled'=>'定时等待','cancelled'=>'已取消','queued'=>'等待同步','running'=>'上传图片 / 核对账号','publishing'=>'正在发布','external_pending'=>'Buffer 已接收 · 等待 X 回执','external_failed'=>'Buffer 发布失败','external_paused'=>'Buffer 状态待核对','succeeded'=>'发布成功','failed'=>'同步失败','unknown'=>'结果待核对','manual_ready'=>'素材已准备 · 需手动发布','manual_recorded'=>'已手动登记'); }
    public static function preparation($post_id,$jobs) {
        if (array_filter($jobs, array('Crolord_Publisher_Preparation', 'is_reservation'))) { return false; }
        $error=$post_id?get_post_meta($post_id,'_crolord_publisher_error',true):'';
        if(!$error||!Crolord_Publisher_Poetic_Slug::ready($post_id)){return false;}
        $post=get_post($post_id);$selection=Crolord_Publisher_Automatic::defaults(get_post_meta($post_id,'_crolord_publisher_selection',true));
        // Re-evaluate the old generic error locally, without uploading files,
        // changing the article or contacting a publishing provider.
        if(!$jobs&&$post&&$selection['media_mode']==='article'&&(strpos($error,'正文有 ')===0||strpos($error,'图片检查未通过')===0)){
            try{$found=Crolord_Publisher_Media::discover($post,get_current_user_id(),Crolord_Publisher_Media::maximum($selection));
                $error=$found['blocked']?(new Crolord_Publisher_Media_Error($found['issues']))->getMessage():'上次未能建立同步任务；本次已识别到 '.count($found['ids']).' 张可读取图片，可以重新检查并同步。';
            }catch(Throwable $e){$error=$e->getMessage();}
        }
        echo '<div class="cp-preparation-error" role="status"><p class="cp-error">'.esc_html($error).'</p>';
        if(!$jobs&&$post&&$post->post_status==='publish'&&!$post->post_password){echo '<p>文章已公开，同步任务尚未建立。</p><button type="button" class="button cp-enqueue cp-media-retry">重新检查图片并同步</button>';}
        echo '</div>';return true;
    }
    public static function rows($jobs,$post_id=0) {
        if (Crolord_Publisher_Store::read_failed()) { echo '<p class="cp-error" role="alert">发布数据表暂时无法读取；当前列表不代表任务已被删除。请检查数据库或插件升级状态。</p>'; }
        $preparation=self::preparation($post_id,$jobs);
        $legacy_preparation = !$post_id && self::preparation_issues();
        if (!$jobs && ($preparation || $legacy_preparation || Crolord_Publisher_Store::read_failed())) { return; }
        if (!$jobs) { echo '<p class="description">暂时没有同步任务。</p>'; return; }
        foreach ($jobs as $job) {
            $name = Crolord_Publisher_Platforms::names()[$job['platform']]; $states = self::states(); $ids = json_decode($job['remote_ids'], true) ?: array();
            $payload=json_decode($job['payload'],true)?:array();$progress=json_decode($job['progress'],true)?:array();
            $state_label = $job['platform'] === 'wechat' ? '旧通道已停用' : ($states[$job['state']] ?? $job['state']);
            if($job['platform']==='weibo'&&$job['state']==='succeeded'&&Crolord_Publisher_Platforms::card($payload)&&($progress['weibo']['card']['state']??'')!=='recognized'){$state_label='已发布 · 卡片未确认';}
            if($job['platform']==='x'&&$job['state']==='external_paused'){ $observed=json_decode($job['progress'],true)?:array();if(($observed['buffer']['status']??'')==='sent'){$state_label='已发送 · 回执待核对';}}
            if($job['platform']==='weibo'&&$job['state']==='external_pending'){$state_label='微博处理媒体 / 核对发布回执';}
            if($job['platform']==='weibo'&&$job['state']==='external_paused'){$state_label='微博回执待核对 · 已暂停';}
            if($job['platform']==='xiaohongshu'&&$job['state']==='external_pending'){$state_label='小红书笔记已提交 · 核对实际内容';}
            if($job['platform']==='xiaohongshu'&&$job['state']==='external_paused'){$state_label='小红书回执待核对 · 已暂停';}
            if($job['platform']==='xiaohongshu'&&$job['state']==='xhs_recorded'){$state_label='创作中心已收录 · 详情未完整核验';}
            if($job['platform']==='xiaohongshu'&&$job['state']==='xhs_session_wait'){$state_label=Crolord_Publisher_XHS_Session::info()['label'].' · 任务已保留';}
            if($job['platform']==='xiaohongshu'&&$job['state']==='unknown'&&Crolord_Publisher_XHS_Admin::publish_blocked($job,$progress)){$state_label='发表请求被拦截 · 结果待核对';}
            $xhs_held = Crolord_Publisher_XHS::locally_held($job);
            if ($xhs_held) { $state_label = '尚未发送 · 已保留任务'; }
            echo '<article class="cp-job" data-platform="' . esc_attr($job['platform']) . '" data-job="' . esc_attr($job['id']) . '" data-state="' . esc_attr($job['state']) . '"><div><strong>' . esc_html($name) . '</strong><span class="cp-state">' . esc_html($state_label) . '</span></div>';
            echo '<p><a href="' . esc_url(get_edit_post_link($job['post_id'])) . '">' . esc_html(get_the_title($job['post_id'])) . '</a></p>';
            $buffer_id = $progress['buffer']['id'] ?? '';
            if (Crolord_Publisher_Preparation::is_reservation($job)) {
                if ($job['error_text']) { echo '<p class="cp-error">' . esc_html($job['error_text']) . '</p>'; }
                $post_error = get_post_meta($job['post_id'], '_crolord_publisher_error', true);
                if ($job['state'] !== 'cancelled' && $post_error && $post_error !== $job['error_text']) { echo '<p class="cp-error">' . esc_html($post_error) . '</p>'; }
                echo '<p class="description">准备完成后自动同步。</p>';
                if ($job['state'] !== 'cancelled') {
                    $slug = get_post_meta($job['post_id'], Crolord_Publisher_Poetic_Slug::META, true);
                    if (($slug['status'] ?? '') === 'failed') { echo '<button type="button" class="button cp-slug-retry" data-post="' . (int) $job['post_id'] . '">重新生成英文链接</button>'; }
                    echo '<button type="button" class="button cp-action" data-operation="prepare_retry">重新准备此文章任务</button> <button type="button" class="button cp-action" data-operation="cancel">取消此平台同步</button>';
                }
                echo '</article>'; continue;
            }
            if($job['platform']==='xiaohongshu'){Crolord_Publisher_XHS_Admin::row($job,$payload,$progress,$ids);}elseif($job['platform']!=='wechat'){self::media_counts($payload,$progress,$job['platform']);}
            if ($job['platform'] === 'wechat') {
                echo '<p class="cp-history-note">历史记录 · 仅供查看。原状态：' . esc_html($states[$job['state']] ?? $job['state']) . '</p>';
                if (!empty($payload['not_before'])) { echo '<p class="cp-time">原计划：' . esc_html(wp_date('Y-m-d H:i', (int) $payload['not_before'])) . '</p>'; }
                if ($job['error_text']) { echo '<p class="cp-error">原记录：' . esc_html($job['error_text']) . '</p>'; }
                if ($ids) { echo '<p>已存平台 ID：' . esc_html(implode(' · ', $ids)) . '</p>'; }
                $candidate = $progress['sdk']['id'] ?? '';
                if ($candidate && !$ids) { echo '<p>历史待核对 ID：' . esc_html($candidate) . '（未改记为成功）</p>'; }
                if (in_array($job['state'], array('manual_ready', 'manual_recorded'), true)) {
                    $url = wp_nonce_url(admin_url('admin-post.php?action=crolord_publisher_pack&job=' . $job['id']), 'cp_pack_' . $job['id']);
                    echo '<a class="button" href="' . esc_url($url) . '">导出历史素材</a>';
                }
                echo '<button type="button" class="button cp-action" data-operation="copy">复制历史文案</button></article>';
                continue;
            }
            if($job['platform']==='weibo'&&(Crolord_Publisher_Weibo_Browser::route($payload)||Crolord_Publisher_Weibo_API::route($payload)||Crolord_Publisher_Weibo::web($payload)||Crolord_Publisher_Weibo_Native::route($payload))){
                if(!empty($payload['media_warnings'])){echo '<p class="description">部分正文图片无法读取，本任务仅包含可用的媒体库图片。</p>';}
                if(Crolord_Publisher_Weibo_Native::route($payload)&&!empty($payload['weibo_statement'])){
                    try{$declaration=Crolord_Publisher_Weibo::STATEMENTS[Crolord_Publisher_Weibo::statement($payload['weibo_statement']??0)];}catch(Throwable $e){$declaration='原声明无效，请重新选择';}
                    echo '<p class="cp-declaration">内容声明：'.esc_html($declaration).' · 使用本任务保存的选项</p>';
                }
                $weibo_progress=$progress[Crolord_Publisher_Weibo_Browser::route($payload)?'weibo_browser':(Crolord_Publisher_Weibo_API::route($payload)?'weibo_api':'weibo')]??array();
                if(!empty($weibo_progress['phase'])){$phase=$weibo_progress['phase'];$labels=array('account'=>'核对微博账号','media'=>'下载核验原始媒体','composer'=>'填写文案','uploading'=>'浏览器正在上传媒体','checking'=>'核对完整文案与媒体','verifying'=>'读取同一条微博回执','video_init'=>'已建立视频上传','video_uploaded'=>'视频已上传，准备转码','video_processing'=>'微博正在处理视频','video_ready'=>'视频已就绪','publishing'=>'正在发表微博','receipt'=>'已取得微博 ID，核对内容','verified'=>'账号、文案与媒体核对通过','link_comment'=>'主帖已发表，核对最后的链接评论');$phase_label=$labels[$phase]??(strpos($phase,'images_')===0?'已上传 '.(int)substr($phase,7).' / '.count($payload['images']).' 张图片':$phase);if($phase==='verified'&&Crolord_Publisher_Platforms::card($payload)&&($weibo_progress['card']['state']??'')!=='recognized'){$phase_label='账号与正文已核对，封面卡片未确认';}echo '<p>进度：'.esc_html($phase_label).'</p>';}
                if(!empty($weibo_progress['link_rejected'])&&empty($weibo_progress['link_comment'])){echo '<button type="button" class="button cp-action" data-operation="weibo_tail_retry">仅重试链接评论</button>';}
                if(!empty($weibo_progress['url'])){echo '<p><a target="_blank" rel="noopener noreferrer" href="'.esc_url($weibo_progress['url']).'">查看该条微博</a></p>';}
                if((Crolord_Publisher_Weibo_Browser::route($payload)||Crolord_Publisher_Weibo_Native::route($payload))&&$ids&&!in_array($job['state'],array('running','publishing'),true)&&($job['state']!=='succeeded'||(Crolord_Publisher_Platforms::card($payload)&&($progress['weibo']['card']['state']??'')!=='recognized'))){echo '<button class="button cp-action" type="button" data-operation="weibo_check">检查同一条微博</button>';}
            }
            if($job['platform']==='weibo'&&!Crolord_Publisher_Weibo_Native::route($payload)&&!$ids&&empty($progress['weibo_browser']['publish_started'])&&empty($progress['weibo']['publish_started'])&&empty($progress['weibo_api']['publish_started'])&&in_array($job['state'],array('manual_ready','failed','cancelled','queued','scheduled'),true)){echo '<p>旧通道已停用。先保存原文章的新同步设置，再显式迁移；不会自动重发。</p><button class="button cp-action" type="button" data-operation="weibo_php_migrate">迁移此任务到插件内通道</button>';}
            if ($buffer_id) {
                if(!empty($progress['buffer']['external_link'])){echo '<p><a target="_blank" rel="noopener noreferrer" href="'.esc_url($progress['buffer']['external_link']).'">查看这条 X</a></p>';}
                echo '<p>Buffer ID：<code>' . esc_html($buffer_id) . '</code> · <a href="https://publish.buffer.com/" target="_blank" rel="noopener noreferrer">打开 Buffer</a></p>';
                if (!in_array($job['state'],array('succeeded','running','publishing'),true)) { echo '<button type="button" class="button cp-action" data-operation="buffer_check">检查发布状态</button>'; }
                if ($job['state']==='external_failed') { echo '<button type="button" class="button cp-action" data-operation="buffer_retry">重试同一 Buffer 帖</button>'; }
            }
            if (!empty($payload['not_before'])) { echo '<p class="cp-time">计划：' . esc_html(wp_date('Y-m-d H:i', (int) $payload['not_before']) . ' · ' . wp_timezone_string()) . '</p>'; }
            if ($job['error_text']) { echo '<p class="cp-error">' . esc_html($job['platform'] === 'xiaohongshu' ? Crolord_Publisher_XHS_Admin::queue_error($job) : $job['error_text']) . '</p>'; }
            if ($ids) { echo '<p>平台 ID：' . esc_html(implode(' · ', $ids)) . '</p>'; }
            if (($job['runner']??'php')!=='browser'&&!($job['platform']==='xiaohongshu'&&$ids)&&($job['state'] === 'queued' || (in_array($job['state'],array('external_pending'),true) && (int)$job['available']<=time()))) { echo '<button type="button" class="button cp-run">继续处理此任务</button>'; }
            if ($xhs_held) { echo '<p class="description">本条没有提交发表请求，无需填写笔记 ID 或确认未发表。只恢复这一条，保留原图、文案和排期。</p><button type="button" class="button cp-action" data-operation="xhs_retry_unsent">恢复这一条同步</button>'; }
            elseif ($job['state'] === 'failed' && !$buffer_id) { echo '<button type="button" class="button cp-action" data-operation="retry">重试未完成部分</button>'; if (!$ids) { echo '<button type="button" class="button cp-action" data-operation="refresh">采用当前设置并重试</button>'; } }
            if (!$ids && !$buffer_id && in_array($job['state'], array('scheduled','queued','failed','manual_ready','cancelled'), true)) {
                echo '<div class="cp-schedule-controls"><label>重新排期<input type="datetime-local" class="cp-job-date" value="' . esc_attr(!empty($payload['not_before']) ? wp_date('Y-m-d\TH:i', (int) $payload['not_before']) : '') . '"></label><button type="button" class="button cp-action" data-operation="reschedule">保存排期</button>';
                if ($job['state'] !== 'cancelled') { echo '<button type="button" class="button cp-action" data-operation="cancel">取消同步</button>'; }
                echo '</div>';
            }
            if ($job['state'] === 'unknown' && !$buffer_id) {
                if ($job['platform'] === 'xiaohongshu') { Crolord_Publisher_XHS_Admin::unknown($job, $progress); }
                else {
                $where=($payload['x_route']??'')==='buffer'?'我已在 Buffer 和 X 两处核对，确认没有此帖或待发布任务':'我已核对平台，确认当前这一帖没有发布';
                echo '<p>先在平台核对。已经保存的平台 ID 不会重发；当前未知的一帖必须确认。</p><label>已发布的当前帖 ID <input class="cp-receipt" '.($job['platform']==='xiaohongshu'?'':'inputmode="numeric"').' placeholder="平台文章 ID"></label><button type="button" class="button cp-action" data-operation="reconcile">登记已发布 ID</button><label><input type="checkbox" class="cp-absent">'.esc_html($where).'</label><button type="button" class="button cp-action" data-operation="reset_unknown">确认未发布后重试</button>';
                }
            }
            if (in_array($job['state'], array('manual_ready','manual_recorded'), true)) {
                echo '<p class="cp-history-note">旧手动任务保留供核对，不会自动改为新发布。</p>';
                if ($job['platform'] === 'x') { echo '<p><a href="https://x.com/compose/post" target="_blank" rel="noopener noreferrer">打开 X 编辑器</a> · 此为旧手动任务。</p>'; }
                if ($job['platform'] === 'weibo') { echo '<p><a href="https://weibo.com/" target="_blank" rel="noopener noreferrer">打开微博</a> · 此为旧手动任务。</p>'; }
                $url = wp_nonce_url(admin_url('admin-post.php?action=crolord_publisher_pack&job=' . $job['id']), 'cp_pack_' . $job['id']);
                echo '<a class="button" href="' . esc_url($url) . '">下载文案与原图</a><button type="button" class="button cp-action" data-operation="copy">复制文案</button><button type="button" class="button cp-action" data-operation="copy_title">复制标题</button><button type="button" class="button cp-action" data-operation="copy_link">复制链接</button>';
                if ($job['state'] === 'manual_ready') { echo '<label>发布凭据（可选）<input class="cp-receipt" placeholder="微博 ID 或朋友圈发布时间"></label><button type="button" class="button cp-action" data-operation="record">我已手动发布，登记完成</button>'; }
            }
            echo '</article>';
        }
    }
    public static function settings() {
        if (!current_user_can('manage_options')) { return; }
        echo '<div class="wrap cp-settings"><h1>Publisher</h1><p>记录在此，也分享在别处。</p>';
        Crolord_Publisher_Weibo_Admin::render();
        Crolord_Publisher_XHS_Admin::render();
        Crolord_Publisher_Buffer::render();
        echo '<section><h2>连接状态</h2><button type="button" class="button cp-environment-test">检查后台队列连接</button><p class="cp-diagnostic-result" role="status"></p></section></div>';

    }
    /** Retained hook for old forms; credential writes are deliberately disabled. */
    public static function save_accounts() {
        if (!current_user_can('manage_options')) { wp_die('没有权限。', '', array('response'=>403)); }
        check_admin_referer('cp_accounts');
        wp_die('旧授权方式已停用，请返回 Publisher 连接 Buffer。已有凭据保留。', '', array('response'=>410));
    }
    private static function media_counts($payload,$progress,$platform) {
        if(Crolord_Publisher_Platforms::card($payload)){
            echo '<p class="cp-media-counts">正文网页卡片请求 · 未上传相册 · 不另发网址评论／回复</p>';
            if($platform==='weibo'&&!empty($progress['weibo']['main_verified'])){
                echo '<p class="description">正文标题与本文链接已核对。'.(($progress['weibo']['card']['state']??'')==='recognized'?'微博回执已返回网页卡片。':'目前没有明确的封面卡片回执。旧卡片模式只发标题和网址，没有上传配图；新版后续任务使用正文配图，原帖不会自动重发。').'</p>';
            }elseif($platform==='x'){echo '<p class="description">'.(Crolord_Publisher_Platforms::main_card($payload)?'标题与本文链接位于同一主帖，网页卡片由 X 抓取生成，显示可能受平台缓存影响。':'这是旧卡片记录，保留原 ID，不会自动重发。').'</p>';}
            return;
        }
        $audit=$payload['media_audit']??array();$count=count($payload['images']??array());
        if($audit){
            $source=($audit['source']??'')==='featured'?'特色图回退':('正文 '.(int)($audit['occurrences']??0).' 处 · 不同图片 '.(int)($audit['unique']??0).' 张 · 重复 '.(int)($audit['duplicates']??0).' 处');
            echo '<p class="cp-media-audit">'.esc_html($source).'</p>';
        }
        if($platform==='weibo'){
            $uploaded=count($progress['weibo']['pictures']??array());$received=$progress['weibo']['receipt_images']??null;
            if(($payload['weibo_format']??'')==='video'){echo '<p class="cp-media-counts">单个视频 · '.(!empty($progress['weibo']['main_verified'])?'最终视频已核对':'上传与转码进度见下方').'</p>';}
            else{echo '<p class="cp-media-counts">计划 '.$count.' 张 · 已上传 '.$uploaded.' 张 · 最终附件 '.($received===null?'待核对':(int)$received.' 张').'</p>';}
            if(!empty($progress['weibo']['link_verified'])){echo '<p class="description">最后的文章链接评论已核对。</p>';}
        }else{
            $sent=$progress['buffer_media']['submitted']??null;$received=$progress['buffer_media']['receipt']??null;
            echo '<p class="cp-media-counts">计划 '.$count.' 张 · 已提交 '.($sent===null?'待提交':(int)$sent.' 张').' · 已发媒体回执 '.($received===null?'待核对':(int)$received.' 张').'（Buffer）</p>';
        }
        if(Crolord_Publisher_Platforms::link_last($payload)){echo '<p class="description">标题与图片在主帖；网址在'.($platform==='x'?'最后一条回复':'图片下方的评论').'。</p>';}
    }
    public static function store_accounts($input) { return Crolord_Publisher_Platforms::retired(); }
    /** Bounded admin-only explanation of legacy preparations that never made a queue row. */
    private static function preparation_issues() {
        $ids = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'fields' => 'ids', 'posts_per_page' => 10, 'orderby' => 'ID', 'order' => 'DESC', 'no_found_rows' => true, 'suppress_filters' => false, 'meta_query' => array('relation' => 'OR', array('key' => Crolord_Publisher_Automatic::PENDING, 'compare' => 'EXISTS'), array('key' => '_crolord_publisher_error', 'compare' => 'EXISTS')))); $shown = false;
        foreach ($ids as $id) {
            if (!current_user_can('edit_post', $id) || Crolord_Publisher_Store::jobs($id)) { continue; }
            $post = get_post($id); if (!$post || $post->post_password) { continue; }
            $pending = get_post_meta($id, Crolord_Publisher_Automatic::PENDING, true); $error = get_post_meta($id, '_crolord_publisher_error', true);
            if (!$pending && !$error) { continue; }
            $shown = true;
            echo '<article class="cp-preparation-issue"><p><strong>同步准备尚未完成</strong> · <a href="' . esc_url(get_edit_post_link($id)) . '">' . esc_html(get_the_title($id)) . '</a></p><p class="cp-error">' . esc_html($error ?: '发布请求未完成同步设置确认；原准备记录已保留。') . '</p><button type="button" class="button cp-recover-post" data-post="' . (int) $id . '">恢复这篇文章的准备任务</button></article>';
        }
        return $shown;
    }
    public static function dashboard() {
        Crolord_Publisher_Automatic::recover_pending();
        echo '<div class="wrap cp-workspace" data-post="0"><h1>Publisher</h1><div class="cp-status">';
        $jobs = Crolord_Publisher_Store::latest_jobs(); $jobs = array_values(array_filter($jobs, function ($job) { return current_user_can('edit_post', $job['post_id']); })); self::rows($jobs,$jobs?(int)$jobs[0]['post_id']:0); echo '</div></div>';
    }
    public static function authorize_job($id) {
        $job = Crolord_Publisher_Store::get($id);
        if (!$job || !current_user_can('crolord_publish_social') || !current_user_can('edit_post', $job['post_id'])) { wp_die('没有权限。', '', array('response'=>403)); }
        return $job;
    }
    public static function status() {
        check_ajax_referer('crolord_publisher_ajax', 'nonce');
        if (!current_user_can('crolord_publish_social')) { wp_send_json_error(array('message'=>'没有权限。'),403); }
        $post = absint($_POST['post'] ?? 0); if ($post && !current_user_can('edit_post', $post)) { wp_send_json_error(array('message'=>'没有权限。'),403); }
        if (!$post) { Crolord_Publisher_Automatic::recover_pending(); }
        if($post){Crolord_Publisher_Poetic_Slug::work($post);}
        $slug=$post?Crolord_Publisher_Poetic_Slug::summary($post):array('active'=>false,'html'=>'');
        $jobs = array_values(array_filter($post ? Crolord_Publisher_Store::jobs($post) : Crolord_Publisher_Store::latest_jobs(), function ($job) { return current_user_can('edit_post', $job['post_id']); }));
        ob_start(); self::rows($jobs,$post?:($jobs?(int)$jobs[0]['post_id']:0));
        $html = ob_get_clean();
        $busy = count(array_filter($jobs,function ($job) { return $job['platform'] !== 'wechat' && in_array($job['state'],array('queued','running','publishing'),true); }));
        $scheduled = count(array_filter($jobs,function ($job) { return $job['platform'] !== 'wechat' && $job['state'] === 'scheduled'; }));
        $scheduled += count(array_filter($jobs,function ($job) { return $job['platform'] !== 'wechat' && in_array($job['state'], array('external_pending', 'preparing'), true); }));
        if (!Crolord_Publisher_XHS_Session::info()['needs_user']) { $scheduled += count(array_filter($jobs, function ($job) { return $job['platform'] === 'xiaohongshu' && $job['state'] === 'xhs_session_wait'; })); }
        wp_send_json_success(array('html'=>$html, 'slug'=>$slug,'active'=>$busy + $scheduled + (int)$slug['active'], 'scheduledOnly'=>!$slug['active']&&!$busy && $scheduled > 0));
    }
    public static function action() {
        check_ajax_referer('crolord_publisher_ajax', 'nonce');
        $job = self::authorize_job(absint($_POST['job'] ?? 0)); $operation = sanitize_key($_POST['operation'] ?? '');
        if (in_array($operation,array('copy','copy_title','copy_link'),true)) { $payload = json_decode($job['payload'],true); $text=$operation==='copy_title'?$payload['title']:($operation==='copy_link'?$payload['url']:Crolord_Publisher_Platforms::text($payload)); wp_send_json_success(array('text'=>$text)); }
        if ($job['platform'] === 'wechat') { wp_send_json_error(array('message'=>Crolord_Publisher_Wechat_History::unavailable()->get_error_message()),410); }
        global $wpdb; $table = Crolord_Publisher_Store::table(); $changed = 0;
        $progress = json_decode($job['progress'],true) ?: array();
        if (!empty($progress['buffer']['id']) && in_array($operation,array('cancel','reschedule','retry','refresh','reset_unknown'),true)) { wp_send_json_error(array('message'=>'Buffer 已接收此帖，请使用同一 Buffer 任务的检查/重试入口；未创建重复任务。'),409); }
        $payload=json_decode($job['payload'],true);
        if (Crolord_Publisher_Preparation::is_reservation($job)) {
            try { Crolord_Publisher_Preparation::action($job, $operation); Crolord_Publisher::kick(); }
            catch (Throwable $e) { wp_send_json_error(array('message' => $e->getMessage()), 409); }
            wp_send_json_success(array('message' => '准备任务已更新，已有发布记录保持原状。'));
        }
        if ($job['platform']==='weibo') {
            try{$handled=Crolord_Publisher_Weibo_Native::action($job,$operation);if($handled!==null){Crolord_Publisher::kick();wp_send_json_success(array('message'=>'微博任务已更新，由插件 PHP 后台队列继续处理。'));}
                if(!Crolord_Publisher_Weibo_Native::route($payload)){throw new RuntimeException('旧通道已停用；仅通过“迁移此任务到插件内通道”迁移确定未发出的任务。已有 ID、结果未知或已有发表意图的旧任务不换通道重发。');}
            }catch(Throwable $e){wp_send_json_error(array('message'=>$e->getMessage()),409);}
        }
        if ($job['platform']==='xiaohongshu') {
            try{$handled=Crolord_Publisher_XHS::action($job,$operation);if($handled!==null){Crolord_Publisher::kick();wp_send_json_success(array('message'=>'小红书任务已更新，后台继续处理同一任务。'));}}
            catch(Throwable $e){wp_send_json_error(array('message'=>$e->getMessage()),409);}
        }
        if (in_array($operation,array('buffer_check','buffer_retry'),true)) { $changed=Crolord_Publisher_Buffer::resume($job,$operation==='buffer_retry'); }
        elseif (in_array($operation, array('cancel','reschedule'), true)) {
            $allowed = array('scheduled','queued','failed','manual_ready','cancelled');
            if (!in_array($job['state'], $allowed, true) || json_decode($job['remote_ids'], true)) { wp_send_json_error(array('message'=>'任务已开始发送或已有平台 ID，不能改期或取消。'),409); }
            $values = array('state'=>'cancelled','lease'=>'','touched'=>time());
            $payload = json_decode($job['payload'], true);
            if ($operation === 'reschedule') {
                try { $time = self::parse_date(wp_unslash($_POST['date'] ?? '')); } catch (Throwable $e) { wp_send_json_error(array('message'=>$e->getMessage()),400); }
                $progress = json_decode($job['progress'], true) ?: array();
                if ((int)($progress['retry_after']??0)>max(time(),$time)) { wp_send_json_error(array('message'=>'平台限流等待时间尚未结束，请选择更晚的时间。'),409); }
                $payload['not_before']=$time; $valid=Crolord_Publisher_Platforms::validate($job['platform'],$payload);
                if (is_wp_error($valid)) { wp_send_json_error(array('message'=>$valid->get_error_message() . ' 请先保存文章设置，再采用当前设置重试。'),400); }
                $values['state']=$time>time()?'scheduled':Crolord_Publisher::waiting_state($job['platform'],$payload);
                $values['available']=max(time(),$time); $values['payload']=wp_json_encode($payload); $values['error_text']='';
            }
            $changed=$wpdb->update($table,$values,array('id'=>$job['id'],'state'=>$job['state'],'lease'=>$job['lease']));
            if ($changed) { Crolord_Publisher::due($job['id'],$operation==='reschedule'?$time:0); }
        }
        elseif ($operation === 'retry') {
            $payload = json_decode($job['payload'],true);
            if ($job['state'] === 'failed' && empty($payload['account'])) {
                $account = Crolord_Publisher_Platforms::config($job['platform']);
                if (!empty($account['uid'])) { $payload['account']=$account['uid']; $wpdb->update($table,array('payload'=>wp_json_encode($payload)),array('id'=>$job['id'],'state'=>'failed')); }
            }
            $changed = Crolord_Publisher_Store::retry($job['id']);
            if ($changed) { Crolord_Publisher::due($job['id'],(int)($payload['not_before']??0)); }
        }
        elseif ($operation === 'refresh' && $job['state'] === 'failed' && !json_decode($job['remote_ids'],true) && (int)$job['available'] <= time()) {
            $selection = get_post_meta($job['post_id'],'_crolord_publisher_selection',true);
            if (!is_array($selection) || !in_array($job['platform'],$selection['platforms'],true)) { wp_send_json_error(array('message'=>'请先勾选此平台并保存文章。'),400); }
            $selection=Crolord_Publisher_Automatic::defaults($selection);
            $selection['requested_by']=get_current_user_id();
            try { $payload=Crolord_Publisher::for_platform(Crolord_Publisher::snapshot($job['post_id'],$selection),$job['platform']); } catch (Throwable $e) { wp_send_json_error(array('message'=>$e->getMessage()),400); }
            $payload=Crolord_Publisher::bind($payload,$job['platform']);
            $valid=Crolord_Publisher_Platforms::validate($job['platform'],$payload);
            if (is_wp_error($valid)) { wp_send_json_error(array('message'=>$valid->get_error_message()),400); }
            $available=max(time(),(int)$payload['not_before']);
            $changed=$wpdb->update($table,array('runner'=>$job['platform']==='weibo'&&Crolord_Publisher_Weibo_Browser::route($payload)?'browser':'php','payload'=>wp_json_encode($payload),'progress'=>'{}','state'=>$available>time()?'scheduled':Crolord_Publisher::waiting_state($job['platform'],$payload),'lease'=>'','error_text'=>'','available'=>$available,'touched'=>time()),array('id'=>$job['id'],'state'=>'failed'));
            if ($changed) { Crolord_Publisher::due($job['id'],(int)$payload['not_before']); }
        }
        elseif ($operation === 'record' && $job['state'] === 'manual_ready') {
            $progress = json_decode($job['progress'],true) ?: array(); $progress['manual_receipt'] = sanitize_text_field(wp_unslash($_POST['receipt'] ?? '')); $progress['recorded_by'] = get_current_user_id(); $progress['recorded_at'] = time();
            $values = array('state'=>'manual_recorded','progress'=>wp_json_encode($progress),'touched'=>time());
            // Manual acknowledgement is not an API-confirmed success or a fabricated ID.
            if (in_array($job['platform'],array('weibo','x'),true) && preg_match('/^\d+$/', $progress['manual_receipt'])) { $values['remote_ids'] = wp_json_encode(array($progress['manual_receipt'])); }
            $changed = $wpdb->update($table,$values,array('id'=>$job['id'],'state'=>'manual_ready'));
        } elseif (in_array($operation,array('reconcile','reset_unknown'),true) && $job['state'] === 'unknown') {
            $ids = json_decode($job['remote_ids'],true) ?: array(); $payload = json_decode($job['payload'],true); $progress = json_decode($job['progress'],true) ?: array();
            if ($operation === 'reconcile') {
                $receipt = trim(wp_unslash($_POST['receipt'] ?? ''));
                if (!preg_match('/^\d{1,24}$/',$receipt) || in_array($receipt,$ids,true)) { wp_send_json_error(array('message'=>'请填写当前未知帖的有效数字 ID，不能重复已有 ID。'),400); }
                $ids[] = $receipt;
            } elseif (empty($_POST['absent'])) { wp_send_json_error(array('message'=>'请先核对平台并勾选确认。'),400); }
            $progress['last_reconciliation'] = array('by'=>get_current_user_id(),'at'=>time(),'operation'=>$operation);
            $parts = 1;
            $changed = $wpdb->update($table,array('state'=>count($ids)>=$parts?'succeeded':'queued','progress'=>wp_json_encode($progress),'remote_ids'=>wp_json_encode($ids),'error_text'=>'','lease'=>'','available'=>time(),'touched'=>time()),array('id'=>$job['id'],'state'=>'unknown'));
        }
        if (!$changed) { wp_send_json_error(array('message'=>'状态已变化，或平台限流等待时间尚未结束。请刷新后再试。'),409); }
        Crolord_Publisher::kick();
        wp_send_json_success(array('message'=>'已更新任务。'));
    }
    public static function pack() {
        $job = self::authorize_job(absint($_GET['job'] ?? 0)); check_admin_referer('cp_pack_' . $job['id']);
        if (!in_array($job['state'],array('manual_ready','manual_recorded'),true)) { wp_die('此任务不是手动分享任务。'); }
        if (!class_exists('ZipArchive')) { wp_die('下载素材需要 PHP ZipArchive 扩展。可以直接从文章媒体库保存原图。'); }
        $payload = json_decode($job['payload'],true); $tmp = wp_tempnam('publisher-pack'); $zip = new ZipArchive();
        if (!$tmp || $zip->open($tmp,ZipArchive::OVERWRITE) !== true) { wp_die('无法建立临时素材包。'); }
        try {
            $zip->addFromString('share.txt',Crolord_Publisher_Platforms::text($payload) . "\n");
            $zip->addFromString('title.txt',$payload['title'] . "\n");
            $zip->addFromString('link.txt',$payload['url'] . "\n");
            $zip->addFromString('publish-notes.txt',Crolord_Publisher_Platforms::names()[$job['platform']] . "\n地点备忘：" . ($payload['location'] ?? '') . "\n请在平台内选取原生定位；文字和图片的位置以客户端为准。\n朋友圈多图动态与链接卡片为不同形式，不承诺底部独立可点击链接。\n素材已准备不代表已发布。发布后请在 Publisher 登记。\n");
            $zip->addFromString('manifest.json',wp_json_encode(array('platform'=>$job['platform'],'title'=>$payload['title'],'url'=>$payload['url'],'location_note'=>$payload['location']??'','native_location_selected'=>false,'image_count'=>(($payload['wechat_media_type']??'')==='video'?0:count($payload['images'])),'video_count'=>(($payload['wechat_media_type']??'')==='video'?1:0),'published'=>false),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            if ($job['platform']==='wechat'&&($payload['wechat_media_type']??'')==='video'&&!empty($payload['wechat_video'])) { $zip->addFile(Crolord_Publisher_Wechat_History::media_file($payload['wechat_video']),'01.mp4'); }
            foreach ((($job['platform']==='wechat'&&($payload['wechat_media_type']??'')==='video')?array():$payload['images']) as $index=>$image) { $file = Crolord_Publisher_Platforms::image($image); $zip->addFile($file,sprintf('%02d.%s',$index+1,wp_get_image_mime($file)==='image/png'?'png':'jpg')); }
            $zip->close(); nocache_headers(); header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="crolord-share-' . (int)$job['id'] . '.zip"'); header('Content-Length: ' . filesize($tmp)); readfile($tmp);
        } catch (Throwable $e) { $zip->close(); unlink($tmp); wp_die(esc_html($e->getMessage())); }
        unlink($tmp); exit;
    }
}
