<?php
if (!defined('ABSPATH')) exit;

function crks_short($value,$length) {
    if(function_exists('mb_substr'))return mb_substr((string)$value,0,$length);
    return preg_match('/^.{0,'.(int)$length.'}/us',(string)$value,$m)?$m[0]:'';
}
function crks_jd_sku($value) {
    if (!is_scalar($value)) return '';
    return preg_match('/^[1-9][0-9]{4,17}$/D', (string)$value) ? (string)$value : '';
}
function crks_jd_item_url($sku, $source_url = '') {
    if (!crks_jd_sku($sku)) return '';
    $host = strtolower((string) wp_parse_url($source_url, PHP_URL_HOST));
    $health = in_array($host, array('item.jingdonghealth.cn', 'mitem.jingdonghealth.cn'), true);
    return 'https://' . ($health ? 'item.jingdonghealth.cn' : 'item.jd.com') . '/' . $sku . '.html';
}
function crks_jd_sku_from_url($url) {
    if (!is_string($url)) return '';
    $p = wp_parse_url(trim($url));
    $hosts = array('item.jd.com', 'item.m.jd.com', 'item.jingdonghealth.cn', 'mitem.jingdonghealth.cn');
    if (!is_array($p) || !in_array(strtolower($p['scheme'] ?? ''), array('http', 'https'), true) || !in_array(strtolower($p['host'] ?? ''), $hosts, true) || isset($p['user']) || isset($p['pass']) || isset($p['port'])) return '';
    return preg_match('~^/(?:product/)?([1-9][0-9]{4,17})\.html$~D', $p['path'] ?? '', $m) ? $m[1] : '';
}
function crks_jd_product_link($url) { return crks_jd_item_url(crks_jd_sku_from_url($url), is_string($url) ? $url : ''); }
function crks_public_cache_key($product) {
    $sku = crks_jd_sku($product['sku'] ?? '');
    $url = crks_jd_item_url($sku, is_string($product['url'] ?? null) ? $product['url'] : '');
    return 'crks_public_v4_' . (strpos($url, 'https://item.jingdonghealth.cn/') === 0 ? 'health_' : 'jd_') . $sku;
}
function crks_json_fragment($text, $offset) {
    $stack=array();$quote=false;$escaped=false;$size=strlen($text);
    for($i=$offset;$i<$size && $i-$offset<=1500000;$i++){
        $c=$text[$i];
        if($quote){if($escaped)$escaped=false;elseif($c==='\\')$escaped=true;elseif($c==='"')$quote=false;continue;}
        if($c==='"'){$quote=true;continue;}
        if($c==='['||$c==='{'){$stack[]=$c;if(count($stack)>64)return null;}
        elseif($c===']'||$c==='}'){
            $open=array_pop($stack);if(($c===']'&&$open!=='[')||($c==='}'&&$open!=='{'))return null;
            if(!$stack){$value=json_decode(substr($text,$offset,$i-$offset+1),true,64,JSON_BIGINT_AS_STRING);return is_array($value)?$value:null;}
        }
    }
    return null;
}
function crks_public_variant($row) {
    if(!is_array($row))return null;
    $sku=crks_jd_sku($row['skuId']??$row['sku']??$row['productID']??'');
    $linked_sku = is_string($row['url'] ?? null) ? crks_jd_sku_from_url($row['url']) : '';
    if (!$sku) $sku = $linked_sku;
    if (!$sku || ($linked_sku && $linked_sku !== $sku)) return null;
    $attributes=array();
    foreach(array('颜色','尺码','尺寸','型号','版本','容量','套装','color','size','model','material','pattern') as $key)if(is_scalar($row[$key]??null)&&trim((string)$row[$key])!=='')$attributes[$key]=sanitize_text_field(crks_short((string)$row[$key],120));
    foreach((array)($row['attributes']??array()) as $key=>$value)if(is_string($key)&&is_scalar($value)&&count($attributes)<8)$attributes[sanitize_text_field(crks_short($key,40))]=sanitize_text_field(crks_short((string)$value,120));
    foreach((array)($row['additionalProperty']??array()) as $prop)if(is_array($prop)&&is_string($prop['name']??null)&&is_scalar($prop['value']??null)&&count($attributes)<8)$attributes[sanitize_text_field(crks_short($prop['name'],40))]=sanitize_text_field(crks_short((string)$prop['value'],120));
    $offer=$row['offers']??array();if(isset($offer[0]))$offer=$offer[0];if(!is_array($offer))$offer=array();
    $availability=$offer['availability']??$row['availability']??'';
    $available=in_array($availability,array('https://schema.org/InStock','http://schema.org/InStock','InStock'),true)?true:(in_array($availability,array('https://schema.org/OutOfStock','http://schema.org/OutOfStock','OutOfStock','Discontinued'),true)?false:null);
    $name=is_string($row['name']??null)?sanitize_text_field(crks_short($row['name'],200)):'';
    if(!$name&&!$attributes)return null;
    return array('sku'=>$sku,'name'=>$name,'attributes'=>$attributes,'available'=>$available,'max_quantity'=>99,'url'=>crks_jd_item_url($sku, is_string($row['url'] ?? null) ? $row['url'] : ''));
}
function crks_mobile_item($html,$sku) {
    if(!preg_match('/(?:window\.)?_itemOnly\s*=\s*\(?\s*(\{)/',$html,$match,PREG_OFFSET_CAPTURE))return null;
    $root=crks_json_fragment($html,$match[1][1]);if(!is_array($root)||!is_array($root['item']??null))return null;$item=$root['item'];
    if(crks_jd_sku($item['skuId']??'')!==$sku)return new WP_Error('crks_public_mismatch','返回的商品编号与链接不一致，未使用其他商品的规格。');
    $rows=$item['newColorSize']??array();if(!is_array($rows)||count($rows)>100)return null;$variants=array();
    foreach($rows as $row){
        if(!is_array($row))continue;$attributes=array();
        foreach(array_slice((array)($item['saleProp']??array()),0,8,true) as $key=>$label)if(is_string($label)&&trim($label)!==''&&is_scalar($row[$key]??null)&&trim((string)$row[$key])!=='')$attributes[sanitize_text_field(crks_short($label,40))]=sanitize_text_field(crks_short((string)$row[$key],120));
        $normalized=$row;if($attributes){$normalized['attributes']=$attributes;foreach(array('color','size','spec') as $key)unset($normalized[$key]);}
        $v=crks_public_variant($normalized);if(!$v)continue;
        if(isset($variants[$v['sku']])&&$variants[$v['sku']]['attributes']!==$v['attributes'])return new WP_Error('crks_public_mismatch','商品规格数据不一致，请稍后重新读取。');$variants[$v['sku']]=$v;
    }
    if(!isset($variants[$sku]))return null;
    return array('variants'=>array_values($variants),'product_title'=>is_string($item['skuName']??null)?sanitize_text_field(crks_short($item['skuName'],200)):'');
}
function crks_public_parse($html,$sku,$url) {
    if(!is_string($html)||strlen($html)>2000000||!crks_jd_sku($sku))return new WP_Error('crks_public_shape','商品规格暂不可读取。');
    $mobile=crks_mobile_item($html,$sku);if(is_wp_error($mobile))return $mobile;
    if(is_array($mobile)){foreach($mobile['variants'] as &$variant)$variant['url']=crks_jd_item_url($variant['sku'],$url);unset($variant);return array_merge($mobile,array('source'=>'jd_public_mobile','source_url'=>$url,'fetched_at'=>time(),'supports_cart'=>false,'message'=>'规格来自京东公开商品资料，以京东页面为准。'));}
    $groups=array();
    if(preg_match_all('~<script\b[^>]*type=["\x27]application/ld\+json["\x27][^>]*>(.*?)</script>~is',$html,$scripts))foreach(array_slice($scripts[1],0,16) as $script){
        $value=json_decode($script,true,64,JSON_BIGINT_AS_STRING);if(!is_array($value))$value=json_decode(html_entity_decode($script,ENT_QUOTES,'UTF-8'),true,64,JSON_BIGINT_AS_STRING);if(!is_array($value))continue;
        $nodes=isset($value['@graph'])?$value['@graph']:(isset($value[0])?$value:array($value));
        foreach(array_slice($nodes,0,64) as $node){if(!is_array($node))continue;$type=$node['@type']??'';if(is_array($type))$type=implode(' ',$type);if(!is_string($type)||!preg_match('/\bProduct(?:Group)?\b/',$type))continue;$group=array();
            $root=crks_public_variant($node);if($root)$group[]=$root;
            foreach(array_slice((array)($node['hasVariant']??array()),0,100) as $row){$v=crks_public_variant($row);if($v)$group[]=$v;}
            if($group)$groups[]=$group;
        }
    }
    if(preg_match_all('/\b(?:colorSize|colorSizeList|skuColorSize|newColorSize)\s*["\x27]?\s*[:=]\s*([\[{])/',$html,$matches,PREG_OFFSET_CAPTURE))foreach(array_slice($matches[1],0,12) as $match){
        $value=crks_json_fragment($html,$match[1]);if(!is_array($value))continue;$group=array();
        foreach(array_slice($value,0,100) as $row){$v=crks_public_variant($row);if($v)$group[]=$v;}
        if($group)$groups[]=$group;
    }
    $variants=array();
    foreach($groups as $group){if(!in_array($sku,array_column($group,'sku'),true))continue;foreach($group as $v)$variants[$v['sku']]=$v;if($variants)break;}
    if(!$variants)return new WP_Error('crks_public_dynamic','暂时没读到这件商品的规格，可到京东商品页继续选择。');
    foreach($variants as &$variant)$variant['url']=crks_jd_item_url($variant['sku'],$url);unset($variant);
    return array('variants'=>array_values($variants),'source'=>'jd_public','source_url'=>$url,'fetched_at'=>time(),'supports_cart'=>false,'message'=>'规格来自京东公开商品资料，以京东页面为准。');
}
/** Only follow a public product redirect to the same SKU on an explicit host. */
function crks_public_fetch($url, $sku, $deadline) {
    $visited = array();
    for ($hop = 0; $hop < 3; $hop++) {
        $remaining = $deadline - microtime(true);
        if ($remaining < 0.5) return new WP_Error('crks_public_network', '公开规格连接未完成，请稍后重试。');
        if (isset($visited[$url])) break;
        $visited[$url] = true;
        $response = wp_safe_remote_get($url, array(
            'timeout' => min(6, $remaining), 'redirection' => 0, 'sslverify' => true,
            'limit_response_size' => 2000000, 'cookies' => array(),
            'headers' => array('Accept' => 'text/html', 'Accept-Language' => 'zh-CN,zh;q=0.9', 'User-Agent' => 'Crolord-Keepsakes/' . CROLORD_KEEPSAKES_VERSION . ' public product metadata'),
        ));
        if (is_wp_error($response)) return new WP_Error('crks_public_network', '公开规格连接未完成，请稍后重试。');
        $status = wp_remote_retrieve_response_code($response);
        if (!in_array($status, array(301, 302, 303, 307, 308), true)) return array('response' => $response, 'url' => $url);
        $next = trim((string) wp_remote_retrieve_header($response, 'location'));
        if (strpos($next, '//') === 0) $next = 'https:' . $next;
        elseif (strpos($next, '/') === 0) $next = 'https://' . wp_parse_url($url, PHP_URL_HOST) . $next;
        if (strtolower((string) wp_parse_url($next, PHP_URL_SCHEME)) !== 'https' || crks_jd_sku_from_url($next) !== $sku) break;
        $url = $next;
    }
    return new WP_Error('crks_public_login', '暂时无法读取公开规格，可到京东商品页继续选择。');
}
function crks_public_catalog($product, $refresh = false) {
    $sku = crks_jd_sku($product['sku'] ?? '');
    if (!$sku) return new WP_Error('crks_not_jd', '此条目不是可核验的京东商品链接。');
    $source = crks_jd_product_link($product['url'] ?? '');
    if (!$source) $source = crks_jd_item_url($sku);
    if (crks_jd_sku_from_url($source) !== $sku) return new WP_Error('crks_public_mismatch', '商品编号与链接不一致，未使用其他商品的规格。');
    $key = crks_public_cache_key($product);
    $cached = get_transient($key); $last = get_transient($key . '_checked');
    if (is_array($cached) && (!$refresh || $last)) return !empty($cached['error']) ? new WP_Error($cached['error'], $cached['message']) : $cached;
    $lock = $key . '_lock'; $until = (int) get_option($lock);
    if ($until && $until < time()) delete_option($lock);
    if (!add_option($lock, time() + 20, '', false)) return new WP_Error('crks_public_pending', '正在读取该商品的公开规格，请稍后刷新。');
    try {
        $value = new WP_Error('crks_public_network', '公开规格连接未完成，请稍后重试。');
        $deadline = microtime(true) + 14;
        $mobile = strpos($source, 'https://item.jingdonghealth.cn/') === 0 ? 'https://mitem.jingdonghealth.cn/product/' . $sku . '.html' : 'https://item.m.jd.com/product/' . $sku . '.html';
        foreach (array($mobile, $source) as $url) {
            if (microtime(true) >= $deadline) break;
            $fetched = crks_public_fetch($url, $sku, $deadline);
            if (is_wp_error($fetched)) { $value = $fetched; if ($value->get_error_code() === 'crks_public_login') break; continue; }
            $response = $fetched['response']; $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $value = new WP_Error('crks_public_denied', '京东暂未提供公开规格，可到商品页继续选择。');
                if (in_array($code, array(401, 403, 412, 429, 461), true)) break;
                continue;
            }
            $value = crks_public_parse(wp_remote_retrieve_body($response), $sku, $fetched['url']);
            if (!is_wp_error($value) || $value->get_error_code() === 'crks_public_mismatch') break;
        }
        set_transient($key, is_wp_error($value) ? array('error' => $value->get_error_code(), 'message' => $value->get_error_message()) : $value, is_wp_error($value) ? 2 * MINUTE_IN_SECONDS : 30 * MINUTE_IN_SECONDS);
        set_transient($key . '_checked', 1, MINUTE_IN_SECONDS);
        return $value;
    } finally { delete_option($lock); }
}
