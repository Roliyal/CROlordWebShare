<?php
/** Cache real OSM city geometry in background; never geocode during a page render. */
if (!defined('ABSPATH')) exit;
function fntm_city_geometry($geometry) {
    if (!is_array($geometry) || !in_array($geometry['type']??'',array('Polygon','MultiPolygon'),true) || !is_array($geometry['coordinates']??null)) return null;
    $polygons=$geometry['type']==='Polygon'?array($geometry['coordinates']):$geometry['coordinates'];$clean=array();$total=0;
    foreach($polygons as $polygon){
        if(!is_array($polygon))return null;$rings=array();
        foreach($polygon as $ring){
            if(!is_array($ring)||count($ring)<4)return null;$points=array();
            foreach($ring as $point){if(++$total>3000||!is_array($point)||count($point)<2||!is_numeric($point[0])||!is_numeric($point[1])||!is_finite((float)$point[0])||!is_finite((float)$point[1])||abs((float)$point[0])>180||abs((float)$point[1])>90)return null;$points[]=array(round((float)$point[0],5),round((float)$point[1],5));}
            if($points[0]!==$points[count($points)-1])return null;$rings[]=$points;
        }
        if(!$rings)return null;$clean[]=$rings;
    }
    return $clean?array('type'=>$geometry['type'],'coordinates'=>$geometry['type']==='Polygon'?$clean[0]:$clean):null;
}
function fntm_queue_city_boundaries() {
    if(!current_user_can('manage_options')||!true||wp_doing_ajax()||get_option('fntm_city_queue_152'))return;
    $ids=get_posts(array('post_type'=>'fntm_place','post_status'=>'publish','numberposts'=>500,'fields'=>'ids'));
    foreach($ids as $id)fntm_queue_city_boundary($id);
    update_option('fntm_city_queue_152',1,false);
}
add_action('admin_init','fntm_queue_city_boundaries',30);
function fntm_queue_city_boundary($id) {
    if(get_post_status($id)!=='publish'||get_post_field('post_password',$id)!=='')return;
    $lat=get_post_meta($id,'_fntm_lat',true);$lng=get_post_meta($id,'_fntm_lng',true);$fingerprint=hash('sha256',$lat.'|'.$lng);
    $saved=get_post_meta($id,'_fntm_city_boundary',true);
    if(is_array($saved)&&($saved['coordinates_key']??'')===$fingerprint)return;
    if(!wp_next_scheduled('fntm_fetch_city_boundary',array((int)$id)))wp_schedule_single_event(time()+5,'fntm_fetch_city_boundary',array((int)$id));
}
add_action('save_post_fntm_place',function($id){if(!wp_is_post_revision($id)&&current_user_can('edit_post',$id))fntm_queue_city_boundary($id);},50);
function fntm_fetch_city_boundary($id) {
    if(!true)return;
    if(get_post_status($id)!=='publish'||get_post_field('post_password',$id)!=='')return;
    $lat=get_post_meta($id,'_fntm_lat',true);$lng=get_post_meta($id,'_fntm_lng',true);
    if(!is_numeric($lat)||!is_numeric($lng)||abs((float)$lat)>90||abs((float)$lng)>180)return;
    $lock='fntm_city_geocoder_lock';$until=get_option($lock);if(is_numeric($until)&&(int)$until<time())delete_option($lock);
    if(!add_option($lock,time()+20,'',false)){if(!wp_next_scheduled('fntm_fetch_city_boundary',array((int)$id)))wp_schedule_single_event(time()+25,'fntm_fetch_city_boundary',array((int)$id));return;}
    // Keep the lease after this request: the next queued lookup cannot exceed 1 req/s.
    $base=defined('CROLORD_MAP_GEOCODER_URL')?CROLORD_MAP_GEOCODER_URL:'https://nominatim.openstreetmap.org/reverse';
    $url=add_query_arg(array('lat'=>(float)$lat,'lon'=>(float)$lng,'format'=>'jsonv2','zoom'=>10,'layer'=>'address','polygon_geojson'=>1,'polygon_threshold'=>0.005),$base);
    if(strpos($url,'https://')!==0)return;
    $response=wp_safe_remote_get($url,array('timeout'=>12,'redirection'=>0,'sslverify'=>true,'limit_response_size'=>524288,'user-agent'=>'crolord-map/'.FNTM_VERSION.' ('.home_url('/').')'));
    if(is_wp_error($response)||wp_remote_retrieve_response_code($response)!==200)return;
    $body=json_decode(wp_remote_retrieve_body($response),true);$geometry=fntm_city_geometry($body['geojson']??null);
    if(!$geometry || !in_array($body['addresstype']??'',array('city','town','municipality','borough'),true))return;
    if((string)get_post_meta($id,'_fntm_lat',true)!==(string)$lat||(string)get_post_meta($id,'_fntm_lng',true)!==(string)$lng)return;
    if(get_post_status($id)!=='publish'||get_post_field('post_password',$id)!=='')return;
    update_post_meta($id,'_fntm_city_boundary',array('geometry'=>$geometry,'source'=>'OpenStreetMap / Nominatim','osm_type'=>sanitize_key($body['osm_type']??''),'osm_id'=>absint($body['osm_id']??0),'fetched_at'=>time(),'coordinates_key'=>hash('sha256',$lat.'|'.$lng)));
}
add_action('fntm_fetch_city_boundary','fntm_fetch_city_boundary');
function fntm_wayfarer_place_url($place_id) {
    if(function_exists('fieldnotes_section_url'))$url=fieldnotes_section_url('map');
    else {
        $pages=get_posts(array('post_type'=>'page','post_status'=>'publish','numberposts'=>1,'meta_key'=>'_wp_page_template','meta_value'=>'templates/page-map.php'));
        $url=$pages?get_permalink($pages[0]):home_url('/wayfarer/');
    }
    return $url.'#place-'.absint($place_id);
}
