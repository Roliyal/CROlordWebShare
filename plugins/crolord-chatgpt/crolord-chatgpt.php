<?php
/**
 * Plugin Name: Echoes
 * Description: 让思绪展开，让灵感有所回应。
 * Version: 1.17.3
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: issac
 * License: GPL-2.0-or-later
 * Update URI: https://github.com/Roliyal/CROlordWebShare
 * Text Domain: crolord-chatgpt
 */
if (!defined('ABSPATH')) { exit; }

// New package identity, same data keys. Avoid loading both generations together.
$crolord_legacy_plugin = 'fieldnotes-chatgpt/fieldnotes-chatgpt.php';
$crolord_legacy_active = in_array($crolord_legacy_plugin, (array) get_option('active_plugins', array()), true);
if (is_multisite()) $crolord_legacy_active = $crolord_legacy_active || isset(((array) get_site_option('active_sitewide_plugins', array()))[$crolord_legacy_plugin]);
if ($crolord_legacy_active) {
    add_action('admin_notices', function () { echo '<div class="notice notice-info"><p>crolord：请先停用旧版 fieldnotes-chatgpt，再刷新页面。原设置与内容会继续沿用，无需删除旧数据。</p></div>'; });
    return;
}

define('FIELDNOTES_CHATGPT_VERSION', '1.17.3');
define('FIELDNOTES_CHATGPT_FILE', __FILE__);
define('FIELDNOTES_CHATGPT_DIR', plugin_dir_path(__FILE__));
define('FIELDNOTES_CHATGPT_URL', plugin_dir_url(__FILE__));


require_once FIELDNOTES_CHATGPT_DIR . 'includes/class-crolord-echoes-account.php';
require_once FIELDNOTES_CHATGPT_DIR . 'includes/class-fieldnotes-chatgpt.php';
Fieldnotes_ChatGPT::instance();

register_activation_hook(__FILE__, array('Fieldnotes_ChatGPT', 'activate'));

require_once FIELDNOTES_CHATGPT_DIR.'includes/class-crolord-echoes-updater.php';
