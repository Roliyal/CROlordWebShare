<?php
defined('WP_UNINSTALL_PLUGIN') || exit;
delete_option('roliyal_mcp_tokens');
// Keep articles, drafts, comments and retry identifiers. Never delete user content.
