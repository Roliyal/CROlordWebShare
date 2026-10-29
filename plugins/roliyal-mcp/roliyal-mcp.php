<?php
/**
 * Plugin Name: Roliyal MCP
 * Description: 连接 Echoes 与你的创作空间。
 * Version: 1.2.0
 * Author: issac
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL-2.0-or-later
 * Text Domain: roliyal-mcp
 */
defined('ABSPATH') || exit;
define('ROLIYAL_MCP_VERSION', '1.2.0');
require_once __DIR__ . '/includes/class-scopes.php';
require_once __DIR__ . '/includes/class-management.php';
require_once __DIR__ . '/includes/class-tools.php';
require_once __DIR__ . '/includes/class-server.php';
require_once __DIR__ . '/includes/class-admin.php';
add_action('plugins_loaded', function () {
    Roliyal_MCP_Server::boot();
    if (is_admin()) { Roliyal_MCP_Admin::boot(); }
});
