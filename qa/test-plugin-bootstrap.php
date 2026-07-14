<?php

// Bootstrap smoke test: load and construct the complete plugin with a minimal
// WordPress API shim, without connecting to WordPress or a database.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['qa_hooks'] = array();
$GLOBALS['qa_filters'] = array();
$GLOBALS['qa_options'] = array();

function plugin_dir_path($file) { return dirname($file) . DIRECTORY_SEPARATOR; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/jiuliu-usdt-payment/'; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function register_activation_hook($file, $callback) { $GLOBALS['qa_hooks']['activation'][] = $callback; }
function register_deactivation_hook($file, $callback) { $GLOBALS['qa_hooks']['deactivation'][] = $callback; }
function add_action($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['qa_hooks'][$tag][] = $callback; return true; }
function add_filter($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['qa_filters'][$tag][] = $callback; return true; }
function wp_next_scheduled($event) { return time() + 30; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['qa_options']) ? $GLOBALS['qa_options'][$key] : $default; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function wp_generate_password($length) { return str_repeat('x', $length); }

require_once __DIR__ . '/../jiuliu-usdt-payment/jiuliu-usdt-payment.php';

$plugin = jiuliu_usdt_payment();
if (!($plugin instanceof JIULIU_USDT_Plugin)) {
    fwrite(STDERR, "FAIL: plugin singleton did not construct\n");
    exit(1);
}
if (!isset($GLOBALS['qa_hooks']['rest_api_init']) || !isset($GLOBALS['qa_hooks']['admin_menu'])) {
    fwrite(STDERR, "FAIL: expected runtime hooks were not registered\n");
    exit(1);
}

$plugin->zibll->register();
if (!isset($GLOBALS['qa_filters']['zibpay_payment_methods']) || !isset($GLOBALS['qa_filters']['zibpay_initiate_paysdk'])) {
    fwrite(STDERR, "FAIL: expected Zibll gateway filters were not registered\n");
    exit(1);
}
if (!isset($GLOBALS['qa_hooks']['order_closed']) || !isset($GLOBALS['qa_hooks']['payment_order_success'])) {
    fwrite(STDERR, "FAIL: expected Zibll close/success lifecycle hooks were not registered\n");
    exit(1);
}

fwrite(STDOUT, "PASS: complete plugin bootstrap and hook registration\n");
