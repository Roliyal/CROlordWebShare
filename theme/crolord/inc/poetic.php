<?php
/** Public-facing section names. Stored page titles, menu data and URLs stay intact. */
if (!defined('ABSPATH')) exit;

function fieldnotes_section_labels() {
    return array('about' => __('About', 'crolord'), 'archive' => __('Markets', 'crolord'), 'map' => __('Wayfarer', 'crolord'), 'categories' => __('Boughs', 'crolord'), 'faves' => __('Keepsakes', 'crolord'), 'chatgpt' => __('Echoes', 'crolord'));
}

function fieldnotes_section_label($slug) {
    $labels = fieldnotes_section_labels();
    return $labels[$slug] ?? '';
}

function fieldnotes_section_heading($section) {
    $headings = array(
        'map' => '把路上的片刻，收藏在世界的一角。',
        'faves' => '生活里的长期之选。',
        'categories' => '把想法做成工具，把实践写成记录。',
    );
    return $headings[$section] ?? fieldnotes_section_label($section);
}

/** Resolve the actual published page, including a page whose slug was changed. */
function fieldnotes_section_page($section) {
    return crolord_request_value(__FUNCTION__ . ":" . $section, function () use ($section) {
        if (!isset(fieldnotes_section_labels()[$section])) return null;
        if (!is_customize_preview()) {
            $ids = crolord_section_page_ids();
            if (!empty($ids[$section])) {
                $page = get_post($ids[$section]);
                if ($page instanceof WP_Post && $page->post_type === 'page' && $page->post_status === 'publish' && get_post_meta($page->ID, '_wp_page_template', true) === 'templates/page-' . $section . '.php') return $page;
                delete_option('crolord_section_page_ids');
                crolord_request_cache_reset();
            }
        }
        return fieldnotes_section_page_uncached($section);
    });
}

function fieldnotes_section_page_uncached($section) {
    if (!isset(fieldnotes_section_labels()[$section])) return null;
    $pages = get_pages(array(
        'post_status' => 'publish',
        'meta_key' => '_wp_page_template',
        'meta_value' => 'templates/page-' . $section . '.php',
        'sort_column' => 'menu_order,ID',
        'sort_order' => 'ASC',
        'number' => 1,
    ));
    if (!empty($pages)) return $pages[0];
    $slugs = array('about' => 'about', 'archive' => 'markets', 'map' => 'wayfarer', 'categories' => 'boughs', 'faves' => 'keepsakes', 'chatgpt' => 'echoes');
    foreach (array_unique(array($slugs[$section], $section)) as $slug) {
        $page = get_page_by_path($slug, OBJECT, 'page');
        if ($page && $page->post_status === 'publish' && in_array(get_page_template_slug($page->ID), array('', 'default', 'templates/page-' . $section . '.php'), true)) return $page;
    }
    return null;
}

function fieldnotes_section_url($section) {
    $page = fieldnotes_section_page($section);
    $url = $page ? get_permalink($page) : home_url('/' . $section . '/');
    return $url;
}

function fieldnotes_public_copy_enabled() {
    return !is_admin() && !(defined('REST_REQUEST') && REST_REQUEST);
}

function fieldnotes_page_section($post_id) {
    $page = get_post($post_id);
    if (!$page || $page->post_type !== 'page') return '';
    if (in_array(get_post_meta($page->ID, '_fieldnotes_room', true), array('wordpress', 'interludes', 'works'), true)) return 'categories';
    $labels = fieldnotes_section_labels();
    $templates = array('templates/page-about.php' => 'about', 'templates/page-archive.php' => 'archive', 'templates/page-map.php' => 'map', 'templates/page-categories.php' => 'categories', 'templates/page-faves.php' => 'faves', 'templates/page-chatgpt.php' => 'chatgpt');
    $template = get_page_template_slug($page->ID);
    if (isset($templates[$template])) return $templates[$template];
    if ($template !== '' && $template !== 'default') return '';
    foreach ($labels as $section => $label) {
        $resolved = fieldnotes_section_page($section);
        if ($resolved && (int) $resolved->ID === (int) $page->ID) return $section;
    }
    return '';
}

function fieldnotes_poetic_page_title($title, $post_id = 0) {
    if (!fieldnotes_public_copy_enabled() || !$post_id) return $title;
    $section = fieldnotes_page_section($post_id);
    return $section !== '' ? ($section === 'categories' ? 'Boughs' : fieldnotes_section_heading($section)) : $title;
}
add_filter('the_title', 'fieldnotes_poetic_page_title', 20, 2);

function fieldnotes_poetic_document_title($parts) {
    if (!fieldnotes_public_copy_enabled() || !is_singular('page') || is_front_page()) return $parts;
    $section = fieldnotes_page_section(get_queried_object_id());
    if ($section !== '') $parts['title'] = $section === 'categories' ? 'Boughs' : fieldnotes_section_heading($section);
    return $parts;
}
add_filter('document_title_parts', 'fieldnotes_poetic_document_title', 20);

function fieldnotes_poetic_menu($items, $args) {
    if (!fieldnotes_public_copy_enabled() || empty($args->theme_location) || $args->theme_location !== 'primary') return $items;
    $hints = fieldnotes_section_labels();
    $page_urls = null;
    foreach ($items as $item) {
        $section = isset($item->object, $item->object_id) && $item->object === 'page' ? fieldnotes_page_section($item->object_id) : '';
        if ($section === '' && isset($item->url)) {
            $old_query = wp_parse_url($item->url, PHP_URL_QUERY);
            if (is_string($old_query)) {
                parse_str($old_query, $old_args);
                if (isset($old_args['page_id']) && is_scalar($old_args['page_id'])) $section = fieldnotes_page_section(absint($old_args['page_id']));
            }
            if ($page_urls === null) {
                $page_urls = array();
                foreach (fieldnotes_section_labels() as $slug => $label) {
                    $page = fieldnotes_section_page($slug);
                    if ($page) $page_urls[untrailingslashit(get_permalink($page))] = $slug;
                }
            }
            if ($section === '') $section = $page_urls[untrailingslashit($item->url)] ?? '';
        }
        if ($section === '') continue;
        $item->title = fieldnotes_section_label($section);
        $item->url = fieldnotes_section_url($section);
        if (empty($item->attr_title)) $item->attr_title = $hints[$section];
    }
    return $items;
}
add_filter('wp_nav_menu_objects', 'fieldnotes_poetic_menu', 20, 2);

function fieldnotes_poetic_map_text($translation, $text, $domain) {
    if (fieldnotes_public_copy_enabled() && in_array($domain, array('fieldnotes-travel-map', 'crolord-map'), true) && $text === '走过的地方。') return fieldnotes_section_heading('map');
    return $translation;
}
add_filter('gettext', 'fieldnotes_poetic_map_text', 20, 3);

function fieldnotes_poetic_map_output($output, $tag) {
    if (fieldnotes_public_copy_enabled() && $tag === 'fieldnotes_travel_map') {
        $output = str_replace('<span class="fntm-map-kicker">FIELDNOTES / ATLAS</span>', '', $output);
    }
    return $output;
}
add_filter('do_shortcode_tag', 'fieldnotes_poetic_map_output', 20, 2);
