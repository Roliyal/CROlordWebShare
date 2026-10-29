<?php
/**
 * Plugin Name: Map
 * Description: 点亮走过的地方，收藏旅途的片刻。
 * Version: 1.6.8
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: issac
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/Roliyal/CROlordWebShare
 * Text Domain: crolord-map
 */
if (!defined('ABSPATH')) { exit; }

// New package identity, same data keys. Avoid loading both generations together.
$crolord_legacy_plugin = 'fieldnotes-travel-map/fieldnotes-travel-map.php';
$crolord_legacy_active = in_array($crolord_legacy_plugin, (array) get_option('active_plugins', array()), true);
if (is_multisite()) $crolord_legacy_active = $crolord_legacy_active || isset(((array) get_site_option('active_sitewide_plugins', array()))[$crolord_legacy_plugin]);
if ($crolord_legacy_active) {
    add_action('admin_notices', function () { echo '<div class="notice notice-info"><p>crolord：请先停用旧版 fieldnotes-travel-map，再刷新页面。原设置与内容会继续沿用，无需删除旧数据。</p></div>'; });
    return;
}

define('FNTM_VERSION', '1.6.8');
// Keep declarations conditional too: PHP declares bare functions even after an early return.
if (!$crolord_legacy_active) {
define('FNTM_FILE', __FILE__);
define('FNTM_DIR', plugin_dir_path(__FILE__));
define('FNTM_URL', plugin_dir_url(__FILE__));

require_once FNTM_DIR . 'includes/city-boundaries.php';

function fntm_license_insert($empty, $postarr) {
    return ($postarr['post_type'] ?? '') === 'fntm_place' && !true ? true : $empty;
}
add_filter('wp_insert_post_empty_content', 'fntm_license_insert', 10, 2);

function fntm_register_place_type() {
    register_post_type('fntm_place', array(
        'labels' => array(
            'name' => __('Map', 'crolord-map'),
            'singular_name' => __('Map', 'crolord-map'),
            'add_new_item' => __('添加足迹', 'crolord-map'),
            'edit_item' => __('编辑地点', 'crolord-map'),
            'search_items' => __('搜索地点', 'crolord-map'),
            'not_found' => __('还没有地点', 'crolord-map'),
        ),
        'public' => false, 'show_ui' => true, 'show_in_rest' => true,
        'menu_icon' => 'dashicons-location-alt', 'supports' => array('title', 'editor', 'excerpt', 'thumbnail'),
        'has_archive' => false, 'rewrite' => false, 'map_meta_cap' => true,
    ));
}
add_action('init', 'fntm_register_place_type', 5);

function fntm_activate() {
    add_option('fntm_settings', array(
        'mapbox_token' => '', 'mapbox_style' => 'mapbox/light-v11',
        'center_lat' => '23', 'center_lng' => '12', 'zoom' => '2',
        'cluster_radius' => '55', 'map_height' => '620', 'fit_bounds' => '1',
    ));
    fntm_register_place_type();
    flush_rewrite_rules();
}
register_activation_hook(FNTM_FILE, 'fntm_activate');
register_deactivation_hook(FNTM_FILE, 'fntm_deactivate');
function fntm_deactivate() { flush_rewrite_rules(); }

function fntm_settings() {
    $defaults = array('mapbox_token' => '', 'mapbox_style' => 'mapbox/light-v11', 'center_lat' => '23', 'center_lng' => '12', 'zoom' => '2', 'cluster_radius' => '55', 'map_height' => '620', 'fit_bounds' => '1');
    $saved = get_option('fntm_settings', array());
    return wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
}

function fntm_add_admin_pages() {
    add_submenu_page('edit.php?post_type=fntm_place', __('地图设置', 'crolord-map'), __('地图设置', 'crolord-map'), 'manage_options', 'fntm-settings', 'fntm_settings_page');
    add_submenu_page('edit.php?post_type=fntm_place', __('导入与导出', 'crolord-map'), __('导入与导出', 'crolord-map'), 'manage_options', 'fntm-transfer', 'fntm_transfer_page');
}
add_action('admin_menu', 'fntm_add_admin_pages');

function fntm_register_settings() {
    register_setting('fntm_settings_group', 'fntm_settings', array('sanitize_callback' => 'fntm_sanitize_settings'));
}
add_action('admin_init', 'fntm_register_settings');
function fntm_sanitize_settings($input) {
    if (!true) {
        add_settings_error('fntm_settings', 'license_required', '请先激活地图组件授权。');
        return fntm_settings();
    }
    $input = is_array($input) ? $input : array();
    return array(
        'mapbox_token' => isset($input['mapbox_token']) ? sanitize_text_field($input['mapbox_token']) : '',
        'mapbox_style' => isset($input['mapbox_style']) ? sanitize_text_field($input['mapbox_style']) : 'mapbox/light-v11',
        'center_lat' => isset($input['center_lat']) ? (string) max(-90, min(90, (float) $input['center_lat'])) : '23',
        'center_lng' => isset($input['center_lng']) ? (string) max(-180, min(180, (float) $input['center_lng'])) : '12',
        'zoom' => isset($input['zoom']) ? (string) max(1, min(18, absint($input['zoom']))) : '2',
        'cluster_radius' => isset($input['cluster_radius']) ? (string) max(20, min(100, absint($input['cluster_radius']))) : '55',
        'map_height' => isset($input['map_height']) ? (string) max(360, min(1000, absint($input['map_height']))) : '620',
        'fit_bounds' => !empty($input['fit_bounds']) ? '1' : '',
    );
}
function fntm_settings_page() {
    if (!current_user_can('manage_options')) return;
    $s = fntm_settings(); ?>
    <div class="wrap">
        <h1><?php esc_html_e('Map', 'crolord-map'); ?></h1>
        <p><?php esc_html_e('让走过的地方，在地图上慢慢亮起。', 'crolord-map'); ?></p>
        <form action="options.php" method="post">
            <?php settings_fields('fntm_settings_group'); ?>
            <table class="form-table" role="presentation">
                <tr><th><label for="fntm-mapbox-token"><?php esc_html_e('Mapbox 公共令牌', 'crolord-map'); ?></label></th><td><input id="fntm-mapbox-token" name="fntm_settings[mapbox_token]" type="text" class="regular-text" value="<?php echo esc_attr($s['mapbox_token']); ?>" autocomplete="off"><p class="description"><?php esc_html_e('只使用受域名限制的公开令牌。不会把私有密钥存入前端。', 'crolord-map'); ?></p></td></tr>
                <tr><th><label for="fntm-mapbox-style"><?php esc_html_e('Mapbox 样式 ID', 'crolord-map'); ?></label></th><td><input id="fntm-mapbox-style" name="fntm_settings[mapbox_style]" type="text" class="regular-text" value="<?php echo esc_attr($s['mapbox_style']); ?>"></td></tr>
                <tr><th><?php esc_html_e('默认中心点', 'crolord-map'); ?></th><td><label><?php esc_html_e('纬度', 'crolord-map'); ?> <input name="fntm_settings[center_lat]" type="number" step="any" value="<?php echo esc_attr($s['center_lat']); ?>"></label> &nbsp; <label><?php esc_html_e('经度', 'crolord-map'); ?> <input name="fntm_settings[center_lng]" type="number" step="any" value="<?php echo esc_attr($s['center_lng']); ?>"></label></td></tr>
                <tr><th><label for="fntm-zoom"><?php esc_html_e('默认缩放', 'crolord-map'); ?></label></th><td><input id="fntm-zoom" name="fntm_settings[zoom]" type="number" min="1" max="18" value="<?php echo esc_attr($s['zoom']); ?>"></td></tr>
                <tr><th><label for="fntm-radius"><?php esc_html_e('标记聚合半径', 'crolord-map'); ?></label></th><td><input id="fntm-radius" name="fntm_settings[cluster_radius]" type="number" min="20" max="100" value="<?php echo esc_attr($s['cluster_radius']); ?>"> px</td></tr>
                <tr><th><label for="fntm-height"><?php esc_html_e('地图高度', 'crolord-map'); ?></label></th><td><input id="fntm-height" name="fntm_settings[map_height]" type="number" min="360" max="1000" value="<?php echo esc_attr($s['map_height']); ?>"> px</td></tr>
                <tr><th><?php esc_html_e('自适应缩放', 'crolord-map'); ?></th><td><label><input name="fntm_settings[fit_bounds]" type="checkbox" value="1" <?php checked($s['fit_bounds'], '1'); ?>> <?php esc_html_e('自动缩放以显示全部地点', 'crolord-map'); ?></label></td></tr>
            </table>
            <?php submit_button(); ?>
        </form>
        <hr><h2><?php esc_html_e('短代码', 'crolord-map'); ?></h2>
        <p><code>[fieldnotes_travel_map]</code> <?php esc_html_e('完整旅行地图；可选 height、zoom、cluster 属性。', 'crolord-map'); ?></p>
        <p><code>[fieldnotes_travel_countries]</code> <?php esc_html_e('显示有足迹的国家及地点数。', 'crolord-map'); ?></p>
    </div><?php
}

function fntm_add_metaboxes() {
    add_meta_box('fntm-place-data', __('地图地点信息', 'crolord-map'), 'fntm_place_metabox', 'fntm_place', 'normal', 'high');
    add_meta_box('fntm-post-locations', __('旅行地点（地图）', 'crolord-map'), 'fntm_post_metabox', 'post', 'side', 'default');
}
add_action('add_meta_boxes', 'fntm_add_metaboxes');

function fntm_place_metabox($post) {
    wp_nonce_field('fntm_save_place', 'fntm_place_nonce');
    $lat = get_post_meta($post->ID, '_fntm_lat', true);
    $lng = get_post_meta($post->ID, '_fntm_lng', true);
    $country = get_post_meta($post->ID, '_fntm_country', true);
    $code = get_post_meta($post->ID, '_fntm_country_code', true);
    $year = get_post_meta($post->ID, '_fntm_year', true);
    $preview = get_post_meta($post->ID, '_fntm_preview', true);
    $related = get_post_meta($post->ID, '_fntm_related_posts', true);
    $related = is_array($related) ? implode(',', array_map('absint', $related)) : '';
    ?>
    <div class="fntm-admin-fields">
        <p><label for="fntm-place-search"><strong><?php esc_html_e('搜索地点并自动填入坐标', 'crolord-map'); ?></strong></label><br><input id="fntm-place-search" class="widefat" type="search" placeholder="<?php esc_attr_e('输入城市或地点名称', 'crolord-map'); ?>"><button type="button" class="button fntm-geocode"><?php esc_html_e('搜索', 'crolord-map'); ?></button><span class="fntm-geocode-status" aria-live="polite"></span><div class="fntm-geocode-results"></div></p>
        <p><label><?php esc_html_e('纬度', 'crolord-map'); ?> <input name="fntm_lat" type="number" step="any" value="<?php echo esc_attr($lat); ?>"></label> &nbsp; <label><?php esc_html_e('经度', 'crolord-map'); ?> <input name="fntm_lng" type="number" step="any" value="<?php echo esc_attr($lng); ?>"></label></p>
        <p><label><?php esc_html_e('国家/地区', 'crolord-map'); ?> <input name="fntm_country" type="text" value="<?php echo esc_attr($country); ?>"></label> &nbsp; <label><?php esc_html_e('国家代码', 'crolord-map'); ?> <input name="fntm_country_code" type="text" maxlength="2" size="4" value="<?php echo esc_attr($code); ?>" placeholder="JP"></label> &nbsp; <label><?php esc_html_e('年份', 'crolord-map'); ?> <input name="fntm_year" type="number" min="1900" max="2100" value="<?php echo esc_attr($year); ?>" placeholder="2025"></label></p>
        <p><label><?php esc_html_e('关联文章 ID（多个用逗号分隔）', 'crolord-map'); ?><br><input class="widefat" name="fntm_related_posts" type="text" value="<?php echo esc_attr($related); ?>"></label></p>
        <p><label><?php esc_html_e('地点预览图 URL', 'crolord-map'); ?><br><input class="widefat fntm-preview-url" name="fntm_preview" type="url" value="<?php echo esc_attr($preview); ?>"></label> <button type="button" class="button fntm-media"><?php esc_html_e('选择图片', 'crolord-map'); ?></button></p>
        <p class="description"><?php esc_html_e('没有已发布关联文章的地点会显示灰色标记。也可设置特色图片作为预览图。', 'crolord-map'); ?></p>
    </div>
    <?php
}

function fntm_post_metabox($post) {
    wp_nonce_field('fntm_save_post_places', 'fntm_post_nonce');
    $ids = get_post_meta($post->ID, '_fntm_places', true);
    $ids = is_array($ids) ? array_map('absint', $ids) : array();
    $items = array();
    foreach ($ids as $id) {
        $place = get_post($id);
        if (!$place || $place->post_type !== 'fntm_place') continue;
        $items[] = array(
            'id' => $id, 'title' => get_the_title($id),
            'lat' => (float) get_post_meta($id, '_fntm_lat', true),
            'lng' => (float) get_post_meta($id, '_fntm_lng', true),
            'country' => (string) get_post_meta($id, '_fntm_country', true),
            'country_code' => (string) get_post_meta($id, '_fntm_country_code', true),
            'year' => (string) get_post_meta($id, '_fntm_year', true),
        );
    }
    ?>
    <div class="fntm-admin-fields">
        <p><?php esc_html_e('输入地点名称，通过 Mapbox 搜索坐标，再加入这篇文章。', 'crolord-map'); ?></p>
        <input id="fntm-post-place-search" class="widefat" type="search" placeholder="<?php esc_attr_e('例如：京都', 'crolord-map'); ?>">
        <button type="button" class="button fntm-geocode"><?php esc_html_e('搜索地点', 'crolord-map'); ?></button>
        <span class="fntm-geocode-status" aria-live="polite"></span>
        <div class="fntm-geocode-results"></div>
        <ul class="fntm-selected-places"></ul>
        <input type="hidden" name="fntm_post_places" value="<?php echo esc_attr(wp_json_encode($items)); ?>">
        <p class="description"><?php esc_html_e('地点会出现在旅行地图上，并自动显示在这篇游记下方。', 'crolord-map'); ?></p>
    </div>
    <?php
}

function fntm_save_place($post_id) {
    if (!true) return;
    if (!isset($_POST['fntm_place_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fntm_place_nonce'])), 'fntm_save_place')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    foreach (array('lat' => 90, 'lng' => 180) as $axis => $limit) {
        $raw = $_POST['fntm_' . $axis] ?? null;
        if (!is_string($raw)) continue;
        $raw = trim(wp_unslash($raw));
        if ($raw === '') delete_post_meta($post_id, '_fntm_' . $axis);
        elseif (is_numeric($raw) && is_finite((float) $raw) && abs((float) $raw) <= $limit) update_post_meta($post_id, '_fntm_' . $axis, (float) $raw);
    }
    update_post_meta($post_id, '_fntm_country', isset($_POST['fntm_country']) ? sanitize_text_field(wp_unslash($_POST['fntm_country'])) : '');
    update_post_meta($post_id, '_fntm_country_code', isset($_POST['fntm_country_code']) ? strtoupper(substr(sanitize_text_field(wp_unslash($_POST['fntm_country_code'])), 0, 2)) : '');
    update_post_meta($post_id, '_fntm_year', isset($_POST['fntm_year']) ? absint($_POST['fntm_year']) : '');
    update_post_meta($post_id, '_fntm_preview', isset($_POST['fntm_preview']) ? esc_url_raw(wp_unslash($_POST['fntm_preview'])) : '');
    $related_ids = isset($_POST['fntm_related_posts']) ? array_unique(array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_POST['fntm_related_posts'])))))) : array();
    update_post_meta($post_id, '_fntm_related_posts', $related_ids);
}
add_action('save_post_fntm_place', 'fntm_save_place');

function fntm_save_post_places($post_id) {
    if (!true) return;
    if (get_post_type($post_id) !== 'post' || !isset($_POST['fntm_post_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fntm_post_nonce'])), 'fntm_save_post_places')) return;
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;
    $raw = isset($_POST['fntm_post_places']) && is_string($_POST['fntm_post_places']) ? json_decode(wp_unslash($_POST['fntm_post_places']), true) : null;
    // A malformed request must not erase the article's existing relationships.
    if (!is_array($raw) || count($raw) > 100) return;
    $old_ids = get_post_meta($post_id, '_fntm_places', true);
    $old_ids = is_array($old_ids) ? array_map('absint', $old_ids) : array();
    foreach ($old_ids as $old_id) {
        $related = get_post_meta($old_id, '_fntm_related_posts', true);
        $related = is_array($related) ? array_map('absint', $related) : array();
        update_post_meta($old_id, '_fntm_related_posts', array_values(array_diff($related, array((int) $post_id))));
    }
    $new_ids = array();
    foreach ($raw as $entry) {
        if (!is_array($entry)) continue;
        $lat = isset($entry['lat']) && is_numeric($entry['lat']) && is_finite((float) $entry['lat']) ? (float) $entry['lat'] : null;
        $lng = isset($entry['lng']) && is_numeric($entry['lng']) && is_finite((float) $entry['lng']) ? (float) $entry['lng'] : null;
        $title = isset($entry['title']) ? sanitize_text_field($entry['title']) : '';
        if ($title === '' || $lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $identity = md5(strtolower(trim($title)) . '|' . round($lat, 5) . '|' . round($lng, 5));
        $matches = get_posts(array('post_type' => 'fntm_place', 'post_status' => array('publish', 'draft', 'private'), 'numberposts' => 1, 'meta_key' => '_fntm_identity', 'meta_value' => $identity));
        $place_id = !empty($entry['id']) ? absint($entry['id']) : (!empty($matches) ? (int) $matches[0]->ID : 0);
        if ($place_id && get_post_type($place_id) !== 'fntm_place') $place_id = 0;
        if ($place_id && !current_user_can('edit_post', $place_id)) continue;
        if (!$place_id) $place_id = wp_insert_post(array('post_type' => 'fntm_place', 'post_status' => 'publish', 'post_title' => $title), true);
        if (!$place_id || is_wp_error($place_id)) continue;
        update_post_meta($place_id, '_fntm_identity', $identity);
        update_post_meta($place_id, '_fntm_lat', $lat);
        update_post_meta($place_id, '_fntm_lng', $lng);
        update_post_meta($place_id, '_fntm_country', isset($entry['country']) ? sanitize_text_field($entry['country']) : '');
        update_post_meta($place_id, '_fntm_country_code', isset($entry['country_code']) ? strtoupper(substr(sanitize_text_field($entry['country_code']), 0, 2)) : '');
        update_post_meta($place_id, '_fntm_year', isset($entry['year']) ? absint($entry['year']) : (int) get_the_date('Y', $post_id));
        $related = get_post_meta($place_id, '_fntm_related_posts', true);
        $related = is_array($related) ? array_map('absint', $related) : array();
        $related[] = (int) $post_id;
        update_post_meta($place_id, '_fntm_related_posts', array_values(array_unique($related)));
        $new_ids[] = (int) $place_id;
    }
    update_post_meta($post_id, '_fntm_places', array_values(array_unique($new_ids)));
}
add_action('save_post_post', 'fntm_save_post_places', 20);

function fntm_admin_assets($hook) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    if (!$screen || !in_array($screen->post_type, array('post', 'fntm_place'), true)) return;
    wp_enqueue_media();
    wp_enqueue_script('fntm-admin', FNTM_URL . 'assets/admin.min.js', array(), FNTM_VERSION, true);
    wp_enqueue_style('fntm-admin', FNTM_URL . 'assets/admin.min.css', array(), FNTM_VERSION);
    $settings = fntm_settings();
    wp_localize_script('fntm-admin', 'fntmAdmin', array(
        'token' => $settings['mapbox_token'],
        'noToken' => __('请先在「旅行地点 > 地图设置」填写 Mapbox 公共令牌；也可以手动输入坐标。', 'crolord-map'),
        'searching' => __('正在搜索…', 'crolord-map'),
        'noResults' => __('没有找到结果。', 'crolord-map'),
        'searchError' => __('搜索失败，请检查令牌或稍后重试。', 'crolord-map'),
        'usePlace' => __('使用此地点', 'crolord-map'),
        'remove' => __('移除', 'crolord-map'),
    ));
}
add_action('admin_enqueue_scripts', 'fntm_admin_assets', 10, 1);

function fntm_register_front_assets() {
    wp_register_style('fntm-leaflet', false, array('fntm-front'), '1.9.4');
    wp_register_style('fntm-cluster', false, array('fntm-leaflet'), '1.5.3');
    wp_register_style('fntm-cluster-default', false, array('fntm-cluster'), '1.5.3');
    wp_register_style('fntm-front', FNTM_URL . 'assets/map.min.css', array(), FNTM_VERSION);
    wp_register_style('fntm-related', FNTM_URL . 'assets/related.min.css', array(), FNTM_VERSION);
    wp_register_script('fntm-leaflet', FNTM_URL . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true);
    wp_register_script('fntm-cluster', FNTM_URL . 'assets/vendor/markercluster/leaflet.markercluster.js', array('fntm-leaflet'), '1.5.3', true);
    wp_register_script('fntm-land', FNTM_URL . 'assets/world-land.min.js', array(), FNTM_VERSION, true);
    wp_register_script('fntm-atlas', FNTM_URL . 'assets/world-atlas.min.js', array(), FNTM_VERSION, true);
    wp_register_script('fntm-front', FNTM_URL . 'assets/map.min.js', array('fntm-cluster', 'fntm-atlas'), FNTM_VERSION, true);

}
add_action('wp_enqueue_scripts', 'fntm_register_front_assets');

/** Shortcodes can enqueue late; localize once, immediately before footer output. */
function fntm_front_script_config() {
    if (!wp_script_is('fntm-front', 'enqueued')) return;
    $settings = fntm_settings();
    wp_localize_script('fntm-front', 'fntmConfig', array(
        'token' => $settings['mapbox_token'], 'style' => $settings['mapbox_style'],
        'center' => array((float) $settings['center_lat'], (float) $settings['center_lng']),
        'zoom' => (int) $settings['zoom'], 'clusterRadius' => (int) $settings['cluster_radius'],
        'height' => (int) $settings['map_height'], 'fitBounds' => $settings['fit_bounds'] === '1',
        'logoUrl' => FNTM_URL . 'assets/mapbox-logo.svg',
        'labels' => array(
            'allCountries' => __('所有国家', 'crolord-map'),
            'allYears' => __('所有年份', 'crolord-map'),
            'noPosts' => __('暂无关联游记', 'crolord-map'),
            'noPlaces' => __('还没有旅行地点', 'crolord-map'),
            'searchPlaceholder' => __('搜索地点、国家或游记', 'crolord-map'),
            'storyCount' => __('篇游记', 'crolord-map'),
            'map' => __('地图', 'crolord-map'),
        ),
    ));
}
add_action('wp_print_footer_scripts', 'fntm_front_script_config', 5);

function fntm_enqueue_map_page_assets() {
    $post = get_queried_object();
    $content = $post instanceof WP_Post ? $post->post_content : '';
    $has_map = is_page_template('templates/page-map.php') || has_shortcode($content, 'fieldnotes_travel_map');
    if ($has_map || has_shortcode($content, 'fieldnotes_travel_countries')) {
        // Queue before wp_head, rather than relying on late shortcode styles.
        wp_enqueue_style('fntm-front');
        if ($has_map) wp_enqueue_script('fntm-front');
    }
    if (is_singular('post') && !post_password_required() && fntm_related_place_ids(get_queried_object_id())) wp_enqueue_style('fntm-related');
}
add_action('wp_enqueue_scripts', 'fntm_enqueue_map_page_assets', 20);

function fntm_map_page_template($template) {
    if (in_array(get_template(), array('fieldnotes-journal', 'crolord'), true) && is_page() && is_page_template('templates/page-map.php')) return FNTM_DIR . 'templates/map-page.php';
    return $template;
}
add_filter('template_include', 'fntm_map_page_template', 20);

function fntm_get_places_data() {
    if (function_exists('crolord_request_value')) return crolord_request_value('fntm:places', 'fntm_get_places_data_uncached');
    return fntm_get_places_data_uncached();
}
function fntm_get_places_data_uncached() {
    $places = get_posts(array('post_type' => 'fntm_place', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC'));
    $related_ids = array();
    foreach ($places as $place) {
        $ids = get_post_meta($place->ID, '_fntm_related_posts', true);
        if (is_array($ids)) foreach ($ids as $id) {
            $id = absint($id);
            if ($id) $related_ids[$id] = $id;
        }
    }
    $related_ids = array_values($related_ids);
    if ($related_ids) _prime_post_caches($related_ids, true, true);
    $thumbnail_ids = array();
    foreach (array_merge(wp_list_pluck($places, 'ID'), $related_ids) as $post_id) {
        $thumbnail_id = get_post_thumbnail_id($post_id);
        if ($thumbnail_id) $thumbnail_ids[] = $thumbnail_id;
    }
    if ($thumbnail_ids) _prime_post_caches(array_unique($thumbnail_ids), false, true);
    $data = array();
    foreach ($places as $place) {
        if ($place->post_password !== '') continue;
        $lat = get_post_meta($place->ID, '_fntm_lat', true);
        $lng = get_post_meta($place->ID, '_fntm_lng', true);
        if ($lat === '' || $lng === '' || !is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) continue;
        $ids = get_post_meta($place->ID, '_fntm_related_posts', true);
        $ids = is_array($ids) ? array_map('absint', $ids) : array();
        $stories = array();
        $story_ids = array();
        foreach ($ids as $id) {
            $story = get_post($id);
            if (!$story || $story->post_type !== 'post' || $story->post_status !== 'publish' || $story->post_password !== '') continue;
            $story_ids[] = $id;
            $stories[] = array('title' => get_the_title($id), 'url' => esc_url_raw(get_permalink($id)));
        }
        $preview = get_post_meta($place->ID, '_fntm_preview', true);
        if (!$preview && has_post_thumbnail($place->ID)) $preview = get_the_post_thumbnail_url($place->ID, 'medium');
        if (!$preview && !empty($story_ids)) {
            $preview = get_the_post_thumbnail_url($story_ids[0], 'medium');
            if (!$preview && function_exists('fieldnotes_card_demo_image')) $preview = fieldnotes_card_demo_image($story_ids[0]);
            elseif (!$preview && function_exists('fieldnotes_demo_image')) $preview = fieldnotes_demo_image($story_ids[0]);
        }
        $boundary=get_post_meta($place->ID, '_fntm_city_boundary', true);
        if(!is_array($boundary)||($boundary['coordinates_key']??'')!==hash('sha256',$lat.'|'.$lng))$boundary=array();
        $data[] = array(
            'id' => (int) $place->ID, 'lat' => (float) $lat, 'lng' => (float) $lng,
            'title' => get_the_title($place->ID), 'country' => (string) get_post_meta($place->ID, '_fntm_country', true),
            'countryCode' => (string) get_post_meta($place->ID, '_fntm_country_code', true),
            'year' => (string) get_post_meta($place->ID, '_fntm_year', true),
            'preview' => $preview ? esc_url_raw($preview) : '',
            'storyIds' => $story_ids,
            'stories' => $stories, 'gray' => empty($stories),
            'description' => wp_strip_all_tags(get_the_excerpt($place->ID)),
            'boundary' => fntm_city_geometry($boundary['geometry']??null),
        );
    }
    return $data;
}

function fntm_map_icon($name) {
    $paths = array(
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'map' => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15M15 6v15"/>',
        'list' => '<path d="M9 6h12M9 12h12M9 18h12"/><circle cx="3.5" cy="6" r=".5"/><circle cx="3.5" cy="12" r=".5"/><circle cx="3.5" cy="18" r=".5"/>',
        'reset' => '<path d="M3 10a9 9 0 1 1 2 8M3 4v6h6"/>',
        'info' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 10.5v6M12 7.5h.01"/>',
    );
    return '<svg aria-hidden="true" focusable="false" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">' . ($paths[$name] ?? '') . '</svg>';
}

function fntm_map_shortcode($atts = array()) {
    $atts = shortcode_atts(array('height' => '', 'zoom' => '', 'cluster' => 'true', 'filter' => 'true', 'heading_level' => '2'), $atts, 'fieldnotes_travel_map');
    $settings = fntm_settings();
    $height = absint($atts['height']) ?: (int) $settings['map_height'];
    $height = max(360, min(1000, $height));
    $zoom = absint($atts['zoom']) ?: (int) $settings['zoom'];
    wp_enqueue_style('fntm-front');
    wp_enqueue_script('fntm-front');
    $id = wp_unique_id('fntm-map-');
    $points = fntm_get_places_data();
    $countries = array(); $stories = array();
    foreach ($points as $point) {
        $country = $point['countryCode'] ?: $point['country'];
        if ($country !== '') $countries[strtoupper($country)] = true;
        foreach ($point['stories'] as $story) $stories[$story['url']] = true;
    }
    $has_boundaries = (bool) array_filter($points, function ($point) { return !empty($point['boundary']); });
    $heading = $atts['heading_level'] === '1' ? 'h1' : 'h2';
    $map_settings = array('center' => array((float) $settings['center_lat'], (float) $settings['center_lng']), 'zoom' => $zoom, 'cluster' => strtolower($atts['cluster']) !== 'false', 'fitBounds' => $settings['fit_bounds'] === '1');
    ob_start(); ?>
    <section class="fntm-map-wrap" data-view="map" style="--fntm-height:<?php echo esc_attr($height); ?>px" aria-labelledby="<?php echo esc_attr($id . '-heading'); ?>">
        <header class="fntm-map-heading">
            <div class="fntm-map-intro"><span class="fntm-map-kicker">FIELDNOTES / ATLAS</span><<?php echo $heading; ?> id="<?php echo esc_attr($id . '-heading'); ?>"><?php esc_html_e('把路上的片刻，收藏在世界的一角。', 'crolord-map'); ?></<?php echo $heading; ?>></div>
            <dl class="fntm-map-stats"><div><dt><?php esc_html_e('处地点', 'crolord-map'); ?></dt><dd><?php echo esc_html(str_pad((string) count($points), 2, '0', STR_PAD_LEFT)); ?></dd></div><div><dt><?php esc_html_e('个国家 / 地区', 'crolord-map'); ?></dt><dd><?php echo esc_html(str_pad((string) count($countries), 2, '0', STR_PAD_LEFT)); ?></dd></div><div><dt><?php esc_html_e('篇旅行笔记', 'crolord-map'); ?></dt><dd><?php echo esc_html(str_pad((string) count($stories), 2, '0', STR_PAD_LEFT)); ?></dd></div></dl>
        </header>
        <div class="fntm-map-toolbar">
            <label class="fntm-place-search"><span class="fntm-sr-only"><?php esc_html_e('搜索已记录的地点和游记', 'crolord-map'); ?></span><?php echo fntm_map_icon('search'); ?><input type="search" class="fntm-search" placeholder="<?php esc_attr_e('寻找一座城市，一段记忆', 'crolord-map'); ?>" autocomplete="off"></label>
            <div class="fntm-view-toggle"><button type="button" data-map-view="map" aria-pressed="true"><?php echo fntm_map_icon('map'); ?><?php esc_html_e('地图', 'crolord-map'); ?></button></div>
        </div>
        <div class="fntm-map-layout">
            <aside class="fntm-map-places" aria-label="<?php esc_attr_e('旅行地点索引', 'crolord-map'); ?>"><div class="fntm-index-heading"><span><?php esc_html_e('足迹索引', 'crolord-map'); ?></span><span class="fntm-map-count" role="status" aria-live="polite"></span></div><div class="fntm-place-list"></div></aside>
            <div id="<?php echo esc_attr($id); ?>" class="fntm-map-pane" aria-label="<?php esc_attr_e('交互式旅行足迹地图', 'crolord-map'); ?>"><div class="fntm-map"></div><button type="button" class="fntm-map-reset" aria-label="<?php esc_attr_e('显示当前筛选的全部地点', 'crolord-map'); ?>" title="<?php esc_attr_e('查看全部地点', 'crolord-map'); ?>"><?php echo fntm_map_icon('reset'); ?></button><div class="fntm-map-status" role="status" hidden><span class="fntm-map-status-text"></span><button type="button" class="fntm-tile-retry"><?php esc_html_e('重新加载', 'crolord-map'); ?></button><button type="button" class="fntm-browse-places"><?php esc_html_e('浏览地点', 'crolord-map'); ?></button></div></div>
        </div>
        <script class="fntm-map-data" type="application/json"><?php echo wp_json_encode(array('places' => $points, 'settings' => $map_settings), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?></script>
        <div class="fntm-map-legend" aria-label="地图图例"><span><i class="fntm-legend-visited" aria-hidden="true"></i>到访区域</span><span><i class="fntm-legend-unvisited" aria-hidden="true"></i>尚未到访</span><span class="fntm-region-count" role="status"></span><button class="fntm-detail-toggle" type="button" aria-pressed="false">详细底图</button></div>
        <div class="fntm-map-footer">
            <p class="fntm-map-credit"><?php esc_html_e('点击一枚坐标，重新翻开那时的笔记。', 'crolord-map'); ?></p>
            <div class="fntm-map-attribution">
                <span data-map-detail-credit hidden></span>
                <a data-map-osm-credit href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer"<?php echo $has_boundaries ? '' : ' hidden'; ?>>© OpenStreetMap</a>
                <details class="fntm-map-sources"><summary aria-label="<?php esc_attr_e('地图数据来源', 'crolord-map'); ?>" title="<?php esc_attr_e('地图数据来源', 'crolord-map'); ?>"><?php echo fntm_map_icon('info'); ?></summary><div><p><?php esc_html_e('本地世界轮廓', 'crolord-map'); ?> · <a href="https://www.naturalearthdata.com/" target="_blank" rel="noopener noreferrer">Natural Earth</a></p><?php if ($has_boundaries) : ?><p><?php esc_html_e('城市边界', 'crolord-map'); ?> · © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a></p><?php endif; ?></div></details>
            </div>
        </div>

        <noscript><p><?php esc_html_e('交互地图需要启用 JavaScript。也可以直接阅读这些旅行笔记：', 'crolord-map'); ?></p><ul><?php foreach ($points as $point) : foreach ($point['stories'] as $story) : ?><li><a href="<?php echo esc_url($story['url']); ?>"><?php echo esc_html($story['title']); ?></a></li><?php endforeach; endforeach; ?></ul></noscript>
    </section>
    <?php return ob_get_clean();
}
add_shortcode('fieldnotes_travel_map', 'fntm_map_shortcode');

function fntm_country_flag($code) {
    $code = strtoupper(preg_replace('/[^A-Z]/', '', (string) $code));
    if (strlen($code) !== 2) return '';
    return html_entity_decode('&#' . (127397 + ord($code[0])) . ';&#' . (127397 + ord($code[1])) . ';', ENT_COMPAT, 'UTF-8');
}
function fntm_countries_shortcode() {
    $places = fntm_get_places_data();
    $countries = array();
    foreach ($places as $place) {
        $key = $place['countryCode'] ? strtoupper($place['countryCode']) : $place['country'];
        if ($key === '') continue;
        if (!isset($countries[$key])) $countries[$key] = array('name' => $place['country'] ?: $key, 'code' => $place['countryCode'], 'count' => 0);
        $countries[$key]['count']++;
    }
    if (!$countries) return '';
    $html = '<ul class="fntm-country-list">';
    foreach ($countries as $country) $html .= '<li><span class="fntm-flag">' . esc_html(fntm_country_flag($country['code'])) . '</span> ' . esc_html($country['name']) . ' <span>' . absint($country['count']) . '</span></li>';
    return $html . '</ul>';
}
add_shortcode('fieldnotes_travel_countries', 'fntm_countries_shortcode');

function fntm_related_place_ids($post_id) {
    static $cache=array();$post_id=absint($post_id);if(isset($cache[$post_id]))return $cache[$post_id];
    $ids = get_post_meta($post_id, '_fntm_places', true);
    $ids = is_array($ids) ? array_map('absint', $ids) : array();
    $linked = get_posts(array(
        'post_type' => 'fntm_place', 'post_status' => 'publish',
        'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_fntm_related_posts',
        'meta_compare' => 'LIKE', 'meta_value' => 'i:' . $post_id . ';',
    ));
    return $cache[$post_id]=array_values(array_unique(array_merge($ids, array_map('absint', $linked))));
}
function fntm_related_places_content($content) {
    if (!is_singular('post') || !in_the_loop() || !is_main_query() || post_password_required()) return $content;
    $ids=fntm_related_place_ids(get_the_ID());
    if (!$ids) return $content;
    $html = '<section class="fntm-related-places"><h2>' . esc_html__('相关地点', 'crolord-map') . '</h2><ul>';
    foreach ($ids as $id) {
        $place = get_post($id);
        if (!$place || $place->post_type !== 'fntm_place' || $place->post_status !== 'publish' || $place->post_password !== '') continue;
        $lat = get_post_meta($id, '_fntm_lat', true); $lng = get_post_meta($id, '_fntm_lng', true);
        $country = get_post_meta($id, '_fntm_country', true); $code = get_post_meta($id, '_fntm_country_code', true);
        $html .= '<li><span>' . esc_html(fntm_country_flag($code)) . '</span> <strong>' . esc_html(get_the_title($id)) . '</strong>';
        if ($country) $html .= ' <span>' . esc_html($country) . '</span>';
        if ($lat !== '' && $lng !== '') $html .= ' <a href="' . esc_url(fntm_wayfarer_place_url($id)) . '">' . esc_html__('查看地图', 'crolord-map') . '</a>';
        $html .= '</li>';
    }
    return $content . $html . '</ul></section>';
}
add_filter('the_content', 'fntm_related_places_content', 30);

function fntm_transfer_page() {
    if (!current_user_can('manage_options')) return;
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('导入与导出', 'crolord-map'); ?></h1>
        <p><?php esc_html_e('导出为 GeoJSON，可在 Google My Maps 等地图工具中继续编辑。导入支持 GeoJSON、JSON 和 KML 的点位。', 'crolord-map'); ?></p>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=fntm_export_geojson'), 'fntm_export_geojson')); ?>"><?php esc_html_e('导出 GeoJSON', 'crolord-map'); ?></a></p>
        <hr>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="fntm_import_places"><?php wp_nonce_field('fntm_import_places'); ?>
            <input type="file" name="fntm_import_file" accept=".json,.geojson,.kml,application/json,application/vnd.google-earth.kml+xml" required>
            <?php submit_button(__('导入地点', 'crolord-map'), 'secondary', 'submit', false); ?>
        </form>
        <p class="description"><?php esc_html_e('导入时会创建新的地点记录；请先备份现有数据。', 'crolord-map'); ?></p>
        <hr>
        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
            <input type="hidden" name="action" value="fntm_seed_demo">
            <?php wp_nonce_field('fntm_seed_demo'); ?>
            <?php submit_button(__('添加原创演示地点与游记', 'crolord-map'), 'secondary', 'submit', false); ?>
        </form>
    </div>
    <?php
}

function fntm_export_geojson() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('权限不足。', 'crolord-map'));
    check_admin_referer('fntm_export_geojson');
    $features = array();
    foreach (fntm_get_places_data() as $p) {
        $features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array($p['lng'], $p['lat'])), 'properties' => array(
            'title' => $p['title'], 'country' => $p['country'], 'country_code' => $p['countryCode'], 'year' => $p['year'], 'preview' => $p['preview'],
            'description' => $p['description'], 'post_ids' => $p['storyIds'],
        ));
    }
    nocache_headers();
    header('Content-Type: application/geo+json; charset=utf-8');
    header('Content-Disposition: attachment; filename="fieldnotes-travel-map.geojson"');
    echo wp_json_encode(array('type' => 'FeatureCollection', 'features' => $features), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
add_action('admin_post_fntm_export_geojson', 'fntm_export_geojson');

function fntm_import_features($file_path, $extension = '') {
    $ext = strtolower($extension ?: pathinfo($file_path, PATHINFO_EXTENSION));
    if ($ext === 'kml') {
        $xml_text = file_get_contents($file_path);
        if (!$xml_text || !function_exists('simplexml_load_string')) return new WP_Error('invalid_kml', __('无法读取 KML 文件。', 'crolord-map'));
        if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)\b/i', $xml_text)) return new WP_Error('invalid_kml', __('KML 不允许包含外部实体或文档类型声明。', 'crolord-map'));
        $previous_errors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xml_text, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors(); libxml_use_internal_errors($previous_errors);
        if (!$xml) return new WP_Error('invalid_kml', __('KML 格式无效。', 'crolord-map'));
        $nodes = $xml->xpath('//*[local-name()="Placemark"]');
        $features = array();
        foreach ((array) $nodes as $node) {
            $names = $node->xpath('./*[local-name()="name"]');
            $name = isset($names[0]) ? (string) $names[0] : '';
            $coords = $node->xpath('.//*[local-name()="Point"]/*[local-name()="coordinates"]');
            if (!$name || empty($coords[0])) continue;
            $parts = explode(',', trim((string) $coords[0]));
            if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) continue;
            $features[] = array('type' => 'Feature', 'geometry' => array('type' => 'Point', 'coordinates' => array((float) $parts[0], (float) $parts[1])), 'properties' => array('title' => $name));
        }
        return $features;
    }
    $decoded = json_decode(file_get_contents($file_path), true);
    if (!is_array($decoded)) return new WP_Error('invalid_json', __('JSON 文件格式无效。', 'crolord-map'));
    if (isset($decoded['type']) && $decoded['type'] === 'FeatureCollection' && isset($decoded['features']) && is_array($decoded['features'])) return $decoded['features'];
    return isset($decoded[0]) && is_array($decoded[0]) ? $decoded : new WP_Error('invalid_geojson', __('未识别的 GeoJSON/JSON 格式。', 'crolord-map'));
}
function fntm_import_places() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('权限不足。', 'crolord-map'));
    check_admin_referer('fntm_import_places');
    
    if (empty($_FILES['fntm_import_file']['tmp_name'])) wp_die(esc_html__('请选择导入文件。', 'crolord-map'));
    $file = $_FILES['fntm_import_file'];
    if (!is_array($file) || !is_string($file['name'] ?? null) || !is_string($file['tmp_name'] ?? null) || (int) ($file['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) wp_die(esc_html__('上传文件无效或未完整上传。', 'crolord-map'));
    $ext = strtolower(pathinfo(sanitize_file_name($file['name']), PATHINFO_EXTENSION));
    if (!in_array($ext, array('json', 'geojson', 'kml'), true) || filesize($file['tmp_name']) > 5 * MB_IN_BYTES) wp_die(esc_html__('文件类型不支持或文件超过 5 MB。', 'crolord-map'));
    $features = fntm_import_features($file['tmp_name'], $ext);
    if (is_wp_error($features)) wp_die(esc_html($features->get_error_message()));
    if (count($features) > 5000) wp_die(esc_html__('一次最多导入 5000 个点位，请拆分文件。', 'crolord-map'));
    $created = 0;
    foreach ($features as $feature) {
        if (!is_array($feature)) continue;
        $props = isset($feature['properties']) && is_array($feature['properties']) ? $feature['properties'] : $feature;
        $geometry = isset($feature['geometry']) ? $feature['geometry'] : array();
        $coords = isset($geometry['coordinates']) && is_array($geometry['coordinates']) ? $geometry['coordinates'] : array();
        if (isset($feature['lat'], $feature['lng'])) $coords = array($feature['lng'], $feature['lat']);
        $title = isset($props['title']) ? $props['title'] : (isset($props['name']) ? $props['name'] : '');
        if (!is_string($title) || trim($title) === '' || count($coords) < 2 || !is_numeric($coords[0]) || !is_numeric($coords[1]) || !is_finite((float) $coords[0]) || !is_finite((float) $coords[1])) continue;
        if (isset($geometry['type']) && $geometry['type'] !== 'Point') continue;
        $lng = (float) $coords[0]; $lat = (float) $coords[1];
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) continue;
        $id = wp_insert_post(array('post_type' => 'fntm_place', 'post_status' => 'publish', 'post_title' => sanitize_text_field($title), 'post_content' => isset($props['description']) ? wp_kses_post($props['description']) : ''), true);
        if (is_wp_error($id)) continue;
        update_post_meta($id, '_fntm_lat', $lat); update_post_meta($id, '_fntm_lng', $lng);
        update_post_meta($id, '_fntm_country', isset($props['country']) ? sanitize_text_field($props['country']) : '');
        update_post_meta($id, '_fntm_country_code', isset($props['country_code']) ? strtoupper(substr(sanitize_text_field($props['country_code']), 0, 2)) : '');
        update_post_meta($id, '_fntm_year', isset($props['year']) ? absint($props['year']) : '');
        update_post_meta($id, '_fntm_preview', isset($props['preview']) ? esc_url_raw($props['preview']) : '');
        $post_ids = isset($props['post_ids']) && is_array($props['post_ids']) ? array_map('absint', $props['post_ids']) : array();
        $post_ids = array_values(array_filter($post_ids, function ($post_id) { return get_post_type($post_id) === 'post'; }));
        update_post_meta($id, '_fntm_related_posts', $post_ids);
        $created++;
    }
    wp_safe_redirect(add_query_arg(array('post_type' => 'fntm_place', 'page' => 'fntm-transfer', 'imported' => $created), admin_url('edit.php')));
    exit;
}
add_action('admin_post_fntm_import_places', 'fntm_import_places');

function fntm_seed_demo_places($force = false) {
    // A draft or trashed place is still an intentional site record, not a new site.
    $existing = get_posts(array('post_type' => 'fntm_place', 'post_status' => array_values(get_post_stati()), 'numberposts' => 1, 'fields' => 'ids'));
    if ($existing && !$force) return 0;
    $demo = array(
        array('京都', 35.0116, 135.7681, '日本', 'JP', '2025', 'demo-travel-kyoto'),
        array('台北', 25.0330, 121.5654, '中国台湾', 'TW', '2025', 'demo-travel-taipei'),
        array('巴黎', 48.8566, 2.3522, '法国', 'FR', '2025', 'demo-travel-paris'),
        array('阿姆斯特丹', 52.3676, 4.9041, '荷兰', 'NL', '2025', 'demo-travel-amsterdam'),
        array('布拉格', 50.0755, 14.4378, '捷克', 'CZ', '2024', 'demo-travel-prague'),
        array('大阪', 34.6937, 135.5023, '日本', 'JP', '2024', 'demo-travel-osaka'),
        array('里斯本', 38.7223, -9.1393, '葡萄牙', 'PT', '2024', ''),
        array('哥本哈根', 55.6761, 12.5683, '丹麦', 'DK', '2023', ''),
        array('温哥华', 49.2827, -123.1207, '加拿大', 'CA', '2023', ''),
        array('罗马', 41.9028, 12.4964, '意大利', 'IT', '2022', ''),
    );
    $created = 0;
    foreach ($demo as $row) {
        $identity = md5(strtolower($row[0]) . '|' . round($row[1], 5) . '|' . round($row[2], 5));
        $match = get_posts(array('post_type' => 'fntm_place', 'post_status' => array('publish', 'draft', 'private'), 'numberposts' => 1, 'fields' => 'ids', 'meta_key' => '_fntm_identity', 'meta_value' => $identity));
        if ($match) continue;
        $story = $row[6] ? get_page_by_path($row[6], OBJECT, 'post') : null;
        if (!$story && $row[6]) {
            $category = get_term_by('slug', 'footprint', 'category');
            $story_id = wp_insert_post(array(
                'post_type' => 'post', 'post_status' => 'publish', 'post_name' => $row[6],
                'post_title' => sprintf(__('在%s散步（演示游记）', 'crolord-map'), $row[0]),
                'post_excerpt' => sprintf(__('原创演示游记：一段关于%s街巷、日常和慢旅行的记录。', 'crolord-map'), $row[0]),
                'post_content' => '<p>' . esc_html(sprintf(__('这是一篇原创虚构的演示游记。沿着%s的街道慢慢走，观察街边小店、城市声音和天气变化；在不赶行程的间隙，记录这段旅途留下的感受。', 'crolord-map'), $row[0])) . '</p>',
                'post_category' => $category ? array((int) $category->term_id) : array(),
            ));
            if ($story_id && !is_wp_error($story_id)) $story = get_post($story_id);
        }
        $id = wp_insert_post(array('post_type' => 'fntm_place', 'post_status' => 'publish', 'post_title' => $row[0], 'post_excerpt' => sprintf(__('原创演示地点：%s。', 'crolord-map'), $row[3])));
        if (!$id || is_wp_error($id)) continue;
        update_post_meta($id, '_fntm_lat', $row[1]); update_post_meta($id, '_fntm_lng', $row[2]);
        update_post_meta($id, '_fntm_country', $row[3]); update_post_meta($id, '_fntm_country_code', $row[4]); update_post_meta($id, '_fntm_year', $row[5]);
        update_post_meta($id, '_fntm_related_posts', $story ? array((int) $story->ID) : array());
        update_post_meta($id, '_fntm_identity', $identity);
        $created++;
    }
    return $created;
}
function fntm_maybe_seed_demo() {
    if (get_option('fntm_demo_seeded') || !current_user_can('manage_options') || wp_doing_ajax()) return;
    // get_template() also recognizes a child theme using either parent identity.
    if (!in_array(get_template(), array('fieldnotes-journal', 'crolord'), true)) return;
    if (!true) return;
    $lock = (int) get_option('fntm_demo_seed_lock');
    if ($lock && $lock < time()) delete_option('fntm_demo_seed_lock');
    if (!add_option('fntm_demo_seed_lock', time() + 90, '', false)) return;
    try {
        // Another admin request may have completed while this one waited.
        if (get_option('fntm_demo_seeded')) return;
        $created = fntm_seed_demo_places();
        $existing = get_posts(array('post_type' => 'fntm_place', 'post_status' => array_values(get_post_stati()), 'numberposts' => 1, 'fields' => 'ids'));
        // Do not mark failed/blocked writes as a completed initialization.
        if ($created || $existing) update_option('fntm_demo_seeded', 1, false);
    } finally { delete_option('fntm_demo_seed_lock'); }
}
add_action('admin_init', 'fntm_maybe_seed_demo', 30);
function fntm_seed_demo_action() {
    if (!current_user_can('manage_options')) wp_die(esc_html__('权限不足。', 'crolord-map'));
    check_admin_referer('fntm_seed_demo');
    
    $count = fntm_seed_demo_places(true);
    wp_safe_redirect(add_query_arg(array('post_type' => 'fntm_place', 'page' => 'fntm-transfer', 'demo' => $count), admin_url('edit.php')));
    exit;
}
add_action('admin_post_fntm_seed_demo', 'fntm_seed_demo_action');

function fntm_maybe_notice() {
    if (!empty($_GET['imported']) && current_user_can('manage_options')) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('已导入 %d 个地点。', 'crolord-map'), absint($_GET['imported']))) . '</p></div>';
    if (!empty($_GET['demo']) && current_user_can('manage_options')) echo '<div class="notice notice-success is-dismissible"><p>' . esc_html(sprintf(__('已创建 %d 个地点演示。', 'crolord-map'), absint($_GET['demo']))) . '</p></div>';
}
add_action('admin_notices', 'fntm_maybe_notice');

} // crolord replacement is active only when its legacy counterpart is inactive.
