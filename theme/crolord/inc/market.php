<?php
/** Historical market data and author-owned observation notes. No simulated prices. */
if (!defined('ABSPATH')) exit;

function fieldnotes_market_symbol($input) {
    if (!is_string($input)) return new WP_Error('invalid_symbol', '请输入沪深北股票代码，例如 600519。');
    $value = strtoupper(trim($input));
    if (preg_match('/^(SH|SZ|BJ)[:.]?(\d{6})$/D', $value, $m)) { $exchange = $m[1]; $code = $m[2]; }
    elseif (preg_match('/^(\d{6})\.(SH|SZ|BJ)$/D', $value, $m)) { $exchange = $m[2]; $code = $m[1]; }
    elseif (preg_match('/^\d{6}$/D', $value)) {
        $code = $value;
        $exchange = preg_match('/^(4|8|92)/', $code) ? 'BJ' : (preg_match('/^[69]/', $code) ? 'SH' : 'SZ');
    } else return new WP_Error('invalid_symbol', '请输入六位股票代码，可加 SH、SZ、BJ 前缀。');
    if ($exchange === 'SH' && $code !== '000001' && !preg_match('/^[69]/', $code) || $exchange === 'SZ' && !preg_match('/^[023]/', $code) || $exchange === 'BJ' && !preg_match('/^(4|8|92)/', $code)) return new WP_Error('invalid_symbol', '代码与交易市场不匹配，请核对后重试。');
    return array('code' => $code, 'exchange' => $exchange, 'canonical' => $exchange . $code, 'tencent' => strtolower($exchange) . $code, 'secid' => ($exchange === 'SH' ? '1.' : '0.') . $code);
}

function fieldnotes_market_parse_rows($rows) {
    if (!is_array($rows) || !$rows || count($rows) > 1000) return new WP_Error('invalid_data', '行情数据暂不可读。');
    $bars = array();
    foreach ($rows as $row) {
        if (is_string($row)) $row = explode(',', $row);
        if (!is_array($row) || count($row) < 6 || !is_string($row[0]) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $row[0], $date) || !checkdate((int) $date[2], (int) $date[3], (int) $date[1])) return new WP_Error('invalid_data', '行情日期或字段格式无效。');
        for ($i = 1; $i <= 5; $i++) if (!is_numeric($row[$i]) || !is_finite((float) $row[$i])) return new WP_Error('invalid_data', '行情价格或成交量无效。');
        $bar = array('date' => $row[0], 'open' => (float) $row[1], 'close' => (float) $row[2], 'high' => (float) $row[3], 'low' => (float) $row[4], 'volume' => (float) $row[5]);
        if ($bar['low'] <= 0 || $bar['volume'] < 0 || $bar['high'] < max($bar['open'], $bar['close']) || $bar['low'] > min($bar['open'], $bar['close'])) return new WP_Error('invalid_data', '行情价格范围无效。');
        $bars[$bar['date']] = $bar;
    }
    ksort($bars, SORT_STRING);
    return array_values(array_slice($bars, -320, null, true));
}

function fieldnotes_market_provider($symbol, $period, $adjust, $provider) {
    $periods = array('day' => 101, 'week' => 102, 'month' => 103);
    if (!isset($periods[$period]) || !in_array($adjust, array('qfq', 'none'), true)) return new WP_Error('invalid_period', 'K 线周期或复权方式无效。');
    if ($provider === 'tencent') {
        $url = add_query_arg('param', $symbol['tencent'] . ',' . $period . ',,,120,' . ($adjust === 'qfq' ? 'qfq' : ''), 'https://web.ifzq.gtimg.cn/appstock/app/fqkline/get');
    } else {
        $url = add_query_arg(array('secid' => $symbol['secid'], 'klt' => $periods[$period], 'fqt' => $adjust === 'qfq' ? 1 : 0, 'beg' => '19900101', 'end' => '20500101', 'lmt' => 320, 'fields1' => 'f1,f2,f3,f4,f5,f6', 'fields2' => 'f51,f52,f53,f54,f55,f56'), 'https://push2his.eastmoney.com/api/qt/stock/kline/get');
    }
    $response = wp_remote_get($url, array('timeout' => 12, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048576, 'headers' => array('Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0')));
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('market_unavailable', '暂时无法取得行情，请稍后重试。');
    $json = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($json)) return new WP_Error('invalid_data', '行情服务没有返回有效数据。');
    if ($provider === 'tencent') {
        if (($json['code'] ?? -1) !== 0 || !is_array($json['data'][$symbol['tencent']] ?? null)) return new WP_Error('symbol_unavailable', '没有取得这只股票的历史行情，请核对代码。');
        $item = $json['data'][$symbol['tencent']];
        $rows = $item[($adjust === 'qfq' ? 'qfq' : '') . $period] ?? null;
        // When no adjustment exists, the provider uses the unadjusted key.
        if (!$rows && $adjust === 'qfq') $rows = $item[$period] ?? null;
        $quote = $item['qt'][$symbol['tencent']] ?? array();
        if (isset($quote[2]) && (string) $quote[2] !== $symbol['code']) return new WP_Error('symbol_mismatch', '行情返回的股票代码不匹配。');
        $name = isset($quote[1]) && is_string($quote[1]) ? sanitize_text_field($quote[1]) : $symbol['canonical'];
    } else {
        $item = $json['data'] ?? null;
        if (!is_array($item) || (string) ($item['code'] ?? '') !== $symbol['code']) return new WP_Error('symbol_unavailable', '没有取得这只股票的历史行情，请核对代码。');
        $name = is_string($item['name'] ?? null) ? sanitize_text_field($item['name']) : $symbol['canonical'];
        $rows = $item['klines'] ?? null;
    }
    $bars = fieldnotes_market_parse_rows($rows);
    if (is_wp_error($bars)) return $bars;
    return array('symbol' => $symbol['canonical'], 'code' => $symbol['code'], 'name' => $name, 'exchange' => $symbol['exchange'], 'period' => $period, 'adjust' => $adjust, 'bars' => $bars, 'source' => $provider === 'tencent' ? '腾讯证券' : '东方财富', 'fetched_at' => time(), 'stale' => false);
}

function fieldnotes_market_series($symbol, $period, $adjust) {
    if ($period === 'minute') return fieldnotes_market_intraday_series($symbol);
    $key = 'fn_market_' . md5($symbol['canonical'] . '|' . $period . '|' . $adjust);
    $cached = get_transient($key);
    if (is_array($cached) && isset($cached['fetched_at']) && $cached['fetched_at'] > time() - 2) return $cached;
    // Optional integration point for a licensed feed, without accepting browser-supplied URLs.
    $custom = apply_filters('fieldnotes_market_series', null, $symbol, $period, $adjust);
    if (is_array($custom)) {
        $bars = fieldnotes_market_parse_rows($custom['rows'] ?? null);
        if (!is_wp_error($bars) && ($custom['symbol'] ?? '') === $symbol['canonical']) {
            $result = array('name' => sanitize_text_field(is_string($custom['name'] ?? null) ? $custom['name'] : $symbol['canonical']), 'code' => $symbol['code'], 'exchange' => $symbol['exchange'], 'symbol' => $symbol['canonical'], 'period' => $period, 'adjust' => $adjust, 'bars' => $bars, 'fetched_at' => time(), 'stale' => false, 'source' => sanitize_text_field(is_string($custom['source'] ?? null) ? $custom['source'] : '自定义行情'));
            set_transient($key, $result, DAY_IN_SECONDS);
            return $result;
        }
    }
    if (!fieldnotes_market_allow('history', 80)) {
        if (is_array($cached)) { $cached['stale'] = true; return $cached; }
        return new WP_Error('market_rate_limited', '查询较多，请稍候再试。');
    }
    $lock = $key . '_lock';
    $until = (int) get_option($lock);
    if ($until && $until < time()) delete_option($lock);
    if (!add_option($lock, time() + 30, '', false)) {
        if (is_array($cached)) { $cached['stale'] = true; return $cached; }
        return new WP_Error('market_pending', '正在读取行情，请稍候。');
    }
    try {
        foreach (array('tencent', 'eastmoney') as $provider) {
            $result = fieldnotes_market_provider($symbol, $period, $adjust, $provider);
            if (!is_wp_error($result)) { set_transient($key, $result, DAY_IN_SECONDS); return $result; }
        }
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) >= time() - DAY_IN_SECONDS) { $cached['stale'] = true; return $cached; }
        return $result;
    } finally { delete_option($lock); }
}

function fieldnotes_market_ajax() {
    
    $symbol = fieldnotes_market_symbol(isset($_GET['symbol']) && is_string($_GET['symbol']) ? wp_unslash($_GET['symbol']) : '');
    $period = isset($_GET['period']) && is_string($_GET['period']) ? $_GET['period'] : 'day';
    $adjust = isset($_GET['adjust']) && is_string($_GET['adjust']) ? $_GET['adjust'] : 'qfq';
    if (is_wp_error($symbol)) wp_send_json_error(array('code' => $symbol->get_error_code(), 'message' => $symbol->get_error_message()), 400);
    if (!in_array($period, array('minute', 'day', 'week', 'month'), true) || !in_array($adjust, array('qfq', 'none'), true)) wp_send_json_error(array('code' => 'invalid_period', 'message' => '行情参数无效。'), 400);
    $result = fieldnotes_market_series($symbol, $period, $adjust);
    if (is_wp_error($result)) wp_send_json_error(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), 502);
    wp_send_json_success($result);
}
add_action('wp_ajax_fieldnotes_market', 'fieldnotes_market_ajax');
add_action('wp_ajax_nopriv_fieldnotes_market', 'fieldnotes_market_ajax');

function fieldnotes_market_notes_data($symbol = null, $page = 1) {
    $term = fieldnotes_boughs_term('investing');
    $out = array('posts' => array(), 'total' => 0, 'all_total' => $term ? (int) $term->count : 0, 'page' => max(1, (int) $page), 'pages' => 0, 'latest' => '', 'has_more' => false);
    if (!$term) return $out;
    $args = array('post_type' => 'post', 'post_status' => 'publish', 'cat' => (int) $term->term_id, 'posts_per_page' => 5, 'paged' => $out['page'], 'ignore_sticky_posts' => true, 'orderby' => array('date' => 'DESC', 'ID' => 'DESC'));
    $query = new WP_Query($args);
    $out['total'] = (int) $query->found_posts;
    $out['pages'] = (int) $query->max_num_pages;
    $out['has_more'] = $out['page'] < $out['pages'];
    foreach ($query->posts as $post) {
        if (post_password_required($post)) {
            $excerpt = '这篇记录有口令保护。';
        } else $excerpt = wp_trim_words(wp_strip_all_tags(get_the_excerpt($post)), 38, '…');
        $out['posts'][] = array('title' => get_the_title($post), 'url' => get_permalink($post), 'date' => get_the_date('Y.m.d', $post), 'iso_date' => get_the_date('c', $post), 'excerpt' => $excerpt, 'symbol' => (string) get_post_meta($post->ID, '_fieldnotes_stock_code', true), 'sample' => (bool) get_post_meta($post->ID, '_fieldnotes_sample_key', true));
    }
    if ($out['posts']) $out['latest'] = $out['posts'][0]['date'];
    return $out;
}

function fieldnotes_market_notes_ajax() {
    $page = isset($_GET['notes_page']) && is_scalar($_GET['notes_page']) ? min(10000, max(1, absint($_GET['notes_page']))) : 1;
    wp_send_json_success(fieldnotes_market_notes_data(null, $page));
}
add_action('wp_ajax_fieldnotes_market_notes', 'fieldnotes_market_notes_ajax');
add_action('wp_ajax_nopriv_fieldnotes_market_notes', 'fieldnotes_market_notes_ajax');

function fieldnotes_market_metabox() {
    add_meta_box('fieldnotes-market-code', '投资观察 · 股票代码', 'fieldnotes_market_metabox_content', 'post', 'side');
}
add_action('add_meta_boxes', 'fieldnotes_market_metabox');
function fieldnotes_market_metabox_content($post) {
    wp_nonce_field('fieldnotes_market_meta', 'fieldnotes_market_nonce');
    echo '<label for="fieldnotes-stock-code">关联股票代码</label><input class="widefat" id="fieldnotes-stock-code" name="fieldnotes_stock_code" value="' . esc_attr(get_post_meta($post->ID, '_fieldnotes_stock_code', true)) . '" placeholder="600519 或 SH600519"><p>填写后归入投资理财，在 Markets 的市场手记归档中展示；留空可写一般理财文章。</p>';
}
function fieldnotes_market_save_meta($post_id) {
    if (!isset($_POST['fieldnotes_market_nonce']) || !is_string($_POST['fieldnotes_market_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['fieldnotes_market_nonce'])), 'fieldnotes_market_meta') || !current_user_can('edit_post', $post_id) || wp_is_post_revision($post_id) || defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    $raw = isset($_POST['fieldnotes_stock_code']) && is_string($_POST['fieldnotes_stock_code']) ? trim(wp_unslash($_POST['fieldnotes_stock_code'])) : '';
    if ($raw === '') { delete_post_meta($post_id, '_fieldnotes_stock_code'); return; }
    $symbol = fieldnotes_market_symbol($raw);
    if (is_wp_error($symbol)) return;
    update_post_meta($post_id, '_fieldnotes_stock_code', $symbol['canonical']);
    $term = fieldnotes_boughs_term('investing');
    if ($term) wp_set_post_categories($post_id, array((int) $term->term_id), false);
}
add_action('save_post_post', 'fieldnotes_market_save_meta', 30);

function fieldnotes_market_save_note() {
    if (!current_user_can('edit_posts')) wp_die('请登录具有写作权限的账号。', '', array('response' => 403));
    check_admin_referer('fieldnotes_market_note');
    $symbol = fieldnotes_market_symbol(isset($_POST['stock']) && is_string($_POST['stock']) ? wp_unslash($_POST['stock']) : '');
    $title = isset($_POST['note_title']) && is_string($_POST['note_title']) ? sanitize_text_field(wp_unslash($_POST['note_title'])) : '';
    $content = isset($_POST['note_content']) && is_string($_POST['note_content']) ? wp_kses_post(wp_unslash($_POST['note_content'])) : '';
    if (is_wp_error($symbol) || trim($title) === '' || trim($content) === '' || strlen($title) > 600 || strlen($content) > 100000) wp_die('请填写有效的股票代码、标题与正文。', '', array('response' => 400));
    $term = fieldnotes_boughs_term('investing');
    if (!$term) wp_die('栏目正在整理，请稍后重试。', '', array('response' => 503));
    $id = wp_insert_post(array('post_type' => 'post', 'post_status' => 'draft', 'post_author' => get_current_user_id(), 'post_title' => $title, 'post_content' => wpautop($content), 'post_category' => array((int) $term->term_id), 'meta_input' => array('_fieldnotes_stock_code' => $symbol['canonical'])), true);
    if (is_wp_error($id)) wp_die('草稿未能保存，请重试。', '', array('response' => 503));
    wp_safe_redirect(get_edit_post_link($id, 'raw')); exit;
}
add_action('admin_post_fieldnotes_market_note', 'fieldnotes_market_save_note');
