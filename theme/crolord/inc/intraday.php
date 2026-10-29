<?php
/** Actual regular-session minute prices, without filling future or missing points. */
if (!defined('ABSPATH')) exit;

function fieldnotes_market_minute_rows($rows, $date) {
    if (!is_array($rows) || !$rows || count($rows) > 300 || !is_string($date) || !preg_match('/^(\d{4})-?(\d{2})-?(\d{2})$/D', $date, $d) || !checkdate((int) $d[2], (int) $d[3], (int) $d[1])) return new WP_Error('invalid_minutes', '分时数据日期或格式无效。');
    $day = $d[1] . '-' . $d[2] . '-' . $d[3];
    $points = array(); $last_minute = -1; $last_volume = 0;
    foreach ($rows as $row) {
        $fields = is_string($row) ? preg_split('/\s+/', trim($row)) : $row;
        if (!is_array($fields) || !isset($fields[0], $fields[1], $fields[2]) || !preg_match('/^(\d{2}):?(\d{2})$/D', (string) $fields[0], $time)) return new WP_Error('invalid_minutes', '分时时间格式无效。');
        $hour = (int) $time[1]; $minute = (int) $time[2]; $clock = $hour * 60 + $minute;
        if ($hour > 23 || $minute > 59) return new WP_Error('invalid_minutes', '分时时间格式无效。');
        // The provider can append auction, recess and post-close snapshots.
        // Only regular sessions belong on the chart's 09:30-15:00 axis.
        if (!(($clock >= 570 && $clock <= 690) || ($clock >= 780 && $clock <= 900))) continue;
        if ($clock <= $last_minute) return new WP_Error('invalid_minutes', '分时数据时间顺序异常。');
        if (!is_numeric($fields[1]) || !is_numeric($fields[2])) return new WP_Error('invalid_minutes', '分时价格或成交量格式无效。');
        $price = (float) $fields[1]; $cumulative = (float) $fields[2];
        if (!is_finite($price) || !is_finite($cumulative) || $price <= 0 || $cumulative < $last_volume) return new WP_Error('invalid_minutes', '分时价格或成交量无效。');
        $points[] = array('time' => $time[1] . ':' . $time[2], 'price' => $price, 'volume' => $cumulative - $last_volume);
        $last_volume = $cumulative; $last_minute = $clock;
    }
    if (!$points) return new WP_Error('minute_unavailable', '当前尚无常规交易时段的分时数据，请稍后重试。');
    return array('date' => $day, 'points' => $points);
}

function fieldnotes_market_intraday_provider($symbol) {
    $url = add_query_arg('code', $symbol['tencent'], 'https://web.ifzq.gtimg.cn/appstock/app/minute/query');
    $response = wp_remote_get($url, array('timeout' => 12, 'redirection' => 0, 'sslverify' => true, 'limit_response_size' => 1048576, 'headers' => array('Accept' => 'application/json', 'User-Agent' => 'Mozilla/5.0')));
    if (is_wp_error($response) || (int) wp_remote_retrieve_response_code($response) !== 200) return new WP_Error('minute_unavailable', '暂时无法读取分时数据，请稍后重试。');
    $json = json_decode(wp_remote_retrieve_body($response), true);
    if (!is_array($json)) return new WP_Error('invalid_minutes', '分时服务没有返回有效数据。');
    $item = $json['data'][$symbol['tencent']] ?? null;
    if ( ($json['code'] ?? -1) !== 0 || !is_array($item)) return new WP_Error('minute_unavailable', '这只股票暂时没有可用的分时数据。');
    if (!is_array($item['qt'] ?? null) || !is_array($item['data'] ?? null)) return new WP_Error('invalid_minutes', '分时服务返回的字段结构无效。');
    $quote = $item['qt'][$symbol['tencent']] ?? array();
    if (!isset($quote[2]) || (string) $quote[2] !== $symbol['code']) return new WP_Error('symbol_mismatch', '分时数据的股票代码不匹配。');
    $series = fieldnotes_market_minute_rows($item['data']['data'] ?? null, $item['data']['date'] ?? '');
    if (is_wp_error($series)) return $series;
    $previous = isset($quote[4]) && is_numeric($quote[4]) && is_finite((float) $quote[4]) && (float) $quote[4] > 0 ? (float) $quote[4] : null;
    return array('symbol' => $symbol['canonical'], 'name' => sanitize_text_field(is_string($quote[1] ?? null) ? $quote[1] : $symbol['canonical']), 'exchange' => $symbol['exchange'], 'period' => 'minute', 'adjust' => 'none', 'date' => $series['date'], 'points' => $series['points'], 'previous_close' => $previous, 'source' => '腾讯证券', 'fetched_at' => time(), 'stale' => false);
}

function fieldnotes_market_intraday_series($symbol) {
    $key = 'fn_intraday_' . md5($symbol['canonical']);
    $cached = get_transient($key);
    if (is_array($cached) && ($cached['fetched_at'] ?? 0) > time() - 2) return $cached;
    if (!fieldnotes_market_allow('intraday', 80)) {
        if (is_array($cached)) { $cached['stale'] = true; return $cached; }
        return new WP_Error('market_rate_limited', '查询较多，请一分钟后再试。');
    }
    $lock = $key . '_lock';
    $until = (int) get_option($lock);
    if ($until && $until < time()) delete_option($lock);
    if (!add_option($lock, time() + 15, '', false)) {
        if (is_array($cached)) { $cached['stale'] = true; return $cached; }
        return new WP_Error('minute_pending', '正在读取分时，请稍候。');
    }
    try {
        $result = fieldnotes_market_intraday_provider($symbol);
        if (!is_wp_error($result)) { set_transient($key, $result, DAY_IN_SECONDS); return $result; }
        if (is_array($cached) && ($cached['fetched_at'] ?? 0) >= time() - DAY_IN_SECONDS) { $cached['stale'] = true; return $cached; }
        return $result;
    } finally { delete_option($lock); }
}
