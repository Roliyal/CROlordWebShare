<?php
/** crolord theme functions. */
if (!defined('ABSPATH')) { exit; }

define('FIELDNOTES_VERSION', '2.4.56');

require_once get_template_directory() . '/inc/performance.php';
require_once get_template_directory() . '/inc/editorial.php';
require_once get_template_directory() . '/inc/profile.php';
require_once get_template_directory() . '/inc/poetic.php';
require_once get_template_directory() . '/inc/collections.php';
require_once get_template_directory() . '/inc/market.php';
require_once get_template_directory() . '/inc/intraday.php';
require_once get_template_directory() . '/inc/market-live.php';
require_once get_template_directory() . '/inc/keepsakes.php';
require_once get_template_directory() . '/inc/samples.php';
require_once get_template_directory() . '/inc/catalog.php';
require_once get_template_directory() . '/inc/motion.php';
require_once get_template_directory() . '/inc/ui.php';
require_once get_template_directory() . '/inc/branding.php';
require_once get_template_directory() . '/inc/article-actions.php';
require_once get_template_directory() . '/inc/rooms.php';

function fieldnotes_setup() {
    load_theme_textdomain('crolord', get_template_directory() . '/languages');
    add_theme_support('title-tag');
    add_theme_support('post-thumbnails');
    add_theme_support('custom-logo', array('height' => 192, 'width' => 192, 'flex-height' => true, 'flex-width' => true));
    add_theme_support('html5', array('search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script'));
    add_theme_support('automatic-feed-links');
    add_theme_support('responsive-embeds');
    add_image_size('fieldnotes-card', 520, 390, true);
    register_nav_menus(array('primary' => __('主导航', 'crolord')));
}
add_action('after_setup_theme', 'fieldnotes_setup');

function fieldnotes_assets() {
    if (get_stylesheet() === get_template()) {
        $sheet='core';
        if(!defined('CROLORD_FULL_STYLES')||!CROLORD_FULL_STYLES){
            if(is_front_page())$sheet='home';
            elseif(crolord_plain_conversation_page())$sheet='conversation';
        }
        wp_enqueue_style('fieldnotes-style', get_template_directory_uri() . '/assets/css/'.$sheet.'.min.css', array(), FIELDNOTES_VERSION);
        // Keep public handles usable by extensions without additional HTTP requests.
        wp_register_style('fieldnotes-refinement', false, array('fieldnotes-style'), FIELDNOTES_VERSION);
        wp_register_style('fieldnotes-editorial', false, array('fieldnotes-refinement'), FIELDNOTES_VERSION);
        wp_enqueue_style('fieldnotes-editorial');
    } else {
        // Child themes need the parent's base rules even without a CSS @import.
        wp_enqueue_style('fieldnotes-parent', get_template_directory_uri() . '/style.css', array(), FIELDNOTES_VERSION);
        wp_enqueue_style('fieldnotes-style', get_stylesheet_uri(), array('fieldnotes-parent'), wp_get_theme()->get('Version'));
        wp_enqueue_style('fieldnotes-refinement', get_template_directory_uri() . '/assets/css/refinement.min.css', array('fieldnotes-style'), FIELDNOTES_VERSION);
        wp_enqueue_style('fieldnotes-editorial', get_template_directory_uri() . '/assets/css/editorial.min.css', array('fieldnotes-refinement'), FIELDNOTES_VERSION);
    }
    wp_enqueue_script('fieldnotes-main', get_template_directory_uri() . '/assets/js/site.min.js', array(), FIELDNOTES_VERSION, true);
    if (is_singular() && comments_open() && get_option('thread_comments')) {
        wp_enqueue_script('comment-reply');
        wp_script_add_data('comment-reply', 'strategy', 'defer');
    }
    if (is_singular('post')) wp_enqueue_script('fieldnotes-editorial', get_template_directory_uri() . '/assets/js/editorial.min.js', array(), FIELDNOTES_VERSION, true);
    wp_localize_script('fieldnotes-main', 'fieldnotesConfig', array(
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('fieldnotes_load_more'),
        'loadingLabel' => __('正在加载…', 'crolord'),
        'moreLabel' => __('加载更多文章', 'crolord'),
    ));
}
add_action('wp_enqueue_scripts', 'fieldnotes_assets');

function fieldnotes_widgets_init() {
    register_sidebar(array('name' => __('博客侧栏', 'crolord'), 'id' => 'journal-sidebar', 'description' => __('显示在文章列表旁边的小工具区域。', 'crolord'), 'before_widget' => '<section class="sidebar-block widget">', 'after_widget' => '</section>', 'before_title' => '<h2>', 'after_title' => '</h2>'));
}
add_action('widgets_init', 'fieldnotes_widgets_init');

function fieldnotes_register_content_types() {
    register_post_type('journal_fave', array(
        'labels' => array('name' => __('Keepsakes', 'crolord'), 'singular_name' => __('物品', 'crolord'), 'add_new_item' => __('添加物品', 'crolord'), 'edit_item' => __('编辑物品', 'crolord')),
        'public' => true, 'show_in_rest' => true, 'menu_icon' => 'dashicons-heart', 'has_archive' => 'fave-items',
        'rewrite' => array('slug' => 'fave-items'), 'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'),
    ));
    register_taxonomy('fave_kind', 'journal_fave', array('labels' => array('name' => __('物品分类', 'crolord')), 'public' => true, 'show_in_rest' => true, 'hierarchical' => true, 'rewrite' => array('slug' => 'fave-kind')));
}
add_action('init', 'fieldnotes_register_content_types');

function fieldnotes_customize_register($wp_customize) {
    $wp_customize->add_section('fieldnotes_options', array('title' => __('博客简介与公告', 'crolord'), 'priority' => 35));
    $settings = array(
        'fieldnotes_notice' => array(__('顶部公告内容', 'crolord'), ''),
        'fieldnotes_notice_url' => array(__('公告链接地址', 'crolord'), ''),
        'fieldnotes_profile' => array(__('个人自述', 'crolord'), ''),
    );
    foreach ($settings as $id => $details) {
        $wp_customize->add_setting($id, array('default' => $details[1], 'sanitize_callback' => 'sanitize_text_field'));
        $wp_customize->add_control($id, array('label' => $details[0], 'section' => 'fieldnotes_options', 'type' => 'text'));
    }
}
add_action('customize_register', 'fieldnotes_customize_register');

function fieldnotes_seed_site() {
    $categories = array();
    foreach (fieldnotes_boughs_sections() as $slug => $section) $categories[] = array($section['name'], $slug, $section['description']);
    foreach ($categories as $cat) if (!term_exists($cat[0], 'category')) wp_insert_term($cat[0], 'category', array('slug' => $cat[1], 'description' => $cat[2]));
    $faves = array(array('Life Style','life-style'), array('Personal','personal'), array('Workspace','workspace'), array('Gear','gear'));
    foreach ($faves as $term) if (!term_exists($term[0], 'fave_kind')) wp_insert_term($term[0], 'fave_kind', array('slug' => $term[1]));
    fieldnotes_seed_demo_content();
    $pages = array('about' => array('About', 'templates/page-about.php'), 'archive' => array('Markets', 'templates/page-archive.php'), 'map' => array(fieldnotes_section_heading('map'), 'templates/page-map.php'), 'categories' => array(fieldnotes_section_heading('categories'), 'templates/page-categories.php'), 'faves' => array(fieldnotes_section_heading('faves'), 'templates/page-faves.php'), 'chatgpt' => array('Echoes', 'templates/page-chatgpt.php', '[fieldnotes_chatgpt]'));
    $page_ids = array();
    foreach ($pages as $slug => $page) {
        $existing = fieldnotes_section_page($slug);
        $page_id = $existing ? $existing->ID : wp_insert_post(array('post_type' => 'page', 'post_status' => 'publish', 'post_title' => $page[0], 'post_name' => $slug, 'post_content' => isset($page[2]) ? $page[2] : ''));
        if ($page_id && !is_wp_error($page_id)) { update_post_meta($page_id, '_wp_page_template', $page[1]); $page_ids[$slug] = $page_id; }
    }
    $locations = (array) get_theme_mod('nav_menu_locations', array());
    $menu = !empty($locations['primary']) ? wp_get_nav_menu_object(absint($locations['primary'])) : false;
    if (!$menu) $menu = wp_get_nav_menu_object('Roliyal 主导航');
    if (!$menu) $menu = wp_get_nav_menu_object('博客导航');
    if (!$menu) {
        $menu_id = wp_create_nav_menu('Roliyal 主导航');
        if (!is_wp_error($menu_id)) {
            foreach (array('about', 'archive', 'map', 'categories', 'faves', 'chatgpt') as $slug) if (!empty($page_ids[$slug])) wp_update_nav_menu_item($menu_id, 0, array('menu-item-title' => fieldnotes_section_label($slug), 'menu-item-object' => 'page', 'menu-item-object-id' => $page_ids[$slug], 'menu-item-type' => 'post_type', 'menu-item-status' => 'publish'));
            $locations['primary'] = $menu_id; set_theme_mod('nav_menu_locations', $locations);
        }
    }
    flush_rewrite_rules();
}
add_action('after_switch_theme', 'fieldnotes_seed_site');

function fieldnotes_seed_demo_content() {
    if (get_option('fieldnotes_demo_content_seeded')) return;
    $existing = get_posts(array('post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 2, 'fields' => 'ids'));
    $hello_world_only = count($existing) === 1 && get_post_field('post_name', $existing[0]) === 'hello-world';
    if (!empty($existing) && !$hello_world_only) {
        update_option('fieldnotes_demo_content_seeded', 1);
        return;
    }
    $samples = array(
        array('把博客当作一间长期经营的小屋', 'notes', '2025-10-03', '这几年我越来越喜欢把博客当作一间慢慢打理的小屋。首页不必堆满功能，分类也不必追着热点调整。把读到的书、路过的城市、买过的东西和偶尔冒出的念头放在合适的位置，过一阵子再回来看看，日子就有了清楚的纹理。', '把博客当作一间长期经营的小屋'),
        array('京都巷子里的雨后晚风', 'footprint', '2025-05-18', '雨停后，从鸭川旁边的小路慢慢往东走。石板路还留着潮湿的光，屋檐下的风铃偶尔响一声。没有赶着去下一个景点，只在街角找了家小店吃晚饭，才发现旅行里最舒服的时刻，常常是不需要做决定的时候。', 'demo-travel-kyoto'),
        array('台北，在早餐店醒来', 'footprint', '2025-04-21', '早上七点走进巷口的早餐店，铁板上有煎蛋和吐司的香气。城市刚醒，机车从路口经过，老板熟练地问今天要不要加一杯豆浆。吃完沿着街区散步，才把时差和旅途的疲惫一点点放下。', 'demo-travel-taipei'),
        array('在巴黎，把行程放慢一点', 'footprint', '2025-03-12', '巴黎的第二天没有安排博物馆清单。我在塞纳河边坐了一会儿，买了咖啡和面包，沿着小街看橱窗里的旧书与花束。城市的美并不总在明信片角度里，也在普通人下班回家时那种自然的从容里。', 'demo-travel-paris'),
        array('阿姆斯特丹的自行车与运河', 'cities', '2025-02-26', '运河边的房子像一排安静的舞台布景，自行车则是城市真正的主角。跟着路口的铃声走，偶尔停下来看看水面和桥洞。天气忽晴忽雨，咖啡馆的窗子总是暖黄色，适合临时躲一阵再出发。', 'demo-travel-amsterdam'),
        array('布拉格黄昏记', 'footprint', '2024-12-09', '傍晚从老城广场往河边走，屋顶的颜色渐渐沉下来。桥上的人很多，风也比白天冷。我们没有急着拍照，只在塔影里慢慢走，等河面亮起灯光后才回去。那一晚的红瓦和钟声，后来常常出现在记忆里。', 'demo-travel-prague'),
        array('大阪街角的一碗乌冬', 'footprint', '2024-11-20', '大阪的街道适合边走边吃。中午躲进一家不太起眼的乌冬店，汤头清爽，桌面只有几种简单的调料。饭后沿着商店街继续往前，路过唱片行、花店和刚开门的小酒馆，觉得这座城的热闹也有很松弛的一面。', 'demo-travel-osaka'),
        array('桌面留一点空白给正在做的事', 'minimalism', '2024-09-16', '整理桌面时，我会先把暂时不用的东西移出视线，再留下每天真的会碰到的几样。空白不是为了拍照，而是让手边的事情更容易开始。少买一点收纳盒，多观察哪些物品其实很久没有被使用，反而更有效。', '桌面留一点空白给正在做的事'),
        array('一台轻便相机带来的散步习惯', 'reviews', '2024-07-08', '轻便相机没有让我拍出更多照片，却让我更愿意带着它出门。沿着熟悉街区走一圈，观察光怎么落在墙面和树叶上。照片未必要发出来，能提醒自己那天确实停下来认真看过，就已经足够。', '一台轻便相机带来的散步习惯'),
        array('长期主义也可以从每周半小时开始', 'long-termism', '2024-05-11', '长期计划听起来很大，落实到生活里往往只是固定的一小段时间。每周留半小时读书、整理账目或写几行字，慢慢就会形成自己的节奏。做得少一些没关系，关键是下次还愿意回来继续。', '长期主义也可以从每周半小时开始'),
        array('收拾行李时，我只带真正会用的东西', 'lifestyle', '2024-03-02', '旅行行李越少，临出门前的心情越轻。现在我会先写下每天确定会用的东西，再为天气和意外留一点余地。那些只因为“也许会用到”而塞进去的物件，大多数时候都可以留在家里。', '收拾行李时，我只带真正会用的东西'),
        array('城市漫步：从一条没有目的的路开始', 'cities', '2024-01-14', '给自己一小时，不导航、不赶路，从一个平时不会下车的地铁站开始走。小店的招牌、窗台上的植物和公园里晒太阳的人，会让熟悉的城市显出另一面。回家时记下路线，下次可以再从这里继续。', '城市漫步：从一条没有目的的路开始')
    );
    foreach ($samples as $sample) {
        if (get_page_by_path($sample[4], OBJECT, 'post')) continue;
        $term = get_term_by('slug', $sample[1], 'category');
        if (!$term) $term = get_term_by('slug', 'cities', 'category');
        $post_id = wp_insert_post(array(
            'post_type' => 'post', 'post_status' => 'publish', 'post_title' => $sample[0],
            'post_name' => $sample[4], 'post_date' => $sample[2] . ' 09:00:00',
            'post_content' => '<p>' . esc_html($sample[3]) . '</p>',
            'post_excerpt' => $sample[3], 'post_category' => $term ? array((int) $term->term_id) : array(),
            'comment_status' => 'open'
        ));
    }
    $fave_items = array(
        array('一册耐写的随身笔记本', 'Personal', '纸张不反光，尺寸适合放进外套口袋。用来记旅行路线、临时想法和需要稍后处理的小事。'),
        array('桌面阅读灯', 'Workspace', '光线柔和，亮度调节简单。晚上读书时只照亮手边一小块区域，桌面看起来也不显拥挤。'),
        array('轻便随身相机', 'Gear', '适合日常散步和短途旅行的相机配置。体积小、启动快，让记录生活这件事变得没有负担。'),
        array('可以每天用的陶杯', 'Life Style', '杯口顺手，容量不大不小。早晨泡茶或下午喝咖啡时，拿起它就知道该暂停几分钟。'),
        array('旅行收纳袋', 'Gear', '简单分装充电线、药品和洗漱用品。颜色容易辨认，落在行李箱里也不需要翻找很久。'),
        array('一把顺手的机械键盘', 'Workspace', '键程清晰，长时间写作时手指比较放松。布局尽量简单，让注意力留在文字本身。'),
        array('散步时听的轻量耳机', 'Gear', '佩戴稳定，通勤时可以听播客，走到公园之后也能很快收进小包里。'),
        array('周末做早餐的小托盘', 'Life Style', '木质托盘让简单的早餐更方便端取。吃完擦一下就能收好，不需要特别讲究的仪式。')
    );
    foreach ($fave_items as $item) {
        $existing_fave = get_page_by_title($item[0], OBJECT, 'journal_fave');
        if ($existing_fave) continue;
        $fave_id = wp_insert_post(array('post_type' => 'journal_fave', 'post_status' => 'publish', 'post_title' => $item[0], 'post_excerpt' => $item[2], 'post_content' => '<p>' . esc_html($item[2]) . '</p>'));
        $kind = get_term_by('name', $item[1], 'fave_kind');
        if ($fave_id && $kind) wp_set_object_terms($fave_id, array((int) $kind->term_id), 'fave_kind');
    }
    update_option('fieldnotes_demo_content_seeded', 1);
}

function fieldnotes_seed_demo_comments() {
    if (get_option('fieldnotes_demo_comments_seeded') || !get_option('fieldnotes_demo_content_seeded')) return;
    $comments = array(
        array('demo-travel-kyoto', '阿澄', 'traveler', '雨后的巷子很适合慢慢走，照片和文字都让人想起那阵晚风。'),
        array('demo-travel-kyoto', '林间散步', 'sakura', '鸭川附近那一段我也很喜欢，不赶时间的时候会发现很多小店。'),
        array('demo-travel-taipei', '小满', 'cat', '早餐店的豆浆配蛋饼，是每次去台北都会想念的味道。'),
        array('demo-travel-paris', '周末旅人', 'mountain', '留一点空白给散步，反而比赶行程更容易记住这座城市。'),
        array('demo-travel-amsterdam', '青禾', 'leaf', '运河边的光线变化很美，读完这篇又想找个晴天去走走。'),
        array('demo-travel-prague', '南窗', 'book', '黄昏的老城屋顶应该特别漂亮，收藏这条散步路线了。'),
    );
    $created = false;
    foreach ($comments as $row) {
        $post = get_page_by_path($row[0], OBJECT, 'post');
        if (!$post || get_comments_number($post->ID)) continue;
        $comment_id = wp_insert_comment(array(
            'comment_post_ID' => (int) $post->ID,
            'comment_author' => $row[1],
            'comment_author_email' => 'reader' . absint($post->ID) . '@example.test',
            'comment_content' => $row[3],
            'comment_type' => 'comment',
            'comment_approved' => 1,
            'comment_date' => current_time('mysql'),
            'comment_date_gmt' => current_time('mysql', true),
        ));
        if ($comment_id) {
            add_comment_meta($comment_id, '_fct_avatar', $row[2], true);
            $created = true;
        }
    }
    update_option('fieldnotes_demo_comments_seeded', 1);
}
// Existing comments are preserved. New samples come only from the explicitly
// labelled sample workflow, never from this legacy visitor-style seed routine.

function fieldnotes_menu_fallback() {
    echo '<nav class="primary-nav" aria-label="' . esc_attr__('主导航', 'crolord') . '">';
    $items = fieldnotes_section_labels();
    foreach ($items as $slug => $label) {
        $url = fieldnotes_section_url($slug);
        echo '<a href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
    }
    echo '</nav>';
}

function fieldnotes_track_views() {
    if (is_singular('post') && !is_preview() && !is_admin()) {
        $id = get_queried_object_id(); $cookie = 'fieldnotes_view_' . $id;
        if (empty($_COOKIE[$cookie])) {
            $views = (int) get_post_meta($id, 'fieldnotes_views', true); update_post_meta($id, 'fieldnotes_views', $views + 1);
            setcookie($cookie, '1', time() + HOUR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);
        }
    }
}
add_action('template_redirect', 'fieldnotes_track_views');

function fieldnotes_ajax_load_more() {
    check_ajax_referer('fieldnotes_load_more', 'nonce');
    $page = isset($_POST['page']) ? max(2, absint($_POST['page'])) : 2;
    $query = new WP_Query(array('post_type' => 'post', 'post_status' => 'publish', 'posts_per_page' => 8, 'paged' => $page, 'ignore_sticky_posts' => true, 'meta_query' => array(array('key' => '_fieldnotes_sample_key', 'compare' => 'NOT EXISTS')), 'orderby' => array('date' => 'DESC', 'ID' => 'DESC')));
    ob_start();
    while ($query->have_posts()) { $query->the_post(); get_template_part('template-parts/post-card'); }
    $html = ob_get_clean();
    $has_more = $page < (int) $query->max_num_pages;
    wp_reset_postdata();
    wp_send_json_success(array('html' => $html, 'page' => $page, 'hasMore' => $has_more));
}
add_action('wp_ajax_fieldnotes_load_more', 'fieldnotes_ajax_load_more');
add_action('wp_ajax_nopriv_fieldnotes_load_more', 'fieldnotes_ajax_load_more');

function fieldnotes_popular_posts($limit = 5) {
    $posts = get_posts(array('post_type' => 'post', 'numberposts' => $limit, 'meta_key' => 'fieldnotes_views', 'orderby' => 'meta_value_num', 'order' => 'DESC'));
    if (!$posts) $posts = get_posts(array('post_type' => 'post', 'numberposts' => $limit, 'orderby' => 'comment_count', 'order' => 'DESC'));
    return $posts;
}

function fieldnotes_demo_image($post_id = 0, $category_slug = null) {
    $post_id = $post_id ? absint($post_id) : get_the_ID();
    $saved = get_post_meta($post_id, '_fn_boughs_170_image', true);
    if (is_string($saved) && in_array($saved, array('kyoto-rain.webp', 'coast-dawn.webp', 'studio-notes.webp', 'paris-rain.webp'), true)) return get_template_directory_uri() . '/assets/photos/' . $saved;
    $categories = $category_slug === null ? get_the_category($post_id) : array();
    $slug = $category_slug !== null ? (string) $category_slug : (!empty($categories) ? $categories[0]->slug : '');
    $sets = array(
        'footprint' => array('kyoto-rain.webp', 'coast-dawn.webp', 'paris-rain.webp'),
        'getaway' => array('coast-dawn.webp', 'kyoto-rain.webp', 'paris-rain.webp'),
        'cities' => array('paris-rain.webp', 'kyoto-rain.webp', 'coast-dawn.webp'),
        'minimalism' => array('studio-notes.webp'),
        'reviews' => array('studio-notes.webp'),
        'lifestyle' => array('studio-notes.webp', 'coast-dawn.webp'),
        'long-termism' => array('studio-notes.webp'),
        'notes' => array('studio-notes.webp', 'kyoto-rain.webp'),
        'things-i-buy' => array('studio-notes.webp'),
        'wordpress-themes' => array('studio-notes.webp'),
        'wordpress-plugins' => array('studio-notes.webp'),
    );
    $choices = isset($sets[$slug]) ? $sets[$slug] : array('kyoto-rain.webp', 'coast-dawn.webp', 'studio-notes.webp', 'paris-rain.webp');
    $index = $post_id ? $post_id % count($choices) : 0;
    return get_template_directory_uri() . '/assets/photos/' . $choices[$index];
}

function fieldnotes_topic_image($slug) {
    $slug = sanitize_title($slug);
    $images = array(
        'footprint' => array('kyoto-rain.webp', 'coast-dawn.webp', 'paris-rain.webp'),
        'getaway' => array('coast-dawn.webp', 'kyoto-rain.webp', 'paris-rain.webp'),
        'cities' => array('paris-rain.webp', 'kyoto-rain.webp', 'coast-dawn.webp'),
        'small-pleasures' => array('coast-dawn.webp', 'studio-notes.webp'),
        'lifestyle' => array('coast-dawn.webp', 'studio-notes.webp'),
        'life-style' => array('coast-dawn.webp', 'studio-notes.webp'),
        'personal' => array('studio-notes.webp', 'kyoto-rain.webp'),
        'workspace' => array('studio-notes.webp', 'paris-rain.webp'),
        'gear' => array('studio-notes.webp', 'coast-dawn.webp'),
        'minimalism' => array('studio-notes.webp'),
        'long-termism' => array('studio-notes.webp', 'paris-rain.webp'),
        'reviews' => array('studio-notes.webp', 'paris-rain.webp'),
        'things-i-buy' => array('studio-notes.webp', 'coast-dawn.webp'),
        'wordpress-themes' => array('studio-notes.webp', 'paris-rain.webp'),
        'wordpress-plugins' => array('studio-notes.webp'),
        'notes' => array('studio-notes.webp', 'kyoto-rain.webp'),
    );
    $choices = isset($images[$slug]) ? $images[$slug] : array('kyoto-rain.webp', 'paris-rain.webp', 'coast-dawn.webp', 'studio-notes.webp');
    $index = hexdec(substr(md5($slug), 0, 4)) % count($choices);
    return get_template_directory_uri() . '/assets/photos/' . $choices[$index];
}

function fieldnotes_fave_image($post_id) {
    $post_id = absint($post_id);
    $image = get_the_post_thumbnail_url($post_id, 'medium_large');
    if ($image) return $image;
    $title = wp_strip_all_tags(get_the_title($post_id));
    $objects = array('笔记'=>'notebook', '灯'=>'lamp', '相机'=>'camera', '杯'=>'mug', '袋'=>'bag', '耳机'=>'headphones', '键盘'=>'keyboard', '托盘'=>'tray');
    foreach ($objects as $word => $object) if (strpos($title, $word) !== false) return get_template_directory_uri() . '/assets/objects/' . $object . '.svg';
    return get_template_directory_uri() . '/assets/objects/notebook.svg';
}


