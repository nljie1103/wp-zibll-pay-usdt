<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

wp_clear_scheduled_hook('jiuliu_crypto_scan_event');
delete_transient('jiuliu_crypto_scan_lock');
