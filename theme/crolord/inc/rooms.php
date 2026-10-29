<?php
/** Independent destinations; categories are kept only as editorial storage. */
if (!defined('ABSPATH')) exit;

function fieldnotes_room_page($key) {
    return crolord_request_value(__FUNCTION__ . ":" . $key, function () use ($key) { return fieldnotes_room_page_uncached($key); });
}

function fieldnotes_room_page_uncached($key) {
    if (fieldnotes_boughs_key($key) === 'works') return fieldnotes_section_page('categories');
    if ($key === 'investing') return fieldnotes_section_page('archive');
    if ($key === 'minimalism') return fieldnotes_section_page('faves');
    if ($key !== 'cities') return null;
    $pages = get_pages(array('post_status' => 'publish', 'meta_key' => '_fieldnotes_room', 'meta_value' => $key, 'number' => 1, 'sort_column' => 'ID', 'sort_order' => 'ASC'));
    return $pages ? $pages[0] : null;
}

function fieldnotes_room_context() {
    if (!is_singular('page')) return '';
    if (is_page_template('templates/page-archive.php')) return 'investing';
    if (is_page_template('templates/page-faves.php')) return 'minimalism';
    if (is_page_template('templates/page-collection.php')) {
        $key = get_post_meta(get_queried_object_id(), '_fieldnotes_room', true);
        return in_array($key, array('cities', 'wordpress', 'interludes', 'works'), true) ? fieldnotes_boughs_key($key) : '';
    }
    if (is_page_template('templates/page-categories.php')) {
        $key = isset($_GET['room']) && is_string($_GET['room']) ? fieldnotes_boughs_key($_GET['room']) : 'works';
        return isset(fieldnotes_boughs_sections()[$key]) ? $key : 'works';
    }
    return '';
}

function fieldnotes_room_title($key) {
    return $key === 'minimalism' ? fieldnotes_section_heading('faves') : ($key === 'investing' ? 'Markets' : (fieldnotes_boughs_key($key) === 'works' ? fieldnotes_section_heading('categories') : (fieldnotes_boughs_sections()[$key]['name'] ?? '')));
}

function fieldnotes_render_room($key) {
    $key = fieldnotes_boughs_key($key);
    if (!isset(fieldnotes_boughs_sections()[$key])) return;
    $boughs_key = $key;
    $boughs_section = fieldnotes_boughs_sections()[$key];
    $boughs_term = fieldnotes_boughs_term($key);
    include get_template_directory() . '/template-parts/collection.php';
}

function fieldnotes_rooms_install() {
    if (!current_user_can('edit_pages') || wp_doing_ajax() || get_option('fieldnotes_rooms_180_done')) return;
    $until = (int) get_option('fieldnotes_rooms_180_lock');
    if ($until && $until < time()) delete_option('fieldnotes_rooms_180_lock');
    if (!add_option('fieldnotes_rooms_180_lock', time() + 60, '', false)) return;
    try {
    $registry = array('investing' => array('markets', 'Markets', 'templates/page-archive.php'), 'minimalism' => array('keepsakes', fieldnotes_section_heading('faves'), 'templates/page-faves.php'));
    foreach ($registry as $key => $definition) {
        if (fieldnotes_room_page($key)) continue;
        $id = wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $definition[1], 'post_name' => $definition[0], 'post_content' => '', 'meta_input' => array('_wp_page_template' => $definition[2], '_fieldnotes_room' => $key)), true);
        if (is_wp_error($id) || !$id) return;
    }
    update_option('fieldnotes_rooms_180_done', 1, false);
    } finally { delete_option('fieldnotes_rooms_180_lock'); }
}
add_action('admin_init', 'fieldnotes_rooms_install', 45);

function fieldnotes_room_document_title($parts) {
    $key = fieldnotes_room_context();
    if ($key) $parts['title'] = $key === 'works' ? 'Boughs' : fieldnotes_room_title($key);
    return $parts;
}
add_filter('document_title_parts', 'fieldnotes_room_document_title', 25);

function fieldnotes_room_assets() {
    if (fieldnotes_is_echoes_page()) {
        $deps = array('fieldnotes-refinement');
        if (wp_style_is('fieldnotes-chatgpt', 'enqueued')) $deps[] = 'fieldnotes-chatgpt';
        crolord_inline_asset_style('fieldnotes-echoes-header','echoes',$deps);
    }
}
add_action('wp_enqueue_scripts', 'fieldnotes_room_assets', 40);

/** Redirect missing front-end documents before WordPress guesses another permalink. */
function fieldnotes_redirect_missing_page() {
    if (!is_404() || is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) || wp_is_json_request() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) return;
    $destination = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
    if ($destination !== '' && !in_array($destination, array('document', 'iframe', 'empty'), true)) return;
    wp_safe_redirect(home_url('/'), 302, 'Crolord');
    exit;
}
add_action('template_redirect', 'fieldnotes_redirect_missing_page', 9);
