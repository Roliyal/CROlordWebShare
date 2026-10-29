<?php
/** Fixed-host stock discovery and timestamped quotes; browser never supplies a feed URL. */
if (!defined('ABSPATH')) exit;

function fieldnotes_market_allow($scope, $limit) {
    $bucket = (int) floor(time() / MINUTE_IN_SECONDS);
    $key = 'fn_mrate_' . substr(wp_hash($scope . '|' . (string) ($_SERVER['REMOTE_ADDR'] ?? '') . '|' . $bucket), 0, 28);
    $used = (int) get_transient($key);
    if ($used >= $limit) return false;
    set_transient($key, $used + 1, MINUTE_IN_SECONDS);
    return true;
}

function fieldnotes_market_utf8($body) {
    if (preg_match('//u', $body)) return $body;
    if (function_exists('mb_convert_encoding')) return mb_convert_encoding($body, 'UTF-8', 'GB18030');
    if (function_exists('iconv')) {
        $converted = iconv('GB18030', 'UTF-8', $body);
        if ($converted !== false) return $converted;
    }
    return '';
}

function fieldnotes_market_search_parse($body) {
    // Decode the quoted value as JSON, never evaluate the provider's JavaScript.
    if (!is_string($body) || strlen($body) > 65536 || !preg_match('/^\s*v_hint\s*=\s*("(?:[^"\\\\]|\\\\.)*")\s*;?\s*$/sD', $body, $match)) return new WP_Error('search_unavailable', '股票检索暂不可用，请使用六位代码。');
    $value = json_decode($match[1], true);
    if (!is_string($value)) return new WP_Error('search_unavailable', '股票检索暂不可用，请使用六位代码。');
    $items = array();
    foreach (explode('^', $value) as $row) {
        $fields = explode('~', $row);
        if (count($fields) < 5 || !in_array(strtolower($fields[0]), array('sh', 'sz', 'bj'), true) || $fields[4] !== 'GP-A') continue;
        $symbol = fieldnotes_market_symbol($fields[0] . $fields[1]);
        if (is_wp_error($symbol)) continue;
        $items[$symbol['canonical']] = array('symbol' => $symbol['canonical'], 'name' => sanitize_text_field($fields[2]), 'exchange' => $symbol['exchange']);
        if (count($items) >= 12) break;
    }
    return array_values($items);
}

function fieldnotes_market_search($query) {
    if (!is_string($query) || !preg_match('/^[\p{Han}a-zA-Z0-9 .:]{2,32}$/uD', $query)) return new WP_Error('invalid_query', '请输入至少两个字、名称简拼或股票代码。');
    if (in_array(strtoupper(trim($query)), array('上证指数', '上证', '沪指', 'SH000001', '000001.SH'), true)) return array(array('symbol' => 'SH000001', 'name' => '上证指数'));
    $key = 'fn_msearch_' . md5($query);
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;
    if (!fieldnotes_market_allow('search', 24)) return new WP_Error('search_rate_limited', '检索较多，请稍候再试；也可以直接输入股票代码。');
    $url = add_query_arg(array('t' => 'all', 'q' => $query), 'https://smartbox.gtimg.cn/s3/');
    $response = wp_remote_get($url, array('timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536, 'headers' => array('User-Agent' => 'Mozilla/5.0')));
    $items = is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200 ? new WP_Error('search_unavailable', '股票检索暂不可用，请使用六位代码。') : fieldnotes_market_search_parse(fieldnotes_market_utf8(wp_remote_retrieve_body($response)));
    if (is_wp_error($items) || !$items) {
        $fallback = fieldnotes_market_search_eastmoney($query);
        if (!is_wp_error($fallback)) $items = $fallback;
    }
    if (!is_wp_error($items)) set_transient($key, $items, HOUR_IN_SECONDS);
    return $items;
}

function fieldnotes_market_search_eastmoney($query) {
    $url = add_query_arg(array('input' => $query, 'type' => 14, 'count' => 12), 'https://searchapi.eastmoney.com/api/suggest/get');
    $response = wp_remote_get($url, array('timeout' => 8, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536, 'headers' => array('User-Agent' => 'Mozilla/5.0')));
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('search_unavailable', '名称检索暂不可用，可直接输入六位股票代码。');
    $json = json_decode(wp_remote_retrieve_body($response), true);
    $rows = $json['QuotationCodeTable']['Data'] ?? null;
    if (!is_array($rows)) return new WP_Error('search_unavailable', '名称检索没有返回有效数据。');
    $out = array();
    foreach ($rows as $item) {
        if (!is_array($item) || ($item['Classify'] ?? '') !== 'AStock' || !is_string($item['Code'] ?? null) || !is_string($item['Name'] ?? null)) continue;
        $symbol = fieldnotes_market_symbol($item['Code']);
        if (is_wp_error($symbol)) continue;
        $out[$symbol['canonical']] = array('symbol' => $symbol['canonical'], 'name' => sanitize_text_field($item['Name']), 'exchange' => $symbol['exchange']);
        if (count($out) >= 12) break;
    }
    return array_values($out);
}

function fieldnotes_market_search_ajax() {
    
    $query = isset($_GET['q']) && is_string($_GET['q']) ? trim(wp_unslash($_GET['q'])) : '';
    $result = fieldnotes_market_search($query);
    if (is_wp_error($result)) wp_send_json_error(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), 400);
    wp_send_json_success(array('items' => $result));
}
add_action('wp_ajax_fieldnotes_market_search', 'fieldnotes_market_search_ajax');
add_action('wp_ajax_nopriv_fieldnotes_market_search', 'fieldnotes_market_search_ajax');

function fieldnotes_market_quote_parse($body, $symbol) {
    if (!is_string($body) || strlen($body) > 65536 || !preg_match('/^\s*v_' . preg_quote($symbol['tencent'], '/') . '\s*=\s*"([^"\r\n]*)";?\s*$/D', $body, $m)) return new WP_Error('quote_unavailable', '暂未取得最新报价。');
    $fields = explode('~', $m[1]);
    if (count($fields) < 35 || $fields[2] !== $symbol['code'] || !preg_match('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/D', $fields[30], $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1]) || (int) $d[4] > 23 || (int) $d[5] > 59 || (int) $d[6] > 59) return new WP_Error('invalid_quote', '报价代码或时间无效。');
    foreach (array(3, 4) as $i) if (!is_numeric($fields[$i]) || !is_finite((float) $fields[$i]) || (float) $fields[$i] <= 0) return new WP_Error('invalid_quote', '报价价格无效。');
    $price = (float) $fields[3]; $previous = (float) $fields[4];
    return array('symbol' => $symbol['canonical'], 'name' => sanitize_text_field($fields[1]), 'price' => $price, 'previous_close' => $previous, 'change' => $price - $previous, 'percent' => ($price / $previous - 1) * 100, 'as_of' => $d[1] . '-' . $d[2] . '-' . $d[3] . 'T' . $d[4] . ':' . $d[5] . ':' . $d[6] . '+08:00', 'source' => '腾讯证券', 'fetched_at' => time(), 'stale' => false);
}

function fieldnotes_market_quote($symbol) {
    $key = 'fn_mquote_' . md5($symbol['canonical']);
    $cached = get_transient($key);
    if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - 2) return $cached;
    if (!fieldnotes_market_allow('live', 80)) {
        if (is_array($cached)) { $cached['stale'] = true; return $cached; }
        return new WP_Error('quote_rate_limited', '更新较多，请稍候再试。');
    }
    $lock = $key . '_lock';
    $until = (int) get_option($lock);
    if ($until && $until < time()) delete_option($lock);
    if (!add_option($lock, time() + 15, '', false)) {
        if (is_array($cached)) { $cached['stale'] = true; return $cached; }
        return new WP_Error('quote_pending', '正在取得最新报价，请稍候。');
    }
    try {
        $response = wp_remote_get('https://qt.gtimg.cn/q=' . rawurlencode($symbol['tencent']), array('timeout' => 10, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 65536, 'headers' => array('User-Agent' => 'Mozilla/5.0')));
        $result = is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200 ? new WP_Error('quote_unavailable', '最新报价暂未连接。') : fieldnotes_market_quote_parse(fieldnotes_market_utf8(wp_remote_retrieve_body($response)), $symbol);
        if (!is_wp_error($result)) { set_transient($key, $result, DAY_IN_SECONDS); return $result; }
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - DAY_IN_SECONDS) { $cached['stale'] = true; return $cached; }
        return $result;
    } finally { delete_option($lock); }
}

function fieldnotes_market_quote_ajax() {
    
    $symbol = fieldnotes_market_symbol(isset($_GET['symbol']) && is_string($_GET['symbol']) ? wp_unslash($_GET['symbol']) : '');
    if (is_wp_error($symbol)) wp_send_json_error(array('message' => $symbol->get_error_message()), 400);
    $result = fieldnotes_market_quote($symbol);
    if (is_wp_error($result)) wp_send_json_error(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()), 502);
    wp_send_json_success($result);
}
add_action('wp_ajax_fieldnotes_market_quote', 'fieldnotes_market_quote_ajax');
add_action('wp_ajax_nopriv_fieldnotes_market_quote', 'fieldnotes_market_quote_ajax');
