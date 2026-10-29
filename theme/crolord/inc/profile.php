<?php
/** Editable profile copy. Reading existing content never changes stored data. */
if (!defined('ABSPATH')) exit;

function fieldnotes_profile_details($author_id) {
    $author = fieldnotes_article_author($author_id);
    $public_name = fieldnotes_clean_text(get_theme_mod('fieldnotes_about_name', 'issac'));
    if ($public_name !== '') {
        $author['name'] = $public_name;
        $author['initial'] = wp_html_excerpt($public_name, 1, '');
    }
    $heading = trim((string) get_theme_mod('fieldnotes_about_heading', ''));
    if (preg_match('/^你好[，,]我是\s*issac[。.]?$/u', $heading)) $heading = '';
    $intro = trim((string) get_theme_mod('fieldnotes_about_intro', ''));
    if ($intro === '') $intro = trim((string) get_theme_mod('fieldnotes_profile', ''));
    return array(
        'author' => $author,
        'heading' => $heading !== '' ? $heading : sprintf(__('Hi, I’m %s.', 'crolord'), $author['name']),
        'role' => fieldnotes_clean_text(get_theme_mod('fieldnotes_about_role', __('独立开发者', 'crolord'))),
        'location' => fieldnotes_clean_text(get_theme_mod('fieldnotes_about_location', __('成都，中国', 'crolord'))),
        'intro' => $intro !== '' ? $intro : __('写代码，记录日常。关心云原生、开源和摄影。', 'crolord'),
        'contact' => fieldnotes_clean_text(get_theme_mod('fieldnotes_about_contact', __('有合适的项目，或只是想聊聊，欢迎来信。', 'crolord'))),
        'note' => fieldnotes_clean_text(get_theme_mod('fieldnotes_about_note', __('遑遑三十载，书剑两无成。', 'crolord'))),
    );
}

function crolord_profile_links() {
    $links = array();
    foreach (array('github' => array('GitHub', 'https://github.com/Roliyal'), 'x' => array('X', 'https://x.com/Roliyal_')) as $key => $item) {
        $url = esc_url_raw(get_theme_mod('fieldnotes_about_' . $key, $item[1]), array('https'));
        if ($url) $links[] = array('label' => $item[0], 'url' => $url, 'external' => true);
    }
    $email = sanitize_email(get_theme_mod('fieldnotes_about_email', 'issac@roliyal.com'));
    if ($email) $links[] = array('label' => __('Email', 'crolord'), 'url' => 'mailto:' . $email, 'external' => false);
    return $links;
}

function fieldnotes_profile_destinations() {
    $items = array(
        'archive' => array(fieldnotes_section_label('archive'), __('记录判断，留待回看。', 'crolord'), __('分时与 K 线，观察与复盘。', 'crolord')),
        'map' => array(fieldnotes_section_label('map'), __('沿途，有回声。', 'crolord'), __('从地图上的一个地方，走进一段旅途。', 'crolord')),
        'faves' => array(fieldnotes_section_label('faves'), __('好物，长相伴。', 'crolord'), __('生活里，那些安静而可靠的小选择。', 'crolord')),
    );
    $links = array();
    foreach ($items as $slug => $copy) {
        $page = fieldnotes_section_page($slug);
        if (!$page || get_post_status($page) !== 'publish') continue;
        $links[] = array('label' => $copy[0], 'title' => $copy[1], 'description' => $copy[2], 'url' => fieldnotes_section_url($slug));
    }
    return $links;
}

function fieldnotes_profile_customize($customizer) {
    $customizer->add_section('fieldnotes_profile_page', array(
        'title' => __('博主简介页面', 'crolord'),
        'description' => __('简短自述与联系链接。正文仍可在「页面 > 博主简介」编辑；公开名称留空时使用页面作者的显示名称。', 'crolord'),
        'priority' => 36,
    ));
    $fields = array(
        'fieldnotes_about_name' => array(__('公开名称', 'crolord'), 'issac', 'text'),
        'fieldnotes_about_role' => array(__('身份说明', 'crolord'), __('独立开发者', 'crolord'), 'text'),
        'fieldnotes_about_location' => array(__('所在城市', 'crolord'), __('成都，中国', 'crolord'), 'text'),
        'fieldnotes_about_heading' => array(__('开场标题（留空使用公开名称）', 'crolord'), '', 'textarea'),
        'fieldnotes_about_intro' => array(__('开场自述', 'crolord'), '', 'textarea'),
        'fieldnotes_about_contact' => array(__('联系说明', 'crolord'), __('有合适的项目，或只是想聊聊，欢迎来信。', 'crolord'), 'text'),
        'fieldnotes_about_note' => array(__('一句自述（留空隐藏）', 'crolord'), __('遑遑三十载，书剑两无成。', 'crolord'), 'text'),
        'fieldnotes_about_github' => array(__('GitHub 链接（留空隐藏）', 'crolord'), 'https://github.com/Roliyal', 'url'),
        'fieldnotes_about_weibo' => array(__('微博链接（留空隐藏）', 'crolord'), 'https://weibo.com/u/3556213535', 'url'),
        'fieldnotes_about_x' => array(__('X 链接（留空隐藏）', 'crolord'), 'https://x.com/Roliyal_', 'url'),
        'fieldnotes_about_email' => array(__('联系邮箱（留空隐藏）', 'crolord'), 'issac@roliyal.com', 'email'),
    );
    foreach ($fields as $id => $field) {
        $callbacks = array('textarea' => 'sanitize_textarea_field', 'url' => 'esc_url_raw', 'email' => 'sanitize_email');
        $customizer->add_setting($id, array('default' => $field[1], 'sanitize_callback' => $callbacks[$field[2]] ?? 'sanitize_text_field'));
        $customizer->add_control($id, array('label' => $field[0], 'section' => 'fieldnotes_profile_page', 'type' => $field[2]));
    }
}
add_action('customize_register', 'fieldnotes_profile_customize', 21);

/** A small, current feed from the native Cities category. */
function crolord_about_updates() {
    $term=get_term_by('slug','cities','category');
    if (!$term || is_wp_error($term)) return array();
    $posts=get_posts(array('post_type'=>'post','post_status'=>'publish','numberposts'=>3,'category'=>(int)$term->term_id,'orderby'=>array('date'=>'DESC','ID'=>'DESC'),'ignore_sticky_posts'=>true,'suppress_filters'=>false));
    $items=array();
    foreach ($posts as $post) {
        $attachment=get_post_thumbnail_id($post->ID);
        $items[]=array('title'=>get_the_title($post->ID),'url'=>get_permalink($post->ID),'date'=>get_the_date('Y.m.d',$post),'iso'=>get_the_date('c',$post),'attachment'=>$attachment,'image'=>$attachment?'':fieldnotes_demo_image($post->ID),'srcset'=>$attachment?'':fieldnotes_demo_image_srcset($post->ID));
    }
    return $items;
}
function crolord_about_social_links() {
    $links=array();
    foreach (array('x'=>array('X','https://x.com/Roliyal_'),'weibo'=>array('微博','https://weibo.com/u/3556213535')) as $key=>$item) {
        $url=esc_url_raw(get_theme_mod('fieldnotes_about_'.$key,$item[1]),array('https'));
        if ($url) $links[]=array('key'=>$key,'label'=>$item[0],'url'=>$url,'external'=>true);
    }
    $email=sanitize_email(get_theme_mod('fieldnotes_about_email','issac@roliyal.com'));
    if ($email) $links[]=array('key'=>'email','label'=>'邮件','url'=>'mailto:'.$email,'external'=>false);
    return $links;
}
function crolord_about_social_icon($key) {
    $paths=array(
        'x'=>'<path d="m5 4 14 16M19 4 5 20M4 4h5l11 16h-5L4 4Z"/>',
        'weibo'=>'<path d="M18 10c3 0 4 2 3 4-1 4-7 6-12 5-4-1-6-4-4-7 1-3 5-6 6-5 1 0 0 2 0 2s5-3 6-1c1 1-1 3-1 3"/><ellipse cx="11" cy="14" rx="4" ry="3"/><path d="M18 5c2 0 4 2 4 4M17 2c4 0 7 3 7 7"/>',
        'email'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m4 7 8 6 8-6"/>',
    );
    return '<svg viewBox="0 0 26 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.25" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$key]??$paths['email']).'</svg>';
}
