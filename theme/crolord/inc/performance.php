<?php
/** Request-local work only: no shared HTML, user sessions or stale page URLs. */
if (!defined('ABSPATH')) exit;
function crolord_request_cache_reset() { $GLOBALS['crolord_request_cache'] = array(); }
foreach (array('clean_post_cache', 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'switch_blog') as $hook) add_action($hook, 'crolord_request_cache_reset');
function crolord_request_value($key, $callback) {
    $key = get_current_blog_id() . ':' . get_locale() . ':' . $key;
    if (!isset($GLOBALS['crolord_request_cache'])) crolord_request_cache_reset();
    if (!array_key_exists($key, $GLOBALS['crolord_request_cache'])) $GLOBALS['crolord_request_cache'][$key] = call_user_func($callback);
    return $GLOBALS['crolord_request_cache'][$key];
}
/** WordPress resolves deferred dependencies; older supported core keeps footer scripts. */
function crolord_defer_scripts() {
    foreach (wp_scripts()->registered as $handle => $script) {
        if (strpos($handle, 'fieldnotes-') === 0 || strpos($handle, 'fntm-') === 0) wp_script_add_data($handle, 'strategy', 'defer');
    }
}
add_action('wp_enqueue_scripts', 'crolord_defer_scripts', 120);

/** Cache versions follow each first-party file, never an unrelated release. */
function crolord_asset_file_version($src, $handle) {
    if (!is_string($src) || !preg_match('/^(?:fieldnotes-|fntm-|crolord-keepsakes-)/', $handle)) return $src;
    $bases = array(get_template_directory_uri() => get_template_directory(), plugins_url() => WP_PLUGIN_DIR);
    foreach ($bases as $url => $directory) {
        $prefix = trailingslashit($url);
        if (strpos($src, $prefix) !== 0) continue;
        $relative = wp_parse_url(substr($src, strlen($prefix)), PHP_URL_PATH);
        if (!is_string($relative) || strpos($relative, '..') !== false || !preg_match('/\.(?:css|js)$/i', $relative)) return $src;
        $file = trailingslashit($directory) . $relative;
        $version = crolord_request_value('asset:' . $file, function () use ($file) { return is_file($file) ? filemtime($file) : false; });
        return $version ? add_query_arg('ver', (string) $version, $src) : $src;
    }
    return $src;
}
add_filter('style_loader_src', 'crolord_asset_file_version', 20, 2);
add_filter('script_loader_src', 'crolord_asset_file_version', 20, 2);

/** Only six explicit, same-site destinations. Echoes and arbitrary links stay native. */
function crolord_navigation_manifest() {
    $urls = array(home_url('/'));
    $labels = array(__('首页', 'crolord'));
    foreach (array('about', 'archive', 'map', 'categories', 'faves') as $section) {
        $urls[] = fieldnotes_section_url($section);
        $labels[] = fieldnotes_section_label($section);
    }
    if (crolord_profile_enabled()) {
        $args = array('crolord_profile' => '1', '_wpnonce' => wp_create_nonce('crolord_profile'));
        foreach ($urls as &$url) $url = add_query_arg($args, $url);
        unset($url);
    }
    $relative_styles = array('core', 'home', 'motion', 'collections', 'catalog', 'market', 'keepsakes');
    $styles = array();
    foreach ($relative_styles as $name) {
        $relative = '/assets/css/' . $name . '.min.css';
        if (is_file(get_template_directory() . $relative)) $styles[] = crolord_asset_file_version(get_template_directory_uri() . $relative, 'fieldnotes-style');
    }
    foreach (array('crolord-map/assets/map.min.css', 'crolord-comment-tools/assets/comment-tools.min.css', 'crolord-keepsakes/assets/flow.min.css') as $relative) {
        if (is_file(WP_PLUGIN_DIR . '/' . $relative)) $styles[] = crolord_asset_file_version(plugins_url($relative), 'fieldnotes-style');
    }
    return array('urls' => $urls, 'labels' => $labels, 'styles' => $styles, 'coreStyle' => crolord_asset_file_version(get_template_directory_uri() . '/assets/css/core.min.css', 'fieldnotes-style'));
}
add_action('wp_enqueue_scripts', function () {
    if (is_customize_preview() || !wp_script_is('fieldnotes-main', 'enqueued')) return;
    wp_localize_script('fieldnotes-main', 'fieldnotesNavigation', crolord_navigation_manifest());
}, 125);

/** Keep Core's defaults; prepare only explicit section-navigation destinations earlier. */
add_action('wp_load_speculation_rules', function ($rules) {
    $urls = array(home_url('/'));
    foreach (array('about', 'archive', 'map', 'categories', 'faves') as $section) $urls[] = fieldnotes_section_url($section);
    $rules->add_rule('prefetch', 'crolord-navigation', array(
        'source' => 'document',
        'where' => array('and' => array(
            array('selector_matches' => '.primary-nav a, .crolord-mobile-nav a, .crolord-site-identity a'),
            array('href_matches' => array_values(array_unique($urls))),
            array('not' => array('selector_matches' => 'a[rel~="nofollow"], a[download], a[target="_blank"], .no-prefetch, .no-prefetch a')),
        )),
        'eagerness' => 'moderate',
    ));
});
add_filter('wp_speculation_rules_href_exclude_paths', function ($paths) {
    $path = wp_parse_url(fieldnotes_section_url('chatgpt'), PHP_URL_PATH);
    if (is_string($path) && $path !== '/') $paths[] = rtrim($path, '/') . '/*';
    return $paths;
});
/** Keep article originals intact; only card fallback imagery uses small derivatives. */
function fieldnotes_card_demo_image($post_id) {
    $url = fieldnotes_demo_image($post_id);
    return preg_replace('~/(kyoto-rain|coast-dawn|studio-notes|paris-rain)\.webp$~', '/$1-card.webp', $url);
}


/** Responsive fallback photography only; native fonts and layout are untouched. */
function fieldnotes_demo_image_srcset($post_id) {
    $url = fieldnotes_demo_image($post_id);
    if (!preg_match('~/assets/photos/(kyoto-rain|coast-dawn|studio-notes|paris-rain)\.webp$~', $url, $match)) return '';
    $base = substr($url, 0, -5);
    $widths = array('kyoto-rain' => 1536, 'coast-dawn' => 1536, 'studio-notes' => 1536, 'paris-rain' => 1536);
    return $base . '-card.webp 520w, ' . $base . '-768.webp 768w, ' . $base . '-1024.webp 1024w, ' . $url . ' ' . $widths[$match[1]] . 'w';
}

/** Small shared styles stay in dependency order without another blocking request. */
function crolord_inline_asset_style($handle,$relative,$dependencies) {
    wp_register_style($handle,false,$dependencies,FIELDNOTES_VERSION);
    wp_enqueue_style($handle);
    $css=crolord_request_value('css:'.$relative,function()use($relative){return file_get_contents(get_template_directory().'/assets/css/'.$relative.'.min.css');});
    if(is_string($css))wp_add_inline_style($handle,$css);
}
/** Match the hero's srcset and sizes so preloading does not fetch a second image. */
function crolord_home_hero_image() {
    $base=get_template_directory_uri().'/assets/photos/kyoto-rain';
    return array('src'=>$base.'.webp','srcset'=>$base.'-480.webp 480w, '.$base.'-768.webp 768w, '.$base.'-1024.webp 1024w, '.$base.'.webp 1536w','sizes'=>'(max-width: 780px) calc(100vw - 48px), (max-width: 1200px) 50vw, 600px');
}
function crolord_preload_home_hero() {
    if(!is_front_page())return;
    $image=crolord_home_hero_image();
    printf('<link rel="preload" as="image" href="%s" imagesrcset="%s" imagesizes="%s" fetchpriority="high">'."\n",esc_url($image['src']),esc_attr($image['srcset']),esc_attr($image['sizes']));
}
add_action('wp_head','crolord_preload_home_hero',1);

function crolord_plain_conversation_page() {
    if(!fieldnotes_is_echoes_page())return false;
    $post=get_queried_object();if(!$post instanceof WP_Post)return false;
    $content=preg_replace('~<!--.*?-->~s','',(string)$post->post_content);
    $content=preg_replace('~</?p\s*>|<br\s*/?>~i','',$content);
    return (bool)preg_match('/^(?:\s*\[fieldnotes_chatgpt\]\s*)+$/D',$content);
}


/** Cache public page IDs only; URLs and user-specific markup are always resolved afresh. */
function crolord_section_page_ids() {
    return crolord_request_value(__FUNCTION__, function () {
        $context = get_stylesheet() . ':' . get_locale();
        $saved = get_option('crolord_section_page_ids', array());
        if (!is_array($saved) || ($saved['context'] ?? '') !== $context || (int) ($saved['until'] ?? 0) < time()) {
            $templates = array();
            foreach (array_keys(fieldnotes_section_labels()) as $section) $templates[] = 'templates/page-' . $section . '.php';
            $query = new WP_Query(array('post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => -1, 'no_found_rows' => true, 'orderby' => array('menu_order' => 'ASC', 'ID' => 'ASC'), 'update_post_term_cache' => false, 'meta_query' => array(array('key' => '_wp_page_template', 'value' => $templates, 'compare' => 'IN'))));
            $ids = array();
            foreach ($query->posts as $page) {
                $template = get_post_meta($page->ID, '_wp_page_template', true);
                foreach (array_keys(fieldnotes_section_labels()) as $section) if ($template === 'templates/page-' . $section . '.php' && !isset($ids[$section])) $ids[$section] = (int) $page->ID;
            }
            $saved = array('context' => $context, 'until' => time() + 300, 'ids' => $ids);
            update_option('crolord_section_page_ids', $saved, true);
        }
        $ids = isset($saved['ids']) && is_array($saved['ids']) ? array_map('absint', $saved['ids']) : array();
        if ($ids && function_exists('_prime_post_caches')) _prime_post_caches(array_values($ids), false, true);
        return $ids;
    });
}
function crolord_clear_section_page_ids($post_id = 0, $post = null) {
    if (($post instanceof WP_Post ? $post->post_type : get_post_type($post_id)) !== 'page') return;
    delete_option('crolord_section_page_ids');
    crolord_request_cache_reset();
}
add_action('clean_post_cache', 'crolord_clear_section_page_ids', 20, 2);
add_action('before_delete_post', 'crolord_clear_section_page_ids', 20, 2);
function crolord_clear_section_template_ids($meta_id, $post_id, $key) {
    if ($key === '_wp_page_template') crolord_clear_section_page_ids($post_id);
}
foreach (array('added_post_meta', 'updated_post_meta', 'deleted_post_meta') as $hook) add_action($hook, 'crolord_clear_section_template_ids', 20, 3);

/** The retired first-party licensing service is no longer part of this site. */
add_action('admin_init', function () {
    if (!current_user_can('update_plugins') || get_option('crolord_license_retired_246')) return;
    wp_clear_scheduled_hook('crolord_license_refresh_v1');
    update_option('crolord_license_retired_246', 1, false);
}, 90);

/** Only native app templates with no author-supplied content skip block styles. */
function crolord_plain_app_page() {
    if (crolord_plain_conversation_page() || (function_exists('fieldnotes_market_board_request') && fieldnotes_market_board_request())) return true;
    if (!is_singular('page') || !is_page_template(array('templates/page-about.php', 'templates/page-categories.php', 'templates/page-faves.php', 'templates/page-map.php'))) return false;
    $post = get_queried_object();
    if (!$post instanceof WP_Post) return false;
    if (has_blocks($post->post_content)) return false;
    $content = preg_replace('~<!--.*?-->~s', '', (string) $post->post_content);
    $content = preg_replace('~</?p\s*>|<br\s*/?>~i', '', $content);
    return trim($content) === '';
}

/** Custom, block-free app templates need no Gutenberg frontend styles. */
add_action('wp_enqueue_scripts', function () {
    if (crolord_plain_app_page()) {
        wp_dequeue_style('wp-block-library'); wp_dequeue_style('wp-block-library-theme'); wp_dequeue_style('global-styles'); wp_dequeue_style('classic-theme-styles');
        // WP 6.9+ regenerates global styles in the footer. Skip that work only
        // on templates which do not render blocks or use their preset variables.
        wp_dequeue_style('wp-global-styles-placeholder');
        remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
    }
}, 200);
/** The native homepage uses theme typography, not editor presets. Fail open for authored designs. */
function crolord_home_plain_blocks($blocks) {
    $allowed = array(
        'core/group' => array(),
        'core/heading' => array('level'),
        'core/search' => array('label', 'showLabel', 'placeholder', 'buttonText', 'buttonUseIcon', 'buttonPosition'),
        'core/latest-posts' => array('postsToShow', 'displayPostDate', 'displayAuthor', 'displayPostContent', 'excerptLength', 'order', 'orderBy', 'categories', 'selectedAuthor'),
        'core/latest-comments' => array('commentsToShow', 'displayAvatar', 'displayDate', 'displayExcerpt'),
    );
    foreach ($blocks as $block) {
        $name = $block['blockName'] ?? null;
        if ($name === null && trim($block['innerHTML'] ?? '') === '') continue;
        if (!isset($allowed[$name])) return false;
        foreach (array_keys($block['attrs'] ?? array()) as $attribute) if (!in_array($attribute, $allowed[$name], true)) return false;
        // Inline styling or preset classes in manually edited HTML also need Core's CSS.
        if (preg_match('/\bstyle\s*=|\bhas-[\w-]+|--wp--|\bis-layout-(?:grid|flex)/i', $block['innerHTML'] ?? '')) return false;
        if (!crolord_home_plain_blocks($block['innerBlocks'] ?? array())) return false;
    }
    return true;
}
function crolord_home_without_presets() {
    if (!is_front_page() || is_customize_preview() || get_stylesheet() !== get_template()) return false;
    if (defined('CROLORD_FULL_STYLES') && CROLORD_FULL_STYLES) return false;
    if (is_file(get_template_directory() . '/theme.json') || trim(wp_get_custom_css()) !== '') return false;
    if (!class_exists('WP_Theme_JSON_Resolver')) return false;
    $custom = WP_Theme_JSON_Resolver::get_user_data()->get_raw_data();
    if (!empty($custom['styles']) || !empty($custom['settings'])) return false;
    $sidebars = wp_get_sidebars_widgets();
    $widgets = get_option('widget_block', array());
    foreach ($sidebars['journal-sidebar'] ?? array() as $widget) {
        if (!preg_match('/^block-([0-9]+)$/D', $widget, $match)) return false;
        if (!isset($widgets[(int) $match[1]]['content'])) return false;
        if (!crolord_home_plain_blocks(parse_blocks($widgets[(int) $match[1]]['content']))) return false;
    }
    return (bool) apply_filters('crolord_home_without_presets', true);
}
/** Keep each rendered block's own stylesheet; omit only unused global preset utilities. */
add_action('wp_enqueue_scripts', function () {
    if (!crolord_home_without_presets()) return;
    wp_dequeue_style('global-styles');
    wp_dequeue_style('wp-global-styles-placeholder');
    remove_action('wp_footer', 'wp_enqueue_global_styles', 1);
}, 205);

remove_action('wp_head', 'print_emoji_detection_script', 7);
remove_action('wp_print_styles', 'print_emoji_styles');
remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');

function crolord_admin_workspace_screen() {
    $screen = get_current_screen();
    return $screen && in_array($screen->id, array('settings_page_fieldnotes-chatgpt','settings_page_crolord-keepsakes','settings_page_crolord-publisher','settings_page_roliyal-mcp','tools_page_crolord-publisher-jobs','fntm_place_page_fntm-settings','appearance_page_fieldnotes-boughs'), true);
}
add_filter('admin_body_class', function ($classes) { return crolord_admin_workspace_screen() ? $classes . ' crolord-admin-screen' : $classes; });
add_action('admin_enqueue_scripts', function () {
    if (!crolord_admin_workspace_screen()) return;
    wp_register_style('crolord-admin-workspace', false, array('common'), FIELDNOTES_VERSION); wp_enqueue_style('crolord-admin-workspace');
    wp_add_inline_style('crolord-admin-workspace', <<<'CSS'
.crolord-admin-screen #wpcontent{background:#f7f7f4}.crolord-admin-screen .wrap{max-width:1060px;margin-top:32px;color:#363c38;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif}.crolord-admin-screen .wrap h1{font-size:30px;font-weight:450;letter-spacing:-.04em;margin-bottom:24px}.crolord-admin-screen .wrap h2{font-size:19px;line-height:1.5;font-weight:500;letter-spacing:-.025em}.crolord-admin-screen .wrap p,.crolord-admin-screen .wrap li{font-size:13px;line-height:1.85;color:#545b55}.crolord-admin-screen .wrap .description{font-size:12px;color:#697068}.crolord-admin-screen .wrap :is(section,.rlm-card,.cp-job){border-color:#e0e3dc;border-radius:14px;box-shadow:none}.crolord-admin-screen .wrap :is(input:not([type=checkbox]):not([type=radio]),textarea,select){border-color:#d8ddd5;border-radius:7px;box-shadow:none;max-width:100%;font-size:13px;line-height:1.7}.crolord-admin-screen .wrap :is(input,textarea,select):focus{border-color:#737e71;box-shadow:0 0 0 1px #737e7140}.crolord-admin-screen .wrap .button{border-color:#b7beb2;border-radius:8px;color:#414c40;background:#fff;min-height:34px;line-height:32px;padding:0 13px;box-shadow:none}.crolord-admin-screen .wrap .button-primary{background:#4c594b;border-color:#4c594b;color:#fff}.crolord-admin-screen .wrap .button:disabled{opacity:.55}.crolord-admin-screen .wrap a{color:#4c654d}.crolord-admin-screen .wrap .cp-error{color:#8d443b}.crolord-admin-screen .wrap .cp-state,.crolord-admin-screen .wrap .rlm-pill{background:#edf0e9;color:#54634e}.crolord-admin-screen .rlm-header .rlm-eyebrow{display:none}.crolord-admin-screen .cp-settings>section,.crolord-admin-screen .echoes-settings>section{padding:24px;margin:16px 0;background:#fff;border:1px solid #e0e3dc}.crolord-admin-screen .cp-job{padding:22px;background:#fff;margin:14px 0}.crolord-admin-screen .wrap .cp-job .button{margin-top:8px}.crolord-admin-screen .wrap .form-table th{font-weight:500}.crolord-admin-screen .wrap code{font-size:12px}@media(max-width:680px){.crolord-admin-screen .wrap{margin-top:20px}.crolord-admin-screen .wrap h1{font-size:26px}.crolord-admin-screen .cp-settings>section,.crolord-admin-screen .echoes-settings>section{padding:18px}.crolord-admin-screen .wrap .button{margin-bottom:5px}}
CSS
    );
});

/** Defer layout and paint for long lists outside the viewport. Native keyboard focus remains available. */
add_action('wp_enqueue_scripts', function () {
    wp_add_inline_style('fieldnotes-style', '@supports(content-visibility:auto){.post-list>.post-card{content-visibility:auto;contain-intrinsic-size:auto 212px}.faves-grid>.fave-card{content-visibility:auto;contain-intrinsic-size:auto 380px}@media(max-width:680px){.post-list>.post-card{contain-intrinsic-size:auto 170px}}}');
}, 210);

/** Owner-invoked, read-only navigation measurements. No code runs for ordinary visitors. */
function crolord_profile_enabled() {
    return isset($_GET['crolord_profile']) && $_GET['crolord_profile'] === '1'
        && current_user_can('manage_options')
        && isset($_GET['_wpnonce']) && is_string($_GET['_wpnonce'])
        && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'crolord_profile');
}
add_action('template_redirect', function () { if (crolord_profile_enabled()) nocache_headers(); });
add_action('wp_footer', function () {
    if (!crolord_profile_enabled()) return;
    echo <<<'HTML'
<aside hidden id="crolord-profile" aria-label="页面性能"><details open><summary>页面性能 · 本浏览器</summary><p>登录状态下的本次导航，单位毫秒。</p><nav aria-label="栏目性能测量"></nav><pre aria-live="polite">正在测量…</pre><details><summary>耗时较长的资源</summary><ul></ul></details></details><button type="button" aria-label="关闭性能面板">关闭</button></aside>
<style>#crolord-profile{position:fixed;right:18px;bottom:18px;z-index:99999;width:min(420px,calc(100vw - 36px));max-height:55vh;overflow:auto;padding:16px 18px;background:#fffffff5;color:#40483f;border:1px solid #dfe4dc;border-radius:14px;box-shadow:0 8px 30px #26332312;font:13px/1.7 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}#crolord-profile summary{cursor:pointer}#crolord-profile pre{white-space:pre-wrap;font:12px/1.8 ui-monospace,monospace;margin:10px 0}#crolord-profile p,#crolord-profile li{font-size:12px;margin:5px 0;overflow-wrap:anywhere}#crolord-profile ul{padding-left:18px}#crolord-profile button{border:0;background:transparent;color:inherit;font:inherit;padding:4px 0;cursor:pointer}</style>
<script>(function(){'use strict';var panel=document.getElementById('crolord-profile'),perf=window.performance;if(!panel||!perf||!perf.getEntriesByType)return;var lcp=null,tasks=[],observers=[],stopped=false;function observe(type,callback){try{if(!window.PerformanceObserver||!PerformanceObserver.supportedEntryTypes.includes(type))return false;var observer=new PerformanceObserver(callback);observer.observe({type:type,buffered:true});observers.push(observer);return true;}catch(e){return false;}}var longSupported=observe('longtask',function(list){tasks=tasks.concat(list.getEntries());});observe('largest-contentful-paint',function(list){var entries=list.getEntries();if(entries.length)lcp=entries[entries.length-1].startTime;});function ms(value){return typeof value==='number'&&isFinite(value)&&value>=0?Math.round(value)+' ms':'尚未取得';}var profileNav=panel.querySelector('nav'),navigation=window.fieldnotesNavigation;if(profileNav&&navigation)navigation.urls.forEach(function(url,i){var link=document.createElement('a');link.href=url;link.textContent=navigation.labels[i];link.style.cssText='display:inline-block;margin-right:12px;font-size:12px';profileNav.appendChild(link);});function report(){if(stopped)return;var nav=perf.getEntriesByType('navigation')[0];if(!nav)return;var paints=perf.getEntriesByType('paint'),fcp=paints.find(function(e){return e.name==='first-contentful-paint';}),resources=perf.getEntriesByType('resource');var blocking=tasks.reduce(function(n,t){return n+Math.max(0,t.duration-50);},0);panel.querySelector('pre').textContent=['导航来源             '+(nav.deliveryType||'network'),'正文传输             '+nav.transferSize+' B','首字节（请求到响应）  '+ms(nav.responseStart-nav.requestStart),'正文下载             '+ms(nav.responseEnd-nav.responseStart),'首次内容绘制         '+ms(fcp&&fcp.startTime),'最大内容绘制         '+ms(lcp),'DOM 就绪             '+ms(nav.domContentLoadedEventEnd),'页面 load            '+ms(nav.loadEventEnd),'长任务 / 阻塞时间    '+(longSupported?tasks.length+' / '+Math.round(blocking)+' ms':'此浏览器未提供'),'已完成资源           '+resources.length+' 项'].join('\n');var list=panel.querySelector('ul');list.replaceChildren();resources.filter(function(r){return r.duration>150;}).sort(function(a,b){return b.duration-a.duration;}).slice(0,7).forEach(function(r){var item=document.createElement('li'),url=new URL(r.name,location.href);item.textContent=Math.round(r.duration)+' ms · '+url.host+url.pathname+(r.transferSize?' · '+Math.round(r.transferSize/1024)+' KB':'');list.appendChild(item);});if(!list.childNodes.length){var item=document.createElement('li');item.textContent='已完成资源中暂无超过 150 ms 的项目。';list.appendChild(item);}}var timers=[setTimeout(report,1000),setTimeout(report,4000),setTimeout(function(){report();observers.forEach(function(o){o.disconnect();});panel.hidden=false;},9000)];function loaded(){setTimeout(report,100);}window.addEventListener('load',loaded,{once:true});panel.querySelector('button').addEventListener('click',function(){stopped=true;timers.forEach(clearTimeout);observers.forEach(function(o){o.disconnect();});window.removeEventListener('load',loaded);panel.remove();});}());</script>
HTML;
}, 100);
