<?php
/** Email avatar lookup and a signed, same-origin image cache. */
if (!defined('ABSPATH')) exit;

function fct_avatar_rating() {
    $rating = strtolower((string) get_option('avatar_rating', 'g'));
    return in_array($rating, array('g', 'pg', 'r', 'x'), true) ? $rating : 'g';
}

function fct_avatar_rate_limit($scope = 'lookup', $limit = 30) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $key = 'fct_avatar_rate_' . $scope . '_' . hash_hmac('sha256', $ip . '|' . (int) floor(time() / MINUTE_IN_SECONDS), wp_salt('nonce'));
    $count = (int) get_transient($key);
    if ($count >= $limit) return false;
    set_transient($key, $count + 1, 2 * MINUTE_IN_SECONDS);
    return true;
}

function fct_avatar_image_url($hash, $rating) {
    return add_query_arg(array(
        'action' => 'fct_avatar_image', 'hash' => $hash, 'rating' => $rating,
        'sig' => hash_hmac('sha256', $hash . '|' . $rating, wp_salt('auth')),
    ), wp_make_link_relative(admin_url('admin-ajax.php')));
}

function fct_avatar_fetch($hash, $rating, $refresh = false) {
    $key = 'fct_gv171_' . $hash . '_' . $rating;
    $stale_key = 'fct_gv171_stale_' . $hash . '_' . $rating;
    $cached = get_transient($key);
    if (!$refresh && is_array($cached) && isset($cached['status'])) return $cached;
    if (!fct_avatar_rate_limit('upstream')) return array('status' => 'unavailable', 'reason' => 'rate_limited');

    $record = array('status' => 'unavailable', 'reason' => 'upstream_unavailable');
    // GET avoids hosts or intermediaries that reject HEAD. Only official hosts
    // are used; SSL verification and WordPress URL safety checks stay enabled.
    foreach (array('secure.gravatar.com' => 8, 'www.gravatar.com' => 4) as $host => $timeout) {
        $url = 'https://' . $host . '/avatar/' . $hash . '?s=192&d=404&r=' . $rating;
        $response = wp_safe_remote_get($url, array(
            'timeout' => $timeout, 'redirection' => 2, 'limit_response_size' => 262145,
            'headers' => array('Accept' => 'image/webp,image/png,image/jpeg,image/gif'),
        ));
        if (is_wp_error($response)) continue;
        $code = wp_remote_retrieve_response_code($response);
        if ($code === 404) {
            $record = array('status' => 'missing');
            delete_transient($stale_key);
            break;
        }
        if ($code !== 200) { $record['reason'] = 'upstream_http'; continue; }
        $body = wp_remote_retrieve_body($response);
        $info = strlen($body) < 262145 ? @getimagesizefromstring($body) : false;
        if (!$info || empty($info['mime']) || !in_array($info['mime'], array('image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif'), true)) {
            $record['reason'] = 'invalid_image';
            continue;
        }
        $record = array('status' => 'found', 'mime' => $info['mime'], 'body' => base64_encode($body));
        set_transient($stale_key, $record, 3 * DAY_IN_SECONDS);
        break;
    }
    if ($record['status'] === 'unavailable') {
        $stale = get_transient($stale_key);
        if (is_array($stale) && isset($stale['status'], $stale['body']) && $stale['status'] === 'found') {
            $record = $stale;
            $record['stale'] = true;
        }
    }
    $ttl = $record['status'] === 'found' ? (empty($record['stale']) ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS) : ($record['status'] === 'missing' ? 5 * MINUTE_IN_SECONDS : 20);
    set_transient($key, $record, $ttl);
    if($record['status']==='found')fct_avatar_store_static($hash,$rating,$record);
    return $record;
}

function fct_gravatar_lookup($email, $refresh = false) {
    $email = strtolower(trim((string) $email));
    if (!is_email($email)) return array('status' => 'unavailable', 'reason' => 'invalid_email');
    $hash = hash('sha256', $email);
    $rating = fct_avatar_rating();
    $record = fct_avatar_fetch($hash, $rating, $refresh);
    // Binary data and cache records stay on the server, never in lookup JSON.
    $result = array('status' => $record['status']);
    if ($record['status'] === 'found') {
        $result['url'] = fct_avatar_image_url($hash, $rating);
        $result['stale'] = !empty($record['stale']);
    } elseif (!empty($record['reason'])) $result['reason'] = $record['reason'];
    return $result;
}

function fct_native_avatar_url($email) {
    $subject = is_user_logged_in() ? wp_get_current_user() : $email;
    $html = get_avatar($subject, 96, '404', '', array('loading' => 'eager', 'force_default' => false));
    $src = '';
    if (is_string($html) && class_exists('WP_HTML_Tag_Processor')) {
        $processor = new WP_HTML_Tag_Processor($html);
        if ($processor->next_tag('IMG')) $src = $processor->get_attribute('src');
    } elseif (is_string($html) && preg_match('/\ssrc\s*=\s*([\'"])(.*?)\1/is', $html, $matches)) {
        $src = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
    }
    if (!is_string($src) || $src === '' || strlen($src) > 4096) return '';
    return esc_url_raw($src, array('http', 'https'));
}

function fct_ajax_avatar_refresh_nonce() {
    nocache_headers();
    if (!fct_avatar_rate_limit('nonce', 30)) wp_send_json_error(array('reason' => 'rate_limited'), 429);
    wp_send_json_success(array('nonce' => wp_create_nonce('fct_avatar_lookup')));
}
add_action('wp_ajax_fct_avatar_refresh_nonce', 'fct_ajax_avatar_refresh_nonce');
add_action('wp_ajax_nopriv_fct_avatar_refresh_nonce', 'fct_ajax_avatar_refresh_nonce');

function fct_ajax_avatar_lookup() {
    nocache_headers();
    if (false === check_ajax_referer('fct_avatar_lookup', 'nonce', false)) wp_send_json_error(array('reason' => 'expired_nonce'), 403);
    
    if (!fct_avatar_rate_limit()) wp_send_json_error(array('status' => 'unavailable', 'reason' => 'rate_limited'), 429);
    $raw = isset($_POST['email']) && is_string($_POST['email']) ? trim(wp_unslash($_POST['email'])) : '';
    if (is_user_logged_in()) $raw = wp_get_current_user()->user_email;
    if (strlen($raw) > 100 || !is_email($raw)) wp_send_json_error(array('status' => 'invalid'), 400);
    if (isset($_POST['native_first']) && $_POST['native_first'] === '1') {
        $native = fct_native_avatar_url($raw);
        // Returning a candidate makes no claim that Gravatar was found. The
        // browser must actually load it before displaying a successful state.
        wp_send_json_success(array('status' => 'candidate', 'url' => $native));
    }
    wp_send_json_success(fct_gravatar_lookup($raw, isset($_POST['refresh']) && $_POST['refresh'] === '1'));
}
add_action('wp_ajax_fct_avatar_lookup', 'fct_ajax_avatar_lookup');
add_action('wp_ajax_nopriv_fct_avatar_lookup', 'fct_ajax_avatar_lookup');

function fct_avatar_image_response($hash, $rating, $signature) {
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash) || !is_string($rating) || !in_array($rating, array('g', 'pg', 'r', 'x'), true) || !is_string($signature) || !hash_equals(hash_hmac('sha256', $hash . '|' . $rating, wp_salt('auth')), $signature)) {
        return array('code' => 403, 'body' => '', 'mime' => 'text/plain', 'ttl' => 0);
    }
    $record = fct_avatar_fetch($hash, $rating);
    if ($record['status'] === 'found') {
        $body = base64_decode($record['body'], true);
        if ($body !== false) return array('code' => 200, 'body' => $body, 'mime' => $record['mime'], 'ttl' => empty($record['stale']) ? HOUR_IN_SECONDS : MINUTE_IN_SECONDS);
    }
    // A network outage must not produce a broken-image icon in a comment.
    return array('code' => 200, 'body' => file_get_contents(FCT_DIR . 'assets/avatars/avatar-placeholder.svg'), 'mime' => 'image/svg+xml', 'ttl' => 0);
}

function fct_ajax_avatar_image() {
    $response = fct_avatar_image_response(isset($_GET['hash']) ? wp_unslash($_GET['hash']) : '', isset($_GET['rating']) ? wp_unslash($_GET['rating']) : '', isset($_GET['sig']) ? wp_unslash($_GET['sig']) : '');
    status_header($response['code']);
    header('Content-Type: ' . $response['mime']);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: ' . ($response['ttl'] ? 'public, max-age=' . $response['ttl'] : 'no-store'));
    echo $response['body']; // Validated raster bytes or a bundled, fixed SVG.
    exit;
}
add_action('wp_ajax_fct_avatar_image', 'fct_ajax_avatar_image');
add_action('wp_ajax_nopriv_fct_avatar_image', 'fct_ajax_avatar_image');
