<?php
/**
 * Plugin Name: Keepsakes
 * Description: 收藏日常，选择长久相伴之物。
 * Version: 1.2.8
 * Author: issac
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: crolord-keepsakes
 * Update URI: https://github.com/Roliyal/CROlordWebShare
 */
if (!defined('ABSPATH')) exit;
define('CROLORD_KEEPSAKES_VERSION', '1.2.8');

require_once __DIR__.'/includes/public-catalog.php';
require_once __DIR__.'/includes/presentation.php';

function crks_settings() {
    $saved = get_option('crolord_keepsakes_settings', array());
    return is_array($saved) ? $saved : array();
}
function crks_settings_sanitize($input) { return crks_settings(); }
function crks_settings_register() {
    register_setting('crolord_keepsakes', 'crolord_keepsakes_settings', array('type' => 'array', 'sanitize_callback' => 'crks_settings_sanitize', 'show_in_rest' => false));
}
add_action('admin_init', 'crks_settings_register');
function crks_admin_menu() { add_options_page('Keepsakes', 'Keepsakes', 'manage_options', 'crolord-keepsakes', 'crks_admin_page'); }
add_action('admin_menu', 'crks_admin_menu');
function crks_admin_page() { if(!current_user_can('manage_options'))return;include __DIR__.'/includes/settings-view.php'; }

function crks_same_origin() {
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $site = wp_parse_url(home_url('/'));
    if ($origin !== '') {
        $o = wp_parse_url($origin);
        if (!is_array($o) || strtolower($o['host'] ?? '') !== strtolower($site['host'] ?? '') || ($o['scheme'] ?? '') !== ($site['scheme'] ?? '') || (int) ($o['port'] ?? (($o['scheme'] ?? '') === 'https' ? 443 : 80)) !== (int) ($site['port'] ?? (($site['scheme'] ?? '') === 'https' ? 443 : 80))) return false;
    }
    return !in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', array('cross-site'), true);
}
function crks_sid() {
    $sid = $_COOKIE['crolord_keepsakes_session'] ?? '';
    return is_string($sid) && preg_match('/^[A-Za-z0-9]{48}$/D', $sid) ? $sid : '';
}
function crks_session_key($sid) { return 'crks_s_' . hash_hmac('sha256', $sid, wp_salt('auth')); }
function crks_visitor($sid) { return hash_hmac('sha256', 'visitor|' . $sid, wp_salt('auth')); }
function crks_session($create = false) {
    $sid = crks_sid(); $session = $sid ? get_transient(crks_session_key($sid)) : false;
    if (!$session && $create && !headers_sent()) {
        $sid = wp_generate_password(48, false, false);
        $session = array('csrf' => wp_generate_password(48, false, false));
        set_transient(crks_session_key($sid), $session, HOUR_IN_SECONDS);
        setcookie('crolord_keepsakes_session', $sid, array('expires' => time() + HOUR_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => true, 'samesite' => 'Lax'));
        $_COOKIE['crolord_keepsakes_session'] = $sid;
    }
    return $session ? array('sid' => $sid, 'csrf' => $session['csrf'], 'visitor' => crks_visitor($sid)) : null;
}
function crks_permission() { return crks_same_origin() ? true : new WP_Error('crolord_origin', '请从本站页面操作。', array('status' => 403)); }
function crks_write_permission($request) {
    if (!crks_same_origin()) return new WP_Error('crolord_origin', '请从本站页面操作。', array('status' => 403));
    $license=null;if(is_wp_error($license))return $license;
    $session = crks_session(); $token = $request->get_header('x-crolord-csrf');
    return $session && is_string($token) && hash_equals($session['csrf'], $token) ? true : new WP_Error('crolord_session', '本页连接已过期，请重新打开选购清单。', array('status' => 403));
}
function crks_response($data, $code = 200) { $response = new WP_REST_Response($data, $code); $response->header('Cache-Control', 'no-store, private'); $response->header('X-Content-Type-Options', 'nosniff'); return $response; }
function crks_limit($visitor, $operation, $limit) {
    $key = 'crks_rl_' . hash('sha256', $visitor . '|' . $operation . '|' . floor(time() / 60));
    $n = (int) get_transient($key); if ($n >= $limit) return false;
    set_transient($key, $n + 1, 90); return true;
}
function crks_selectable($id) { return has_term('life-style','fave_kind',$id); }
function crks_products($ids) {
    if (!is_array($ids) || count($ids) < 1 || count($ids) > 12) return new WP_Error('crolord_items', '一次请选择 1 至 12 件物品。');
    $out = array();
    foreach ($ids as $id) {
        if (!is_scalar($id) || !ctype_digit((string) $id)) return new WP_Error('crolord_items', '物品信息需要重新读取。');
        $id = (int) $id; $p = get_post($id);
        if (!$p || $p->post_type !== 'journal_fave' || $p->post_status !== 'publish' || post_password_required($p) || !crks_selectable($id)) return new WP_Error('crolord_items', '有一件物品已无法选购，请刷新页面。');
        $raw=(string)get_post_meta($id,'_fieldnotes_purchase_url',true);
        $url=crks_jd_product_link($raw);
        $sku=$url?crks_jd_sku_from_url($url):($raw===''?crks_jd_sku((string)get_post_meta($id,'_fieldnotes_jd_sku',true)):'');
        if(!$url&&$sku)$url=crks_jd_item_url($sku);
        $out[(string) $id] = array('id' => (string) $id, 'title' => get_the_title($p), 'sku' => $sku, 'url' => $url);
    }
    return array_values($out);
}
function crks_provider($operation,$payload,$visitor) { return new WP_Error('crks_browser_cart','请使用清单中的京东加购入口，并在京东核对本人购物车。'); }
function crks_catalog_data($products,$visitor,$refresh=false) {
    $out=array('authorized'=>false,'cart_enabled'=>false,'authorize_url'=>'','products'=>array());
    foreach($products as $p){
        $p['variants']=array();$p['supports_cart']=false;$p['source']='';$p['fetched_at']=0;
        $value=$p['sku']?crks_catalog_lookup($p,$refresh):new WP_Error('crks_not_jd','请先为这件物品填写京东商品链接。');
        if(is_wp_error($value)){$p['catalog_status']=$value->get_error_code();$p['message']=$value->get_error_message();}
        else{$p['variants']=$value['variants'];$p['source']=$value['source'];$p['source_url']=$value['source_url'];$p['fetched_at']=$value['fetched_at'];$p['product_title']=$value['product_title']??'';$p['catalog_status']='ready';$p['message']='规格来自京东公开商品资料，以京东页面为准。';}
        $out['products'][]=$p;
    }
    return $out;
}
function crks_bootstrap() {
    $s = crks_session(true);
    return $s ? crks_response(array('csrf' => $s['csrf'])) : crks_response(array('message' => '选购连接暂未建立，请重新打开清单。'), 503);
}
function crks_catalog($request) {
    $s = crks_session(); if (!$s) return crks_response(array('message' => '请重新打开清单。'), 403);
    if (!crks_limit($s['visitor'], 'catalog', 30)) return crks_response(array('message' => '稍等片刻，再读取规格。'), 429);
    $products = crks_products($request->get_param('ids'));
    if (is_wp_error($products)) return crks_response(array('message' => $products->get_error_message()), 400);
    $result=crks_catalog_data($products,$s['visitor'],$request->get_param('refresh')===true);unset($result['_buyer_binding']);return crks_response($result);
}
function crks_cart($request) { return crks_response(array('message'=>'本站不代登录或写入京东购物车。请使用清单中的京东加购入口，结果由京东页面确认。'),410); }
function crks_routes() {
    register_rest_route('crolord/v1','/keepsakes/preview',array('methods'=>'POST','callback'=>'crks_preview','permission_callback'=>'crks_preview_permission'));
    register_rest_route('crolord/v1', '/keepsakes/session', array('methods' => 'GET', 'callback' => 'crks_bootstrap', 'permission_callback' => 'crks_permission'));
    register_rest_route('crolord/v1', '/keepsakes/catalog', array('methods' => 'POST', 'callback' => 'crks_catalog', 'permission_callback' => 'crks_write_permission'));
    register_rest_route('crolord/v1', '/keepsakes/cart', array('methods' => 'POST', 'callback' => 'crks_cart', 'permission_callback' => 'crks_write_permission'));
}
add_action('rest_api_init', 'crks_routes');
