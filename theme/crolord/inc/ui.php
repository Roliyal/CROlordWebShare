<?php
/** Shared field interactions and an uninterrupted Echoes header. */
if (!defined('ABSPATH')) exit;

function fieldnotes_is_echoes_page() {
    if (!is_singular('page')) return false;
    if (is_page_template('templates/page-chatgpt.php')) return true;
    $page = get_queried_object();
    if (!$page || ($page->post_type ?? '') !== 'page') return false;
    if (fieldnotes_page_section($page->ID) === 'chatgpt') return true;
    return has_shortcode((string) ($page->post_content ?? ''), 'fieldnotes_chatgpt');
}

function fieldnotes_logo_only_header() {
    return !is_front_page();
}

/** Presentation only: let the plugin validate its own session and render its own state. */
function fieldnotes_echoes_unlocked() {
    if (!fieldnotes_is_echoes_page() || !shortcode_exists('fieldnotes_chatgpt')) return false;
    static $unlocked = null;
    if ($unlocked === null) {
        // No cookie presence check, password copy, or separate permission decision in the theme.
        $markup = do_shortcode('[fieldnotes_chatgpt]');
        $unlocked = (bool) preg_match('/class=["\'][^"\']*\bfnchat-shell\b[^"\']*["\']/', $markup);
    }
    return $unlocked;
}

function fieldnotes_ui_body_class($classes) {
    $classes[] = 'fieldnotes-ui';
    if (fieldnotes_is_echoes_page()) $classes[] = 'echoes-logo-only';
    return array_values(array_unique($classes));
}
add_filter('body_class', 'fieldnotes_ui_body_class');

function fieldnotes_field_assets() {
    $dependencies = array('fieldnotes-editorial');
    foreach (array('fieldnotes-collections', 'fieldnotes-catalog', 'fieldnotes-market', 'fieldnotes-chatgpt', 'fieldnotes-comment-tools', 'fntm-front', 'fieldnotes-keepsakes', 'crolord-keepsakes-flow', 'fieldnotes-motion') as $handle) {
        if (wp_style_is($handle, 'enqueued')) $dependencies[] = $handle;
    }
    wp_enqueue_style('fieldnotes-fields', get_template_directory_uri() . '/assets/css/fields.min.css', $dependencies, FIELDNOTES_VERSION);
}
add_action('wp_enqueue_scripts', 'fieldnotes_field_assets', 100);

/** Keep desktop widget classes intact; mark only recent-content widgets for phone CSS. */
function fieldnotes_phone_recent_widget($params) {
    if (($params[0]['id'] ?? '') !== 'journal-sidebar') return $params;
    $id = (string) ($params[0]['widget_id'] ?? '');
    $recent = (bool) preg_match('/^(recent-posts|recent-comments)-[0-9]+$/D', $id);
    if (!$recent && preg_match('/^block-([0-9]+)$/D', $id, $match)) {
        $blocks = get_option('widget_block', array());
        $content = is_array($blocks) ? ($blocks[(int) $match[1]]['content'] ?? '') : '';
        $recent = is_string($content) && (has_block('core/latest-posts', $content) || has_block('core/latest-comments', $content));
    }
    if ($recent && class_exists('WP_HTML_Tag_Processor')) {
        $tag = new WP_HTML_Tag_Processor((string) ($params[0]['before_widget'] ?? ''));
        if ($tag->next_tag()) {
            $tag->add_class('crolord-phone-recent');
            $params[0]['before_widget'] = $tag->get_updated_html();
        }
    }
    return $params;
}
add_filter('dynamic_sidebar_params', 'fieldnotes_phone_recent_widget', 20);
