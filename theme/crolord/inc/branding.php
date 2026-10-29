<?php
if (!defined('ABSPATH')) exit;

/** A transparent vector by default; uploaded identity settings remain available. */
function crolord_branding_logo_id() {
    if (get_theme_mod('crolord_identity_style', 'mark') !== 'image') return 0;
    $id = absint(get_theme_mod('custom_logo'));
    if (!$id || !wp_attachment_is_image($id)) return 0;
    return $id;
}

function crolord_brand_image_url($size = 512) {
    $id = crolord_branding_logo_id();
    if ($id) {
        $url = wp_get_attachment_image_url($id, (int) $size >= 512 ? 'full' : array((int) $size, (int) $size));
        if ($url) return $url;
    }
    $icon = absint(get_option('site_icon'));
    if ($icon && wp_attachment_is_image($icon)) {
        $native = wp_get_attachment_image_url($icon, (int) $size >= 512 ? 'full' : array((int) $size, (int) $size));
        if ($native) return $native;
    }
    $size = (int) $size;
    $available = array(16, 32, 48, 128, 180, 192, 270, 512);
    foreach ($available as $available_size) if ($available_size >= $size) {
        $size = $available_size;
        break;
    }
    $size = min(512, max(16, $size));
    return get_template_directory_uri() . '/assets/branding/site-icon-' . $size . '.png?ver=' . rawurlencode(FIELDNOTES_VERSION);
}

/** One fixed identity outside every page's scrolling/filtering header container. */
function crolord_site_logo() {
    $name = get_bloginfo('name');
    $image = crolord_branding_logo_id();
    if (!$image) {
        // The exact bundled vector is drawn immediately, without an image request.
        static $mark = null;
        if ($mark === null) $mark = str_replace('<svg ', '<svg class="custom-logo crolord-avatar-logo crolord-symbol-logo" aria-hidden="true" ', file_get_contents(get_template_directory() . '/assets/branding/site-mark.svg'));
        echo '<a class="custom-logo-link crolord-avatar-logo-link" href="' . esc_url(home_url('/')) . '" rel="home" aria-label="' . esc_attr(sprintf(__('返回 %s 首页', 'crolord'), $name)) . '">' . $mark . '</a>';
        return;
    }
    if ($image && crolord_original_portrait_id()) {
        echo '<a class="custom-logo-link crolord-avatar-logo-link" href="' . esc_url(home_url('/')) . '" rel="home" aria-label="' . esc_attr(sprintf(__('返回 %s 首页', 'crolord'), $name)) . '">' . '<img class="custom-logo crolord-avatar-logo crolord-image-logo" src="' . esc_url(crolord_original_portrait_url()) . '" width="35" height="35" alt="' . esc_attr($name) . '" loading="eager" decoding="async">' . '</a>';
        return;
    }
    $source = $image ? crolord_brand_image_url(128) : get_template_directory_uri() . '/assets/branding/site-mark.svg?ver=' . rawurlencode(FIELDNOTES_VERSION);
    printf(
        '<a class="custom-logo-link crolord-avatar-logo-link" href="%1$s" rel="home" aria-label="%2$s"><img class="custom-logo crolord-avatar-logo %5$s" src="%3$s" width="32" height="32" alt="%4$s" loading="eager" decoding="async" fetchpriority="high"></a>',
        esc_url(home_url('/')),
        esc_attr(sprintf(__('返回 %s 首页', 'crolord'), $name)),
        esc_url($source),
        esc_attr($name),
        $image ? 'crolord-image-logo' : 'crolord-symbol-logo'
    );
}

/** Keep WordPress' native icon tags, including login/admin/apple-touch variants. */
function crolord_brand_site_icon_url($url, $size, $blog_id = 0) {
    if ($blog_id && (int) $blog_id !== get_current_blog_id()) return $url;
    if (crolord_original_portrait_id() && (int) $size <= 64) return crolord_original_portrait_url();
    return crolord_brand_image_url($size);
}
add_filter('get_site_icon_url', 'crolord_brand_site_icon_url', 20, 3);

function crolord_brand_ico_tag($tags) {
    if (!crolord_branding_logo_id() && !get_option('site_icon')) {
        $tags[] = sprintf('<link rel="shortcut icon" type="image/x-icon" sizes="16x16 32x32 48x48" href="%s">', esc_url(get_template_directory_uri() . '/assets/branding/favicon.ico?ver=' . rawurlencode(FIELDNOTES_VERSION)));
        $tags[] = sprintf('<link rel="icon" type="image/svg+xml" sizes="any" href="%s">', esc_url(get_template_directory_uri() . '/assets/branding/site-mark.svg?ver=' . rawurlencode(FIELDNOTES_VERSION)));
    }
    return $tags;
}
add_filter('site_icon_meta_tags', 'crolord_brand_ico_tag', 20);

function crolord_brand_customize($customizer) {
    $customizer->add_setting('crolord_identity_style', array('default'=>'mark', 'sanitize_callback'=>'crolord_brand_style_sanitize'));
    $customizer->add_control('crolord_identity_style', array('label'=>__('站点标识样式', 'crolord'), 'section'=>'title_tagline', 'type'=>'select', 'choices'=>array('mark'=>__('透明线稿', 'crolord'), 'image'=>__('已上传的 Logo 图片', 'crolord')), 'description'=>__('简约标识，也可选用自己的图片。', 'crolord')));
}
function crolord_brand_style_sanitize($value) { return $value === 'image' ? 'image' : 'mark'; }
add_action('customize_register', 'crolord_brand_customize', 22);

function crolord_site_description() {
    $description = html_entity_decode((string) get_bloginfo('description', 'raw'), ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ?: 'UTF-8');
    $description = trim(wp_strip_all_tags($description));
    if ($description === '' || in_array($description, array('Just another WordPress site', '又一个WordPress站点', '又一个 WordPress 站点'), true)) $description = __('Ideas, Stories & Things I Build.', 'crolord');
    return $description;
}

function crolord_share_post() {
    if (!is_singular() || is_front_page()) return null;
    $post = get_post(get_queried_object_id());
    return $post && $post->post_status === 'publish' && !$post->post_password ? $post : null;
}
function crolord_social_description($description = '') {
    if ($description !== '') return $description;
    $post = crolord_share_post();
    if ($post) {
        $source = trim((string) $post->post_excerpt) !== '' ? $post->post_excerpt : $post->post_content;
        $source = preg_replace('~</(?:p|div|li|h[1-6]|figure)>|<br\s*/?>~i', ' ', strip_shortcodes($source));
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(wp_strip_all_tags($source), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        if ($text !== '') { preg_match('/\A.{0,160}/us', $text, $m); $short = rtrim($m[0] ?? ''); return $short . (strlen($short) < strlen($text) ? '…' : ''); }
    }
    return crolord_site_description();
}
function crolord_social_image($url = '') {
    if ($url !== '') return $url;
    $post = crolord_share_post();
    if ($post) {
        $featured = get_post_thumbnail_id($post->ID);
        if ($featured) { $image = wp_get_attachment_image_url($featured, 'full'); if ($image) return $image; }
        if (preg_match('~<img\b[^>]*\bsrc\s*=\s*([\'"])(.*?)\1~is', (string) $post->post_content, $m)) {
            $image = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (preg_match('~^https?://~i', $image)) return esc_url_raw($image);
        }
    }
    return crolord_brand_image_url(512);
}

/** Existing SEO providers keep ownership of their tag output. */
function crolord_has_social_provider() {
    return defined('WPSEO_VERSION') || defined('RANK_MATH_VERSION') || defined('AIOSEO_VERSION') || defined('SEOPRESS_VERSION') || defined('THE_SEO_FRAMEWORK_VERSION');
}

function crolord_social_metadata() {
    if (crolord_has_social_provider()) return;
    $name = wp_strip_all_tags(get_bloginfo('name'));
    $description = crolord_social_description();
    $post = crolord_share_post();
    $title = $post ? wp_strip_all_tags($post->post_title) : $name;
    $url = is_singular() ? get_permalink(get_queried_object_id()) : home_url('/');
    if (!is_singular() && !is_front_page() && !is_search() && !is_404()) {
        global $wp;
        $url = home_url(user_trailingslashit('/' . ltrim($wp->request ?? '', '/')));
    }
    $image = crolord_social_image();
    $brand_image = $image === crolord_brand_image_url(512);
    $tags = array('og:type'=>$post && $post->post_type === 'post' ? 'article' : 'website', 'og:site_name'=>$name, 'og:title'=>$title, 'og:description'=>$description, 'og:url'=>$url, 'og:locale'=>get_locale(), 'og:image'=>$image, 'og:image:alt'=>$brand_image ? sprintf(__('%s 的站点标识', 'crolord'), $name) : $title);
    if ($brand_image && !crolord_branding_logo_id()) $tags += array('og:image:type'=>'image/png', 'og:image:width'=>'512', 'og:image:height'=>'512');
    if (strpos($image, 'https://') === 0) $tags['og:image:secure_url'] = $image;
    printf("<meta name=\"description\" content=\"%s\">\n", esc_attr($description));
    foreach ($tags as $property => $value) printf("<meta property=\"%s\" content=\"%s\">\n", esc_attr($property), esc_attr($value));
    foreach (array('twitter:card'=>$brand_image?'summary':'summary_large_image', 'twitter:title'=>$title, 'twitter:description'=>$description, 'twitter:image'=>$image, 'twitter:image:alt'=>$tags['og:image:alt']) as $property => $value) printf("<meta name=\"%s\" content=\"%s\">\n", esc_attr($property), esc_attr($value));
}
add_action('wp_head', 'crolord_social_metadata', 5);

foreach (array('wpseo_opengraph_image', 'wpseo_twitter_image', 'rank_math/opengraph/facebook/image', 'rank_math/opengraph/twitter/image') as $hook) add_filter($hook, 'crolord_social_image', 20);
foreach (array('wpseo_opengraph_desc', 'wpseo_twitter_description', 'rank_math/opengraph/facebook/og_description', 'rank_math/opengraph/twitter/twitter_description') as $hook) add_filter($hook, 'crolord_social_description', 20);

function crolord_migrate_theme_identity() {
    if (!current_user_can('edit_theme_options') || get_option('crolord_identity_210_done')) return;
    $current = get_option('theme_mods_crolord', array());
    $legacy = get_option('theme_mods_fieldnotes-journal', array());
    if (is_array($legacy) && is_array($current)) {
        if (empty($current['nav_menu_locations']) && !empty($legacy['nav_menu_locations'])) $current['nav_menu_locations'] = $legacy['nav_menu_locations'];
        update_option('theme_mods_crolord', array_replace($legacy, $current));
    }
    update_option('crolord_identity_210_done', 1, false);
}
add_action('after_switch_theme', 'crolord_migrate_theme_identity');
add_action('admin_init', 'crolord_migrate_theme_identity', 1);

/** Keep page IDs and the site's existing permalink structure. */
function crolord_install_named_urls() {
    global $wp_rewrite;
    if (!current_user_can('manage_options') || wp_doing_ajax() || get_option('crolord_named_urls_210_done') || !is_object($wp_rewrite)) return;
    $lock = (int) get_option('crolord_named_urls_210_lock');
    if ($lock && $lock < time()) delete_option('crolord_named_urls_210_lock');
    if (!add_option('crolord_named_urls_210_lock', time() + 90, '', false)) return;
    try {
        $registry = array('about'=>'about', 'archive'=>'markets', 'map'=>'wayfarer', 'categories'=>'boughs', 'faves'=>'keepsakes', 'chatgpt'=>'echoes');
        $pages = array();
        foreach ($registry as $section => $slug) {
            $page = fieldnotes_section_page($section);
            if ($page) $pages[$page->ID] = array('slug'=>$slug, 'old_slug'=>$page->post_name, 'old_url'=>get_permalink($page));
        }
        foreach (array('cities') as $section) {
            $page = fieldnotes_room_page($section);
            if ($page) $pages[$page->ID] = array('slug'=>$section, 'old_slug'=>$page->post_name, 'old_url'=>get_permalink($page));
        }
        if (!get_option('crolord_named_urls_210_backup')) {
            if (!add_option('crolord_named_urls_210_backup', array('structure'=>get_option('permalink_structure'), 'pages'=>$pages), '', false)) return;
        }
        foreach ($pages as $id => $entry) {
            if ($entry['old_slug'] === $entry['slug']) continue;
            $result = wp_update_post(array('ID'=>$id, 'post_name'=>$entry['slug']), true);
            if (is_wp_error($result) || !$result) return;
            add_post_meta($id, '_wp_old_slug', $entry['old_slug']);
        }
        // This runs once in admin, after the theme/plugins registered their content types.
        flush_rewrite_rules(true);
        update_option('crolord_named_urls_210_done', 1, false);
    } finally { delete_option('crolord_named_urls_210_lock'); }
}
add_action('admin_init', 'crolord_install_named_urls', 80);

function crolord_redirect_old_page_paths() {
    if (!is_404() || is_admin() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET','HEAD'), true)) return;
    $backup = get_option('crolord_named_urls_210_backup', array());
    $requested = wp_parse_url(wp_unslash($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    foreach ($backup['pages'] ?? array() as $id => $entry) {
        $old = wp_parse_url($entry['old_url'], PHP_URL_PATH);
        if (wp_parse_url($entry['old_url'], PHP_URL_QUERY) || !$old || untrailingslashit($requested) !== untrailingslashit($old)) continue;
        if (get_post_status($id) !== 'publish') continue;
        $target = get_permalink($id);
        if (untrailingslashit(wp_parse_url($target, PHP_URL_PATH)) === untrailingslashit($old)) continue;
        wp_safe_redirect($target, 301, 'crolord');
        exit;
    }
}
add_action('template_redirect', 'crolord_redirect_old_page_paths', 8);




/** The uploaded original remains untouched; the matching SVG removes its paper. */
function crolord_original_portrait_id() {
    $id = crolord_branding_logo_id();
    return $id && wp_basename((string) get_attached_file($id)) === 'issac-original-avatar.png' ? $id : 0;
}

function crolord_original_portrait_url() {
    return get_template_directory_uri() . '/assets/branding/site-mark.svg?ver=' . rawurlencode(FIELDNOTES_VERSION);
}

/** Prefer the actual transparent asset over competing opaque browser icons. */
function crolord_original_portrait_icon($tags) {
    if (!crolord_original_portrait_id()) return $tags;
    $transparent = array();
    foreach ($tags as $tag) {
        if (preg_match('~\brel\s*=\s*(["\'])(?:shortcut\s+)?icon\1~i', $tag)) continue;
        $transparent[] = $tag;
    }
    $transparent[] = '<link rel="icon" type="image/svg+xml" sizes="any" href="' . esc_url(crolord_original_portrait_url()) . '">';
    return $transparent;
}
add_filter('site_icon_meta_tags', 'crolord_original_portrait_icon', 30);

/** Preserve the native attachment choice for WordPress and touch-icon fallbacks. */
function crolord_sync_portrait_identity() {
    $id = crolord_branding_logo_id();
    if ($id && (int) get_option('site_icon') !== $id) update_option('site_icon', $id);
}
add_action('customize_save_after', 'crolord_sync_portrait_identity', 30);
