<?php
/** Editorial collections, the unified work page and preserved legacy links. */
if (!defined('ABSPATH')) exit;

function fieldnotes_boughs_sections() {
    return array(
        'cities' => array('name' => '城市漫步', 'description' => '城市的片刻，日常的回声。', 'intro' => '沿着街巷走走，记下那些想停留的片刻。', 'topics' => array('心情随笔', '城市观察', '生活片段'), 'image' => 'kyoto-rain.webp'),
        'works' => array('name' => 'Boughs', 'description' => '主题、插件、开源项目与最佳实践。', 'intro' => '', 'topics' => array('WordPress', '开源项目', '最佳实践'), 'image' => 'studio-notes.webp'),
        'investing' => array('name' => '投资理财', 'description' => '观察市场，也记录每一次判断。', 'intro' => '把价格的变化与自己的思考放在同一页。看图、写下依据，再在时间里回看。', 'topics' => array('历史 K 线', '观察记录', '判断与复盘'), 'image' => 'studio-notes.webp'),
        'minimalism' => array('name' => '败家极简', 'description' => '生活里，那些安静而可靠的小选择。', 'intro' => '买过、用过，也认真取舍过。留下适合自己的器物，让日常更轻一点。', 'topics' => array('Keepsakes', '长期使用', '选择与取舍'), 'image' => 'studio-notes.webp'),
    );
}

function fieldnotes_boughs_key($key) {
    return in_array($key, array('wordpress', 'interludes', 'wordpress-themes', 'wordpress-plugins'), true) ? 'works' : $key;
}

function fieldnotes_boughs_term($slug) {
    $slug = fieldnotes_boughs_key($slug);
    if (!isset(fieldnotes_boughs_sections()[$slug])) return null;
    $term = get_term_by('slug', $slug, 'category');
    if ((!$term || is_wp_error($term)) && $slug === 'works') $term = get_term_by('slug', 'interludes', 'category');
    return $term && !is_wp_error($term) ? $term : null;
}

function fieldnotes_boughs_url($slug) {
    if ($slug === 'cities') return fieldnotes_section_url('map');
    if ($slug === 'minimalism') return fieldnotes_section_url('faves');
    if ($slug === 'investing') return fieldnotes_section_url('archive');
    if (fieldnotes_boughs_key($slug) === 'works') {
        $url = fieldnotes_section_url('categories');
        if ($slug === 'wordpress') $url = add_query_arg('work_kind', 'wordpress', $url);
        elseif ($slug === 'interludes') $url = add_query_arg('work_kind', 'projects', $url);
        return $url;
    }
    return fieldnotes_section_url('categories');
}

/** Keep the Cities category and page editable; public entrances lead to Wayfarer. */
function fieldnotes_city_category_link($url, $term, $taxonomy) {
    if ((fieldnotes_public_copy_enabled() || wp_doing_ajax()) && $taxonomy === 'category' && $term->slug === 'cities') return fieldnotes_section_url('map');
    return $url;
}
add_filter('term_link', 'fieldnotes_city_category_link', 20, 3);

function fieldnotes_city_page_link($url, $post_id) {
    if ((fieldnotes_public_copy_enabled() || wp_doing_ajax()) && get_post_meta($post_id, '_fieldnotes_room', true) === 'cities') return fieldnotes_section_url('map');
    return $url;
}
add_filter('page_link', 'fieldnotes_city_page_link', 20, 2);

function fieldnotes_city_redirect() {
    if (is_preview() || fieldnotes_boughs_context() !== 'cities') return;
    wp_safe_redirect(fieldnotes_section_url('map'), 301);
    exit;
}
add_action('template_redirect', 'fieldnotes_city_redirect', 4);

function fieldnotes_boughs_context() {
    $room = function_exists('fieldnotes_room_context') ? fieldnotes_room_context() : '';
    if ($room) return $room;
    if (!is_category()) return '';
    $term = get_queried_object();
    $key = $term ? fieldnotes_boughs_key($term->slug ?? '') : '';
    return isset(fieldnotes_boughs_sections()[$key]) ? $key : '';
}

function fieldnotes_boughs_assets() {
    $key = fieldnotes_boughs_context();
    if (!$key && !is_page_template('templates/page-categories.php')) return;
    wp_enqueue_style('fieldnotes-collections', get_template_directory_uri() . '/assets/css/collections.min.css', array('fieldnotes-editorial'), FIELDNOTES_VERSION);
    if ($key === 'investing') {
        wp_enqueue_style('fieldnotes-market', get_template_directory_uri() . '/assets/css/market.min.css', array('fieldnotes-collections'), FIELDNOTES_VERSION);
        wp_enqueue_script('fieldnotes-indicators', get_template_directory_uri() . '/assets/js/indicators.min.js', array(), FIELDNOTES_VERSION, true);
        wp_enqueue_script('fieldnotes-market', get_template_directory_uri() . '/assets/js/market.min.js', array('fieldnotes-indicators'), FIELDNOTES_VERSION, true);
        wp_localize_script('fieldnotes-market', 'fieldnotesMarket', array('ajaxUrl' => admin_url('admin-ajax.php', 'relative')));
    }
}
add_action('wp_enqueue_scripts', 'fieldnotes_boughs_assets', 30);

function fieldnotes_boughs_normalize_path($url) {
    $path = wp_parse_url($url, PHP_URL_PATH);
    return '/' . trim(rawurldecode(is_string($path) ? $path : ''), '/') . '/';
}

function fieldnotes_boughs_tools_menu() {
    add_theme_page('Boughs', 'Boughs', 'manage_categories', 'fieldnotes-boughs', 'fieldnotes_boughs_tools_page');
}
add_action('admin_menu', 'fieldnotes_boughs_tools_menu');

function fieldnotes_boughs_tools_page() {
    if (!current_user_can('manage_categories')) return;
    echo '<div class="wrap"><h1>Boughs</h1><p>主题、插件、开源项目与最佳实践。</p><div class="crolord-section-list">';
    foreach (fieldnotes_boughs_sections() as $slug => $section) {
        echo '<section><h2><a href="' . esc_url(fieldnotes_boughs_url($slug)) . '">' . esc_html($section['name']) . '</a></h2><p>' . esc_html($section['description']) . '</p></section>';
    }
    $url = wp_nonce_url(admin_url('admin-post.php?action=fieldnotes_boughs_backup'), 'fieldnotes_boughs_backup');
    echo '</div><details><summary>栏目归档</summary><p><a class="button" href="' . esc_url($url) . '">下载分类记录</a></p></details>';
    if (current_user_can('manage_options')) {
        $pages = array('首页' => home_url('/'), '地图' => fieldnotes_section_url('map'), 'Echoes' => fieldnotes_section_url('chatgpt'));
        $latest = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'no_found_rows' => true));
        if ($latest) $pages['最新文章'] = get_permalink($latest[0]);
        echo '<details><summary>性能检查</summary><p>';
        foreach ($pages as $label => $page) {
            $profile = wp_nonce_url(add_query_arg('crolord_profile', '1', $page), 'crolord_profile');
            echo '<a class="button" href="' . esc_url($profile) . '" target="_blank" rel="noopener">' . esc_html($label) . '</a> ';
        }
        echo '</p></details>';
    }
    echo '</div>';
}

function fieldnotes_boughs_download_backup() {
    if (!current_user_can('manage_categories')) wp_die('无权访问。', '', array('response' => 403));
    check_admin_referer('fieldnotes_boughs_backup');
    $backup = get_option('fieldnotes_boughs_170_backup', array());
    $backup['posts'] = array();
    $ids = get_posts(array('post_type' => 'post', 'post_status' => array_keys(get_post_stati()), 'numberposts' => -1, 'fields' => 'ids', 'meta_key' => '_fn_boughs_170_categories'));
    foreach ($ids as $id) $backup['posts'][] = array('id' => (int) $id, 'categories' => get_post_meta($id, '_fn_boughs_170_categories', true), 'old_path' => get_post_meta($id, '_fn_boughs_170_path', true));
    nocache_headers();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="boughs-original-categories.json"');
    echo wp_json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}
add_action('admin_post_fieldnotes_boughs_backup', 'fieldnotes_boughs_download_backup');

function fieldnotes_boughs_redirect_legacy() {
    if (!get_option('fieldnotes_boughs_170_done')) return;
    if (is_category()) {
        $term = get_queried_object();
        if ($term && isset(fieldnotes_boughs_sections()[$term->slug])) {
            $url = fieldnotes_boughs_url($term->slug);
            foreach (array('kind', 'stock', 'period', 'adjust', 'notes_page', 'faves_page') as $arg) if (isset($_GET[$arg]) && is_string($_GET[$arg])) $url = add_query_arg($arg, sanitize_text_field(wp_unslash($_GET[$arg])), $url);
            wp_safe_redirect($url, 302); exit;
        }
    }
    if (!is_404()) return;
    $path = fieldnotes_boughs_normalize_path(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
    $post = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 1, 'meta_key' => '_fn_boughs_170_path', 'meta_value' => $path));
    if ($post) { wp_safe_redirect(get_permalink($post[0]), 301); exit; }
    $backup = get_option('fieldnotes_boughs_170_backup', array());
    foreach ((array) ($backup['terms'] ?? array()) as $term) {
        if (!is_string($term['url']) || fieldnotes_boughs_normalize_path($term['url']) !== $path) continue;
        $target = in_array($term['slug'], array('wordpress-themes', 'wordpress-plugins', 'wordpress'), true) ? 'wordpress' : (in_array($term['slug'], array('investing', 'minimalism', 'interludes'), true) ? $term['slug'] : 'cities');
        wp_safe_redirect(fieldnotes_boughs_url($target), 301); exit;
    }
}
add_action('template_redirect', 'fieldnotes_boughs_redirect_legacy', 5);

/** These are native post_tag slugs; editing article tags changes the results. */
function fieldnotes_practice_topics() {
    return array('ai' => 'AI', 'cloud-native' => '云原生', 'observability' => '可观测', 'message-queue' => '消息队列');
}

function fieldnotes_practice_topic() {
    $topic = isset($_GET['topic']) && is_string($_GET['topic']) ? sanitize_key(wp_unslash($_GET['topic'])) : '';
    return isset(fieldnotes_practice_topics()[$topic]) ? $topic : '';
}

function fieldnotes_practice_topic_url($topic = '') {
    $url = fieldnotes_section_url('categories');
    if ($topic !== '' || fieldnotes_work_kind() === 'notes') $url = add_query_arg('work_kind', 'notes', $url);
    if (isset(fieldnotes_practice_topics()[$topic])) $url = add_query_arg('topic', $topic, $url);
    return $url . '#practice-notes';
}

function fieldnotes_practice_post_topics($post_id) {
    $tags = get_the_terms($post_id, 'post_tag');
    if (!$tags || is_wp_error($tags)) return array();
    $topics = fieldnotes_practice_topics();
    $result = array();
    foreach ($tags as $tag) {
        $url = isset($topics[$tag->slug]) ? fieldnotes_practice_topic_url($tag->slug) : get_term_link($tag);
        if (!is_wp_error($url)) $result[] = array('label' => $topics[$tag->slug] ?? $tag->name, 'url' => $url);
    }
    return $result;
}

function fieldnotes_boughs_collection_posts($term, $title = '这里的文字', $options = array()) {
    if (!$term) { echo '<p class="boughs-empty">第一篇文字，留给下一次认真记录。</p>'; return; }
    $practice = !empty($options['practice']) && fieldnotes_boughs_key($term->slug) === 'works';
    $topic = $practice ? fieldnotes_practice_topic() : '';
    $page = isset($_GET['notes_page']) && is_scalar($_GET['notes_page']) ? max(1, absint($_GET['notes_page'])) : 1;
    $query_args = array('post_type' => 'post', 'post_status' => 'publish', 'cat' => (int) $term->term_id, 'posts_per_page' => 8, 'paged' => $page, 'ignore_sticky_posts' => true, 'orderby' => array('date' => 'DESC', 'ID' => 'DESC'));
    if ($topic !== '') $query_args['tag_slug__in'] = array($topic);
    $query = new WP_Query($query_args);
    echo '<section class="boughs-entries' . ($practice ? ' boughs-practices' : '') . '"' . ($practice ? ' id="practice-notes"' : '') . '>';
    if ($title !== '') {
        echo '<div class="boughs-section-heading"><div><h2>' . esc_html($title) . '</h2>';
        echo '</div></div>';
    }
    if ($practice) {
        echo '<div class="boughs-practice-browser"><nav class="boughs-topic-filter" aria-label="按标签筛选最佳实践">';
        foreach (fieldnotes_practice_topics() as $slug => $label) {
            $selected = $topic === $slug;
            echo '<a data-work-filter href="' . esc_url(fieldnotes_practice_topic_url($selected ? '' : $slug)) . '"' . ($selected ? ' aria-current="page" aria-label="' . esc_attr('取消筛选：' . $label) . '"' : '') . '><span>' . esc_html($label) . '</span>' . ($selected ? '<span class="fn-filter-remove" aria-hidden="true">×</span>' : '') . '</a>';
        }
        echo '</nav><div class="boughs-practice-results">';
    }
    if ($query->have_posts()) {
        echo '<div class="boughs-entry-list">';
        $entry_index = 0;
        while ($query->have_posts()) {
            $query->the_post();
            get_template_part('template-parts/collection-entry', null, array('featured' => $term->slug === 'cities' && $page === 1 && $entry_index === 0, 'practice' => $practice));
            $entry_index++;
        }
        echo '</div>';
        if ($query->max_num_pages > 1) {
            $base = $practice ? fieldnotes_work_kind_url('notes') : fieldnotes_boughs_url($term->slug);
            if ($topic !== '') $base = add_query_arg('topic', $topic, $base);
            echo '<nav class="boughs-pagination" aria-label="文章分页">' . wp_kses_post(paginate_links(array('base' => add_query_arg('notes_page', '%#%', $base), 'format' => '', 'current' => $page, 'total' => $query->max_num_pages, 'prev_text' => '上一页', 'next_text' => '下一页', 'add_fragment' => $practice ? '#practice-notes' : ''))) . '</nav>';
        }
    } else echo '<p class="boughs-empty">' . ($practice && $topic !== '' ? '这个方向的记录还在整理。' : '第一篇文字，留给下一次认真记录。') . '</p>';
    wp_reset_postdata();
    if ($practice) echo '</div></div>';
    echo '</section>';
}

/** Preserve old links while keeping one published work destination. */
function fieldnotes_works_category_link($url, $term, $taxonomy) {
    if ((fieldnotes_public_copy_enabled() || wp_doing_ajax()) && $taxonomy === 'category' && fieldnotes_boughs_key($term->slug) === 'works') return fieldnotes_work_kind_url('notes') . '#practice-notes';
    return $url;
}
add_filter('term_link', 'fieldnotes_works_category_link', 21, 3);

function fieldnotes_works_page_link($url, $post_id) {
    if (!(fieldnotes_public_copy_enabled() || wp_doing_ajax())) return $url;
    $key = get_post_meta($post_id, '_fieldnotes_room', true);
    return in_array($key, array('wordpress', 'interludes'), true) ? fieldnotes_boughs_url($key) : $url;
}
add_filter('page_link', 'fieldnotes_works_page_link', 21, 2);

function fieldnotes_works_redirect() {
    if (is_admin() || is_preview() || !in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', array('GET', 'HEAD'), true)) return;
    $legacy = '';
    if (isset($_GET['room']) && is_string($_GET['room']) && in_array($_GET['room'], array('wordpress', 'interludes', 'works'), true)) $legacy = $_GET['room'];
    if (!$legacy && is_singular('page') && !is_page_template('templates/page-categories.php')) {
        $key = get_post_meta(get_queried_object_id(), '_fieldnotes_room', true);
        if (in_array($key, array('wordpress', 'interludes', 'works'), true)) $legacy = $key;
    }
    if (!$legacy && is_category()) {
        $term = get_queried_object();
        if ($term && fieldnotes_boughs_key($term->slug) === 'works') $legacy = 'notes';
    }
    if (!$legacy && is_404()) {
        $path = fieldnotes_boughs_normalize_path(wp_unslash($_SERVER['REQUEST_URI'] ?? '/'));
        $paths = array('/wordpress/' => 'wordpress', '/interludes/' => 'interludes', '/category/wordpress/' => 'notes', '/category/interludes/' => 'notes', '/category/works/wordpress/' => 'notes');
        $legacy = $paths[$path] ?? '';
        if (!$legacy && isset($_GET['page_id']) && is_scalar($_GET['page_id'])) {
            $key = get_post_meta(absint($_GET['page_id']), '_fieldnotes_room', true);
            if (in_array($key, array('wordpress', 'interludes', 'works'), true)) $legacy = $key;
        }
    }
    if (!$legacy) return;
    $kind = $legacy === 'wordpress' ? 'wordpress' : ($legacy === 'interludes' ? 'projects' : 'notes');
    $topic = fieldnotes_practice_topic();
    if ($topic !== '' || isset($_GET['notes_page'])) $kind = 'notes';
    $url = fieldnotes_work_kind_url($kind);
    if ($topic !== '') $url = add_query_arg('topic', $topic, $url);
    if (isset($_GET['notes_page']) && is_scalar($_GET['notes_page'])) $url = add_query_arg('notes_page', max(1, absint($_GET['notes_page'])), $url);
    if ($kind === 'notes') $url .= '#practice-notes';
    wp_safe_redirect($url, 301, 'Crolord');
    exit;
}
add_action('template_redirect', 'fieldnotes_works_redirect', 4);
