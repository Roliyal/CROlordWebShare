<?php
/** Keepsakes: multiple considered choices and explicit purchase destinations. */
if (!defined('ABSPATH')) exit;

function fieldnotes_jd_sku($value) {
    if (!is_string($value)) return '';
    $value = trim($value);
    if (preg_match('/^[1-9][0-9]{4,17}$/D', $value)) return $value;
    if (function_exists('crks_jd_sku_from_url')) return crks_jd_sku_from_url($value);
    $parts = wp_parse_url($value);
    if (!is_array($parts) || !in_array(strtolower($parts['scheme'] ?? ''), array('https', 'http'), true) || !in_array(strtolower($parts['host'] ?? ''), array('item.jd.com', 'item.m.jd.com', 'item.jingdonghealth.cn', 'mitem.jingdonghealth.cn'), true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return '';
    return preg_match('~^/(?:product/)?([1-9][0-9]{4,17})\.html$~D', $parts['path'] ?? '', $m) ? $m[1] : '';
}

function fieldnotes_purchase_url($value) {
    if (!is_string($value)) return '';
    $parts = wp_parse_url(trim($value));
    if (!is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) return '';
    return esc_url_raw(trim($value), array('https'));
}

function fieldnotes_keepsake_product($post_id) {
    $url = fieldnotes_purchase_url((string) get_post_meta($post_id, '_fieldnotes_purchase_url', true));
    $sku = fieldnotes_jd_sku((string) get_post_meta($post_id, '_fieldnotes_jd_sku', true));
    if (!$url && $sku) $url = 'https://item.jd.com/' . $sku . '.html';
    if ($url) { $parsed_sku = fieldnotes_jd_sku($url); $sku = $parsed_sku ?: ''; }
    return array('id' => (string) $post_id, 'title' => get_the_title($post_id), 'url' => $url, 'sku' => $sku, 'preview' => fieldnotes_fave_image($post_id));
}
function fieldnotes_keepsakes_metabox() {
    add_meta_box('fieldnotes-jd-product', '商品信息', 'fieldnotes_keepsakes_metabox_content', 'journal_fave', 'normal');
}
add_action('add_meta_boxes', 'fieldnotes_keepsakes_metabox');
function fieldnotes_keepsakes_metabox_content($post) {
    wp_nonce_field('fieldnotes_jd_product', 'fieldnotes_jd_nonce');
    $product = fieldnotes_keepsake_product($post->ID);
    echo '<p><label for="fn-purchase-url">购买链接</label><input class="widefat" type="url" id="fn-purchase-url" name="fieldnotes_purchase_url" value="' . esc_attr($product['url']) . '" placeholder="https://商店的商品详情页"></p><p>粘贴京东商品链接，自动读取可选规格。</p>';
}
function fieldnotes_keepsakes_save($post_id) {
    if (!isset($_POST['fieldnotes_jd_nonce']) || !is_string($_POST['fieldnotes_jd_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fieldnotes_jd_nonce'])), 'fieldnotes_jd_product') || !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (isset($_POST['fieldnotes_purchase_url'])) {
        if (!is_string($_POST['fieldnotes_purchase_url'])) return;
        $raw = trim(wp_unslash($_POST['fieldnotes_purchase_url'])); $url = fieldnotes_purchase_url($raw);
        if ($raw === '') { delete_post_meta($post_id, '_fieldnotes_purchase_url'); delete_post_meta($post_id, '_fieldnotes_jd_sku'); }
        elseif ($url) update_post_meta($post_id, '_fieldnotes_purchase_url', $url);
    } elseif (isset($_POST['fieldnotes_jd_sku']) && is_string($_POST['fieldnotes_jd_sku'])) {
        // Accept the previous editor field without changing already stored product IDs.
        $raw = trim(wp_unslash($_POST['fieldnotes_jd_sku'])); $sku = fieldnotes_jd_sku($raw);
        if ($raw === '') delete_post_meta($post_id, '_fieldnotes_jd_sku');
        elseif ($sku) update_post_meta($post_id, '_fieldnotes_jd_sku', $sku);
    }
}
add_action('save_post_journal_fave', 'fieldnotes_keepsakes_save');

function fieldnotes_keepsakes_assets() {
    if (fieldnotes_boughs_context() !== 'minimalism') return;
    wp_enqueue_style('fieldnotes-keepsakes', get_template_directory_uri() . '/assets/css/keepsakes.min.css', array('fieldnotes-collections'), FIELDNOTES_VERSION);
    wp_enqueue_script('fieldnotes-keepsakes', get_template_directory_uri() . '/assets/js/keepsakes.min.js', array(), FIELDNOTES_VERSION, true);
    wp_localize_script('fieldnotes-keepsakes', 'crolordKeepsakes', array('restUrl' => function_exists('crks_routes') ? rest_url('crolord/v1/keepsakes/') : '', 'likeUrl' => admin_url('admin-ajax.php', 'relative')));
}
add_action('wp_enqueue_scripts', 'fieldnotes_keepsakes_assets', 40);
