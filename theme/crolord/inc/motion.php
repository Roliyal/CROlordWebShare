<?php
if (!defined('ABSPATH')) exit;
function fieldnotes_motion_assets() {
    $work = fieldnotes_boughs_context() === 'works';
    $about = is_page_template('templates/page-about.php');
    if ($work) wp_enqueue_style('fieldnotes-catalog', get_template_directory_uri() . '/assets/css/catalog.min.css', array('fieldnotes-collections'), FIELDNOTES_VERSION);
    wp_register_style('fieldnotes-motion', get_template_directory_uri() . '/assets/css/motion.min.css', array('fieldnotes-editorial'), FIELDNOTES_VERSION);
    wp_register_script('fieldnotes-motion', get_template_directory_uri() . '/assets/js/motion.min.js', array('fieldnotes-main'), FIELDNOTES_VERSION, true);
    if ($about) wp_enqueue_style('fieldnotes-motion');
    if ($work || $about) wp_enqueue_script('fieldnotes-motion');
}
add_action('wp_enqueue_scripts', 'fieldnotes_motion_assets', 55);
