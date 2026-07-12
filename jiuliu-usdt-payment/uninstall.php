<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// Financial records and settings are intentionally retained on uninstall.
// This prevents accidental loss of transaction evidence. Administrators may
// remove the plugin tables manually after completing their accounting export.
wp_clear_scheduled_hook('jiuliu_usdt_scan_event');
delete_transient('jiuliu_usdt_scan_lock');

