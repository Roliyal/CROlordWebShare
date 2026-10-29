<?php
/** Quiet article actions. Local votes, no external sharing SDK or visitor IP storage. */
if (!defined('ABSPATH')) exit;

function crolord_likes_table() { global $wpdb; return $wpdb->prefix . 'crolord_likes'; }
function crolord_likes_ready() { return get_option('crolord_likes_schema') === '1'; }

/** A theme update reaches admin_init; front-page requests never install tables. */
function crolord_likes_install() {
    if (!current_user_can('manage_options') || crolord_likes_ready()) return;
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $table = crolord_likes_table();
    $collate = $wpdb->get_charset_collate();
    dbDelta("CREATE TABLE $table (
        post_id bigint(20) unsigned NOT NULL,
        visitor_hash char(64) NOT NULL,
        created_at datetime NOT NULL,
        PRIMARY KEY  (post_id,visitor_hash)
    ) $collate;");
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table) update_option('crolord_likes_schema', '1', false);
}
add_action('after_switch_theme', 'crolord_likes_install');
add_action('admin_init', 'crolord_likes_install', 25);

function crolord_article_actions_allowed($post) {
    return $post instanceof WP_Post && $post->post_type === 'post' && crolord_like_allowed($post);
}

function crolord_like_allowed($post) {
    return $post instanceof WP_Post && in_array($post->post_type, array('post', 'journal_fave'), true) && $post->post_status === 'publish' && $post->post_password === '' && is_post_publicly_viewable($post);
}

function crolord_like_count($post_id, $fresh = false) {
    if (!crolord_likes_ready()) return 0;
    $key = 'likes:' . get_current_blog_id() . ':' . absint($post_id);
    $found = false;
    $count = wp_cache_get($key, 'crolord', false, $found);
    if ($fresh || !$found) {
        global $wpdb;
        $table = crolord_likes_table();
        $count = max(0, (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE post_id = %d", $post_id)));
        wp_cache_set($key, $count, 'crolord', 60);
    }
    return (int) $count;
}

/** A signed, HttpOnly browser token; the database stores only a keyed hash. */
function crolord_like_reader($create = false) {
    if (is_user_logged_in()) return hash_hmac('sha256', 'user:' . get_current_blog_id() . ':' . get_current_user_id(), wp_salt('auth'));
    $name = 'crolord_reader_' . substr(hash('sha256', home_url('/')), 0, 12);
    $raw = isset($_COOKIE[$name]) && is_string($_COOKIE[$name]) ? wp_unslash($_COOKIE[$name]) : '';
    if (preg_match('/^([a-f0-9]{48})\.([a-f0-9]{64})$/D', $raw, $parts) && hash_equals(hash_hmac('sha256', $parts[1], wp_salt('secure_auth')), $parts[2])) {
        return hash_hmac('sha256', 'reader:' . get_current_blog_id() . ':' . $parts[1], wp_salt('auth'));
    }
    if (!$create || headers_sent()) return '';
    try { $token = bin2hex(random_bytes(24)); } catch (Throwable $error) { return ''; }
    $value = $token . '.' . hash_hmac('sha256', $token, wp_salt('secure_auth'));
    if (!setcookie($name, $value, array('expires'=>time() + YEAR_IN_SECONDS, 'path'=>defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/', 'domain'=>defined('COOKIE_DOMAIN') && COOKIE_DOMAIN ? COOKIE_DOMAIN : '', 'secure'=>is_ssl(), 'httponly'=>true, 'samesite'=>'Lax'))) return '';
    $_COOKIE[$name] = $value;
    return hash_hmac('sha256', 'reader:' . get_current_blog_id() . ':' . $token, wp_salt('auth'));
}

function crolord_like_has_voted($post_id, $reader) {
    if (!$reader || !crolord_likes_ready()) return false;
    global $wpdb;
    $table = crolord_likes_table();
    return (bool) $wpdb->get_var($wpdb->prepare("SELECT 1 FROM $table WHERE post_id = %d AND visitor_hash = %s LIMIT 1", $post_id, $reader));
}

/** A fresh nonce is fetched from an uncached endpoint, never from cached HTML. */
function crolord_like_request() {
    nocache_headers();
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, array('GET', 'POST'), true)) wp_send_json_error(array('message'=>__('请求方式不支持。', 'crolord')), 405);
    $input = $method === 'POST' ? $_POST : $_GET;
    $raw = $input['post_id'] ?? '';
    if (!is_string($raw) || !preg_match('/^[1-9][0-9]{0,18}$/D', $raw)) wp_send_json_error(array('message'=>__('文章编号无效。', 'crolord')), 400);
    $post_id = absint($raw);
    $post = get_post($post_id);
    if (!crolord_like_allowed($post)) wp_send_json_error(array('message'=>__('这项内容暂不支持点赞。', 'crolord')), 404);
    if (!crolord_likes_ready()) wp_send_json_error(array('message'=>__('点赞暂未就绪，请稍后再试。', 'crolord')), 503);
    $reader = crolord_like_reader($method === 'GET');
    if (!$reader) wp_send_json_error(array('message'=>__('暂时无法保存点赞状态，请刷新后重试。', 'crolord')), 400);
    if ($method === 'POST') {
        $nonce = $input['nonce'] ?? '';
        if (!is_string($nonce) || !wp_verify_nonce($nonce, 'crolord_like_' . $post_id)) wp_send_json_error(array('message'=>__('请刷新点赞状态后重试。', 'crolord')), 403);
        $wanted = $input['liked'] ?? '1';
        if (!is_string($wanted) || !in_array($wanted, array('0', '1'), true) || $wanted === '0' && $post->post_type !== 'journal_fave') wp_send_json_error(array('message'=>__('点赞状态无效。', 'crolord')), 400);
        global $wpdb;
        $table = crolord_likes_table();
        // The primary key makes simultaneous/repeated requests idempotent.
        $result = $wanted === '0' ? $wpdb->delete($table, array('post_id'=>$post_id, 'visitor_hash'=>$reader), array('%d', '%s')) : $wpdb->query($wpdb->prepare("INSERT IGNORE INTO $table (post_id, visitor_hash, created_at) VALUES (%d, %s, %s)", $post_id, $reader, current_time('mysql', true)));
        if ($result === false) wp_send_json_error(array('message'=>__('这次点赞未能保存，请稍后重试。', 'crolord')), 503);
    }
    $liked = crolord_like_has_voted($post_id, $reader);
    if ($method === 'POST' && $liked !== ($wanted === '1')) wp_send_json_error(array('message'=>__('这次点赞未能保存，请稍后重试。', 'crolord')), 503);
    wp_send_json_success(array('count'=>crolord_like_count($post_id, true), 'liked'=>$liked, 'nonce'=>wp_create_nonce('crolord_like_' . $post_id)));
}
add_action('wp_ajax_crolord_like', 'crolord_like_request');
add_action('wp_ajax_nopriv_crolord_like', 'crolord_like_request');

function crolord_likes_delete_post($post_id) {
    if (!crolord_likes_ready()) return;
    global $wpdb;
    $wpdb->delete(crolord_likes_table(), array('post_id'=>absint($post_id)), array('%d'));
    wp_cache_delete('likes:' . get_current_blog_id() . ':' . absint($post_id), 'crolord');
}
add_action('deleted_post', 'crolord_likes_delete_post');

function crolord_article_action_icon($name) {
    $paths = array(
        'like'=>'<path d="M16.8 4.2a4.5 4.5 0 0 0-6.3.1l-.5.5-.5-.5a4.5 4.5 0 0 0-6.4 6.3L10 17l6.9-6.4a4.5 4.5 0 0 0-.1-6.4Z"/>',
        'wechat'=>'<path d="M11.9 11.8c-1 .5-2.2.8-3.5.8-.8 0-1.6-.1-2.3-.3l-2.4 1 .7-2A4.8 4.8 0 0 1 2 7.5C2 4.7 4.9 2.6 8.4 2.6s6.3 2.1 6.3 4.9"/><path d="M17.9 14.9c.7-.7 1.1-1.6 1.1-2.5 0-2.3-2.3-4.1-5.1-4.1s-5.1 1.8-5.1 4.1 2.3 4.1 5.1 4.1c.7 0 1.3-.1 1.9-.3l2 .9-.5-1.7.6-.5Z"/><path d="M5.9 6.3h.1m4.5 0h.1m1.2 5.1h.1m4 0h.1" stroke-width="2"/>',
        'weibo'=>'<path d="M13.8 9.2c.8-2.7-1.5-3.1-4.1-1.9.9-2.9-.7-3.1-2.8-1.5C4.2 7.8 2.3 10 2.3 12.3c0 2.7 3.5 4.8 7.6 4.5 4.2-.3 7.2-2.7 6.8-5.1-.2-1.2-1.2-2-2.9-2.5Z"/><ellipse cx="9" cy="12.4" rx="3.4" ry="2.5" transform="rotate(-12 9 12.4)"/><circle cx="8.1" cy="12.2" r=".7" fill="currentColor" stroke="none"/><path d="M13.7 3.3a4.6 4.6 0 0 1 4.7 4.1M14 5.8a2 2 0 0 1 1.9 1.8"/>',
        'x'=>'<path d="M3 3h3.9L17 17h-3.9L3 3Z"/><path d="M16.8 3 3.2 17"/>',
        'qq'=>'<path d="M6.2 6.7c-.1-2.7 1.4-4.5 3.8-4.5s3.9 1.8 3.8 4.5l.9 2.1c1.7 2.3 2.4 4.9 1.5 5.3-.4.2-1-.2-1.7-.8.1 1.7-.6 2.8-1.7 3.3l1.4.7c.5.3.3.8-.5.8h-2.4L10 17.4l-1.3.7H6.3c-.8 0-1-.5-.5-.8l1.4-.7c-1.1-.5-1.8-1.6-1.7-3.3-.7.6-1.3 1-1.7.8-.9-.4-.2-3 1.5-5.3l.9-2.1Z"/><path d="M8.2 8.5c1.1.6 2.5.6 3.6 0M8.5 6.1v.5m3-.5v.5M6.1 10.6c2.4 1 5.4 1 7.8 0"/>',
        'copy'=>'<rect x="7" y="7" width="10" height="11" rx="2"/><path d="M12 7V4a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>',
        'close'=>'<path d="m5 5 10 10M15 5 5 15"/>',
    );
    if (!isset($paths[$name])) return '';
    return '<svg viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.35" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

function crolord_article_actions_assets() {
    if (!is_singular('post') || !crolord_article_actions_allowed(get_queried_object())) return;
    $base = get_template_directory_uri();
    wp_enqueue_style('fieldnotes-article-actions', $base . '/assets/css/article-actions.min.css', array('fieldnotes-fields'), FIELDNOTES_VERSION);
    wp_enqueue_script('fieldnotes-article-actions', $base . '/assets/js/article-actions.min.js', array(), FIELDNOTES_VERSION, true);
    wp_localize_script('fieldnotes-article-actions', 'crolordArticleConfig', array('ajaxUrl'=>admin_url('admin-ajax.php'), 'qrUrl'=>$base . '/assets/js/qrcode.min.js?ver=' . rawurlencode(FIELDNOTES_VERSION), 'like'=>__('喜欢', 'crolord'), 'liked'=>__('已喜欢', 'crolord'), 'thanks'=>__('谢谢你的喜欢。', 'crolord'), 'error'=>__('暂时未能保存，请稍后重试。', 'crolord'), 'qrError'=>__('二维码暂未生成，可以复制链接分享。', 'crolord'), 'copied'=>__('链接已复制。', 'crolord'), 'copyHint'=>__('请选择上方链接进行复制。', 'crolord')));
}
add_action('wp_enqueue_scripts', 'crolord_article_actions_assets', 110);

function crolord_article_actions($post_id) {
    $post = get_post($post_id);
    if (!crolord_article_actions_allowed($post)) return;
    $url = get_permalink($post);
    $title = fieldnotes_clean_text(get_the_title($post));
    $image = crolord_social_image();
    $links = array(
        'weibo'=>array(__('分享到微博', 'crolord'), add_query_arg(array_map('rawurlencode', array('url'=>$url, 'title'=>$title, 'pic'=>$image)), 'https://service.weibo.com/share/share.php')),
        'x'=>array(__('分享到 X', 'crolord'), add_query_arg(array_map('rawurlencode', array('url'=>$url, 'text'=>$title)), 'https://x.com/intent/tweet')),
        'qq'=>array(__('分享到 QQ', 'crolord'), add_query_arg(array_map('rawurlencode', array('url'=>$url, 'title'=>$title, 'summary'=>crolord_social_description(), 'pics'=>$image)), 'https://connect.qq.com/widget/shareqq/index.html')),
    );
    $dialog_id = 'crolord-wechat-' . absint($post_id);
    ?>
    <div class="crolord-article-actions" data-article-actions data-post-id="<?php echo absint($post_id); ?>" data-share-url="<?php echo esc_url($url); ?>">
        <div class="crolord-like-wrap">
            <button class="crolord-action crolord-like" type="button" data-like aria-pressed="false" aria-label="<?php esc_attr_e('喜欢这篇文章', 'crolord'); ?>"><?php echo crolord_article_action_icon('like'); ?></button>
            <span class="crolord-action-status screen-reader-text" data-like-status role="status"></span>
        </div>
        <div class="crolord-share-actions" role="group" aria-label="<?php esc_attr_e('分享文章', 'crolord'); ?>">
            <button class="crolord-action" type="button" data-wechat aria-label="<?php esc_attr_e('分享到微信', 'crolord'); ?>" title="<?php esc_attr_e('分享到微信', 'crolord'); ?>" aria-haspopup="dialog" aria-controls="<?php echo esc_attr($dialog_id); ?>" aria-expanded="false"><?php echo crolord_article_action_icon('wechat'); ?></button>
            <?php foreach ($links as $network=>$link) : ?><a class="crolord-action" data-share-network="<?php echo esc_attr($network); ?>" href="<?php echo esc_url($link[1]); ?>" target="_blank" rel="noopener noreferrer" aria-label="<?php echo esc_attr($link[0]); ?>" title="<?php echo esc_attr($link[0]); ?>"><?php echo crolord_article_action_icon($network); ?></a><?php endforeach; ?>
        </div>
        <dialog class="crolord-share-dialog" id="<?php echo esc_attr($dialog_id); ?>" aria-labelledby="<?php echo esc_attr($dialog_id); ?>-title" aria-describedby="<?php echo esc_attr($dialog_id); ?>-help">
            <button type="button" class="crolord-action crolord-dialog-close" data-share-close aria-label="<?php esc_attr_e('关闭分享', 'crolord'); ?>"><?php echo crolord_article_action_icon('close'); ?></button>
            <h2 tabindex="-1" id="<?php echo esc_attr($dialog_id); ?>-title"><?php esc_html_e('分享到微信', 'crolord'); ?></h2>
            <p class="crolord-share-help" id="<?php echo esc_attr($dialog_id); ?>-help"><?php esc_html_e('微信扫一扫，打开后分享给朋友或朋友圈。', 'crolord'); ?></p>
            <div class="crolord-share-qr" data-share-qr aria-busy="false"></div>
            <p class="crolord-share-title"><?php echo esc_html($title); ?></p>
            <input class="crolord-share-url" type="text" readonly value="<?php echo esc_url($url); ?>" aria-label="<?php esc_attr_e('文章链接', 'crolord'); ?>">
            <button class="crolord-copy-link" type="button" data-copy-link><?php echo crolord_article_action_icon('copy'); ?><span><?php esc_html_e('复制链接', 'crolord'); ?></span></button>
            <p class="crolord-share-status" data-share-status role="status"></p>
        </dialog>
        <noscript><p class="crolord-actions-noscript"><?php esc_html_e('启用 JavaScript 后可点赞或生成微信二维码；也可以直接复制本页网址。', 'crolord'); ?></p></noscript>
    </div>
    <?php
}
