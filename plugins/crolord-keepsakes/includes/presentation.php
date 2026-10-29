<?php
if(!defined('ABSPATH'))exit;

function crks_catalog_lookup($product,$refresh=false) {
    static $misses=0;
    $sku=crks_jd_sku($product['sku']??'');
    if(!$sku)return new WP_Error('crks_not_jd','请先为这件物品填写京东商品链接。');
    $cache_key=crks_public_cache_key($product);
    if((!get_transient($cache_key)||$refresh&&!get_transient($cache_key.'_checked'))&&++$misses>1)return new WP_Error('crks_catalog_pending','正在分批读取其余商品规格。');
    return crks_public_catalog($product,$refresh);
}
function crks_assets() {
    if(!function_exists('fieldnotes_boughs_context')||fieldnotes_boughs_context()!=='minimalism')return;
    wp_dequeue_script('fieldnotes-keepsakes');wp_deregister_script('fieldnotes-keepsakes');
    wp_enqueue_script('fieldnotes-keepsakes',plugins_url('assets/keepsakes.min.js',dirname(__DIR__).'/crolord-keepsakes.php'),array(),CROLORD_KEEPSAKES_VERSION,true);
    wp_localize_script('fieldnotes-keepsakes','crolordKeepsakes',array('restUrl'=>rest_url('crolord/v1/keepsakes/'),'likeUrl'=>admin_url('admin-ajax.php','relative'),'selectableKind'=>'生活器物'));
    wp_enqueue_style('crolord-keepsakes-flow',plugins_url('assets/flow.min.css',dirname(__DIR__).'/crolord-keepsakes.php'),array('fieldnotes-keepsakes'),CROLORD_KEEPSAKES_VERSION);
}
add_action('wp_enqueue_scripts','crks_assets',60);

function crks_product_metabox() {
    remove_meta_box('fieldnotes-jd-product','journal_fave','normal');
    add_meta_box('fieldnotes-jd-product','京东商品链接','crks_product_metabox_content','journal_fave','normal');
}
add_action('add_meta_boxes','crks_product_metabox',30);
function crks_product_metabox_content($post) {
    $url=(string)get_post_meta($post->ID,'_fieldnotes_purchase_url',true);
    if($url==='')$url=crks_jd_item_url((string)get_post_meta($post->ID,'_fieldnotes_jd_sku',true));
    wp_nonce_field('crks_product_link','crks_product_nonce');
    echo '<p><label for="crks-product-url">粘贴京东或京东健康商品链接</label></p><input class="widefat" type="url" id="crks-product-url" name="crks_product_url" value="'.esc_attr($url).'" placeholder="https://item.jd.com/10112442338563.html">';
    echo '<p class="description">支持京东与京东健康的商品详情链接。自动读取规格后，可前往商品页选购。</p>';
    echo '<div class="crks-product-preview" data-post-id="'.esc_attr((string)$post->ID).'" aria-live="polite"><p class="crks-preview-status">正在准备读取…</p><div class="crks-preview-content"></div></div>';
}
function crks_save_product_link($post_id) {
    if(!isset($_POST['crks_product_nonce'],$_POST['crks_product_url'])||!is_string($_POST['crks_product_nonce'])||!is_string($_POST['crks_product_url'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['crks_product_nonce'])),'crks_product_link')||!current_user_can('edit_post',$post_id)||wp_is_post_revision($post_id)||defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
    $raw=trim(wp_unslash($_POST['crks_product_url']));
    if($raw===''){delete_post_meta($post_id,'_fieldnotes_purchase_url');delete_post_meta($post_id,'_fieldnotes_jd_sku');return;}
    $url=crks_jd_product_link($raw);
    if(!$url){set_transient('crks_bad_link_'.get_current_user_id(),1,MINUTE_IN_SECONDS);return;}
    update_post_meta($post_id,'_fieldnotes_purchase_url',$url);
    update_post_meta($post_id,'_fieldnotes_jd_sku',crks_jd_sku_from_url($url));
}
add_action('save_post_journal_fave','crks_save_product_link',30);
function crks_link_notice() {
    $screen=get_current_screen();if(!$screen||$screen->post_type!=='journal_fave')return;
    $key='crks_bad_link_'.get_current_user_id();if(!get_transient($key))return;delete_transient($key);
    echo '<div class="notice notice-error is-dismissible"><p>商品链接未保存：请粘贴京东或京东健康的商品详情链接，原链接已保留。</p></div>';
}
add_action('admin_notices','crks_link_notice');
function crks_warm_catalog($post_id) {
    if(!crks_selectable($post_id))return;
    $products=crks_products(array((string)$post_id));if(!is_wp_error($products))crks_catalog_lookup($products[0]);
}
add_action('crolord_keepsakes_warm','crks_warm_catalog');
add_action('save_post_journal_fave',function($post_id){if(wp_is_post_revision($post_id)||!crks_selectable($post_id))return;if(!wp_next_scheduled('crolord_keepsakes_warm',array($post_id)))wp_schedule_single_event(time()+10,'crolord_keepsakes_warm',array($post_id));},50);

function crks_admin_assets($hook) {
    if(!in_array($hook,array('post.php','post-new.php'),true))return;$screen=get_current_screen();if(!$screen||$screen->post_type!=='journal_fave')return;
    wp_enqueue_script('crolord-keepsakes-editor',plugins_url('assets/editor.min.js',dirname(__DIR__).'/crolord-keepsakes.php'),array(),CROLORD_KEEPSAKES_VERSION,true);
    wp_localize_script('crolord-keepsakes-editor','crksEditor',array('url'=>rest_url('crolord/v1/keepsakes/preview'),'nonce'=>wp_create_nonce('wp_rest')));
}
add_action('admin_enqueue_scripts','crks_admin_assets');
function crks_preview_permission($request) {
    if(!crks_same_origin())return new WP_Error('crks_preview_origin','请从本站后台操作。',array('status'=>403));$id=$request->get_param('post_id');$nonce=$request->get_header('x-wp-nonce');
    if(!is_scalar($id)||!ctype_digit((string)$id)||!current_user_can('edit_post',(int)$id)||!is_string($nonce)||!wp_verify_nonce($nonce,'wp_rest'))return new WP_Error('crks_preview_permission','请刷新物品编辑页后重试。',array('status'=>403));
    $post=get_post((int)$id);if(!$post||$post->post_type!=='journal_fave')return new WP_Error('crks_preview_post','请选择收藏条目。',array('status'=>400));$license=null;return is_wp_error($license)?$license:true;
}
function crks_preview($request) {
    if(!crks_limit('editor:'.get_current_user_id(),'preview',30))return crks_response(array('message'=>'读取较频繁，请稍等片刻。'),429);$url=crks_jd_product_link($request->get_param('url'));if(!$url)return crks_response(array('message'=>'请粘贴有效的京东商品详情链接。'),400);
    $value=crks_public_catalog(array('sku'=>crks_jd_sku_from_url($url),'url'=>$url));if(is_wp_error($value))return crks_response(array('message'=>$value->get_error_message(),'code'=>$value->get_error_code()),$value->get_error_code()==='crks_public_pending'?409:502);return crks_response($value);
}
