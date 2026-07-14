<?php

// Regression coverage for the 1.0.2 security hardening. This file runs with
// a small WordPress shim and does not need a WordPress installation.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('JIULIU_USDT_VERSION', '1.0.2');
define('JIULIU_USDT_COINGECKO_API_KEY', 'constant-market-key');

$GLOBALS['qa_options'] = array();
$GLOBALS['qa_transients'] = array();
$GLOBALS['qa_filters'] = array();
$GLOBALS['qa_actions'] = array();
$GLOBALS['qa_routes'] = array();
$GLOBALS['qa_http_queue'] = array();
$GLOBALS['qa_http_calls'] = 0;
$GLOBALS['qa_scheduled'] = false;

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code, $message = '', $data = null) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
class WP_REST_Server { const READABLE = 'GET'; const CREATABLE = 'POST'; }
class WP_REST_Request
{
    private $headers;
    private $params;
    public function __construct($headers = array(), $params = array()) { $this->headers = $headers; $this->params = $params; }
    public function get_header($key) { return isset($this->headers[$key]) ? $this->headers[$key] : ''; }
    public function get_param($key) { return isset($this->params[$key]) ? $this->params[$key] : ''; }
}

function __($value) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_unslash($value) { return $value; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function wp_generate_password($length) { return str_repeat('x', $length); }
function get_option($key, $default = false) { return isset($GLOBALS['qa_options'][$key]) ? $GLOBALS['qa_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['qa_options'][$key] = $value; return true; }
function get_transient($key) { return isset($GLOBALS['qa_transients'][$key]) ? $GLOBALS['qa_transients'][$key] : false; }
function set_transient($key, $value, $expiration) { $GLOBALS['qa_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['qa_transients'][$key]); return true; }
function add_filter($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['qa_filters'][$tag][] = $callback; return true; }
function add_action($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['qa_actions'][$tag][] = $callback; return true; }
function apply_filters($tag, $value) { return $value; }
function wp_next_scheduled($event) { return $GLOBALS['qa_scheduled'] ? time() : false; }
function wp_schedule_event($time, $recurrence, $event) { $GLOBALS['qa_scheduled'] = true; return true; }
function wp_clear_scheduled_hook($event) { $GLOBALS['qa_scheduled'] = false; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['qa_routes'][$namespace . $route] = $args; }
function rest_ensure_response($value) { return $value; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_remote_get($url, $args) { $GLOBALS['qa_http_calls']++; return array_shift($GLOBALS['qa_http_queue']); }
function wp_remote_retrieve_response_code($response) { return isset($response['code']) ? $response['code'] : 0; }
function wp_remote_retrieve_header($response, $name) { return isset($response['headers'][$name]) ? $response['headers'][$name] : ''; }
function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }

require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-util.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-settings.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-trongrid.php';

class JIULIU_USDT_DB
{
    public function acquire_scan_lock() { return false; }
}
class JIULIU_USDT_Invoices {}

require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-cron.php';

function qa_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$settings = new JIULIU_USDT_Settings();
$settings->install_defaults();
$stored = $settings->all();
$old_token = $stored['cron_token'];
$new_token = $settings->rotate_cron_token();
if (is_wp_error($new_token) || $old_token === $new_token || strlen($new_token) < 32) {
    qa_fail('Cron token rotation did not replace the stored credential');
}
if ('constant-market-key' !== $settings->get('coingecko_api_key')) {
    qa_fail('CoinGecko wp-config constant did not override the stored setting');
}

$db = new JIULIU_USDT_DB();
$invoices = new JIULIU_USDT_Invoices();
$cron_a = new JIULIU_USDT_Cron($settings, $db, $invoices);
$cron_b = new JIULIU_USDT_Cron($settings, $db, $invoices);
if (1 !== count($GLOBALS['qa_filters']['cron_schedules'])) {
    qa_fail('cron_schedules filter was registered more than once');
}
$cron_a->register_rest_route();
$route = $GLOBALS['qa_routes']['jiuliu-usdt/v1/cron'];
if (WP_REST_Server::CREATABLE !== $route['methods']) {
    qa_fail('external Cron route is not POST-only');
}
$query_only = new WP_REST_Request(array(), array('token' => $new_token));
if (!is_wp_error($cron_a->rest_permission($query_only))) {
    qa_fail('external Cron accepted a URL query token');
}
$header_request = new WP_REST_Request(array('x-jiuliu-cron-token' => $new_token));
if (true !== $cron_a->rest_permission($header_request)) {
    qa_fail('external Cron rejected the valid header token');
}

// The configured cap must override a deeper caller request.
$stored = $settings->all();
$stored['receive_address'] = JIULIU_USDT_Settings::USDT_CONTRACT;
$stored['trongrid_max_pages'] = 2;
update_option(JIULIU_USDT_Settings::OPTION_NAME, $stored, false);
$settings = new JIULIU_USDT_Settings();
$chain = new JIULIU_USDT_Trongrid($settings);
$page = array(
    'code' => 200,
    'body' => json_encode(array('success' => true, 'data' => array_fill(0, 200, array()), 'meta' => array('fingerprint' => 'next'))),
);
$GLOBALS['qa_http_queue'] = array($page, $page, $page);
$result = $chain->get_transfers(JIULIU_USDT_Settings::USDT_CONTRACT, 0, 1000, 8);
if (is_wp_error($result) || 2 !== $GLOBALS['qa_http_calls']) {
    qa_fail('TronGrid scan page cap was not enforced');
}

// A 429 must create a shared backoff and suppress the next network call.
$GLOBALS['qa_http_queue'] = array(array('code' => 429, 'headers' => array('retry-after' => '30'), 'body' => ''));
$before = $GLOBALS['qa_http_calls'];
$limited = $chain->get_transfers(JIULIU_USDT_Settings::USDT_CONTRACT, 0, 1000, 1);
if (!is_wp_error($limited) || 'trongrid_rate_limited' !== $limited->get_error_code()) {
    qa_fail('TronGrid 429 did not return the expected error');
}
$backed_off = $chain->get_transfers(JIULIU_USDT_Settings::USDT_CONTRACT, 0, 1000, 1);
if (!is_wp_error($backed_off) || 'trongrid_backoff' !== $backed_off->get_error_code()) {
    qa_fail('TronGrid shared failure backoff was not enforced');
}
if ($before + 1 !== $GLOBALS['qa_http_calls']) {
    qa_fail('TronGrid backoff still issued a network request');
}

$admin_source = file_get_contents(__DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-admin.php');
$zibll_source = file_get_contents(__DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-zibll.php');
$preflight_start = strpos($zibll_source, 'public function preflight_check_pay');
$preflight_end = strpos($zibll_source, 'public function enqueue_assets', $preflight_start);
$preflight_source = substr($zibll_source, $preflight_start, $preflight_end - $preflight_start);
if (false === strpos($admin_source, 'confirm_uncertain_settlement') || false === strpos($admin_source, 'jiuliu_usdt_rotate_cron_token')) {
    qa_fail('administrator high-risk confirmation or Cron rotation control is missing');
}
if (false !== strpos($preflight_source, '$_REQUEST') || false === strpos($preflight_source, "'POST' !== \$method")) {
    qa_fail('public payment preflight is not strictly POST-only');
}

fwrite(STDOUT, "PASS: 1.0.2 Cron, polling and TronGrid security hardening\n");
