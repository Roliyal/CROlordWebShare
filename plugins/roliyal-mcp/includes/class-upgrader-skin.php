<?php
defined('ABSPATH') || exit;

/** Core bulk updater with a non-interactive skin: never flush HTML into JSON-RPC. */
final class Roliyal_MCP_Bulk_Skin extends Automatic_Upgrader_Skin {
    public $plugin_info = array();
    public $plugin_active = false;
    public $plugin_network_active = false;
    public function bulk_header() {}
    public function bulk_footer() {}
}
