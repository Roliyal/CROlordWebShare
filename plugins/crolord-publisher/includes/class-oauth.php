<?php
if (!defined('ABSPATH')) { exit; }

/** Compatibility shim. Direct X OAuth and token refresh were retired in 1.5.0.
 * Stored encrypted credentials remain untouched; no legacy entry point contacts X.
 */
final class Crolord_Publisher_OAuth {
    const APP_OPTION = 'crolord_publisher_x_app';
    const LOCK_OPTION = 'crolord_publisher_accounts_lock';
    public static function boot() {}
    public static function render() {}
    public static function locked($work) { return Crolord_Publisher_Store::locked($work); }
    public static function access_token() { return Crolord_Publisher_Platforms::retired(); }
}
