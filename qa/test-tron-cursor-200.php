<?php

// TRON recent-first cursor: bounded recent discovery, descending historical
// coverage, outage-gap preservation and coverage-gated exact-tail release.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('JIULIU_CRYPTO_VERSION', '2.1.3');

$GLOBALS['qa_tron_options'] = array();

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code, $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value, $domain = null) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['qa_tron_options']) ? $GLOBALS['qa_tron_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['qa_tron_options'][$key] = $value; return true; }
function get_date_from_gmt($value, $format) { return $value; }

class QA_Tron_WPDB
{
    public function prepare($query) { return $query; }
    public function get_var($query) { return 1; }
}
$GLOBALS['wpdb'] = new QA_Tron_WPDB();

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-settings.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-db.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-rate.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-trongrid.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-invoices.php';

function qa_tron_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

class QA_Tron_Settings extends JIULIU_CRYPTO_Settings
{
    public function get($key, $default = null)
    {
        if ('invoice_timeout' === $key) { return 15; }
        if ('trongrid_max_pages' === $key) { return 3; }
        if ('late_grace_hours' === $key) { return 24; }
        if ('monitor_closed_orders' === $key) { return 1; }
        return $default;
    }
}

class QA_Tron_DB extends JIULIU_CRYPTO_DB
{
    public $events = array();
    public $scan_invoice = null;
    public $release_calls = 0;
    public function settlement_tables_are_transactional() { return true; }
    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true)
    {
        return $this->scan_invoice ? array($this->scan_invoice) : array();
    }
    public function update_invoice($id, $data) { return true; }
    public function release_scanned_active_keys($invoice_ids, $late_grace_hours = 24)
    {
        $this->release_calls++;
        return count((array) $invoice_ids);
    }
    public function expire_due() { return true; }
    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0)
    {
        $this->events[] = array('event' => $event, 'context' => $context);
        return true;
    }
}

class QA_Tron_Adapter extends JIULIU_CRYPTO_Trongrid
{
    public $calls = array();
    public $fail_recent = false;
    public $fail_history = false;
    public $emit_recent = true;
    public $txid;
    public function __construct($settings)
    {
        parent::__construct($settings);
        $this->txid = str_repeat('7', 64);
    }
    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15, $route = array(), $split_depth = 0)
    {
        $recent = (int) $max_timestamp >= (time() - 5) * 1000;
        $this->calls[] = array(
            'min' => (int) $min_timestamp,
            'max' => (int) $max_timestamp,
            'recent' => $recent,
        );
        if ($recent && $this->fail_recent) {
            return new WP_Error('qa_recent_failed', 'recent failed');
        }
        if (!$recent && $this->fail_history) {
            return new WP_Error('qa_history_failed', 'history failed');
        }
        if ($recent && $this->emit_recent) {
            return array(array(
                'transaction_id' => $this->txid,
                'value' => '1234567',
                'block_timestamp' => (time() - 30) * 1000,
            ));
        }
        return array();
    }
}

class QA_Tron_Routes
{
    private $route;
    public function __construct($route) { $this->route = $route; }
    public function get_by_id($id, $enabled_only = false) { return $this->route; }
}

$settings = new QA_Tron_Settings();
$db = new QA_Tron_DB();
$adapter = new QA_Tron_Adapter($settings);
$route = array(
    'id' => 'qa_tron', 'method' => 'ju_crypto_qa_tron', 'enabled' => 1,
    'adapter' => 'tron', 'chain_key' => 'tron', 'chain_id' => 'tron-mainnet',
    'asset_symbol' => 'USDT', 'asset_decimals' => 6, 'network' => 'TRON (TRC20)',
    'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
    'receive_address' => 'TLa2f6VPqDgRE67v1736s7bJ8Ray5wYjU7',
    'fee_symbol' => 'TRX', 'required_confirmations' => 1,
);
$scope = hash('sha256', 'qa-tron-scope');
$created = time() - 48 * HOUR_IN_SECONDS;
$invoice = (object) array(
    'id' => 31, 'route_id' => 'qa_tron', 'payment_method' => 'ju_crypto_qa_tron',
    'adapter' => 'tron', 'chain_key' => 'tron', 'chain_id' => 'tron-mainnet',
    'asset_symbol' => 'USDT', 'asset_decimals' => 6, 'network' => 'TRON (TRC20)',
    'contract_address' => $route['contract_address'], 'receive_address' => $route['receive_address'],
    'fee_symbol' => 'TRX', 'required_confirmations' => 1, 'quote_scope' => $scope,
    'created_at' => gmdate('Y-m-d H:i:s', $created),
    'expires_at' => gmdate('Y-m-d H:i:s', $created + 15 * MINUTE_IN_SECONDS),
    'status' => 'closed', 'expected_raw' => '9999999',
);
$service = new JIULIU_CRYPTO_Invoices(
    $settings,
    $db,
    new JIULIU_CRYPTO_Rate($settings),
    $adapter,
    new QA_Tron_Routes($route)
);
$cursor = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'get_tron_cursor_transfers');
$cursor->setAccessible(true);
$meta_property = new ReflectionProperty('JIULIU_CRYPTO_Invoices', 'last_tron_cursor_meta');
$meta_property->setAccessible(true);

$started_ms = time() * 1000;
$first = $cursor->invoke($service, $scope, array($invoice), 3, 5);
if (is_wp_error($first) || 1 !== count($first) || $adapter->txid !== $first[0]['transaction_id']) {
    qa_tron_fail('an old historical window starved the newest TRON transfer');
}
if (count($adapter->calls) < 2 || empty($adapter->calls[0]['recent'])) {
    qa_tron_fail('TRON cursor did not scan the recent window first');
}
if (($adapter->calls[0]['max'] - $adapter->calls[0]['min']) > 21 * MINUTE_IN_SECONDS * 1000) {
    qa_tron_fail('TRON recent scan expanded into the old full invoice window');
}
if (($adapter->calls[1]['max'] - $adapter->calls[1]['min']) > 15 * MINUTE_IN_SECONDS * 1000) {
    qa_tron_fail('TRON history scan was not bounded to one progressive slice');
}
$option_key = 'jiuliu_crypto_tron_cursor_' . $scope;
$first_state = $GLOBALS['qa_tron_options'][$option_key];
$first_meta = $meta_property->getValue($service);
if (empty($first_meta['backlog']) || $first_state['forward_next'] <= $started_ms) {
    qa_tron_fail('TRON forward/history cursor state was not persisted');
}

// A failed history slice does not discard successful recent discovery and does
// not advance the failed historical position.
$adapter->calls = array();
$adapter->fail_history = true;
$history_failed = $cursor->invoke($service, $scope, array($invoice), 3, 5);
$adapter->fail_history = false;
$after_history_failure = $GLOBALS['qa_tron_options'][$option_key];
$history_meta = $meta_property->getValue($service);
if (is_wp_error($history_failed)
    || $after_history_failure['history_next'] !== $first_state['history_next']
    || empty($history_meta['history_error'])
    || empty($history_meta['backlog'])) {
    qa_tron_fail('failed TRON history scan advanced or hid its backlog');
}

// A recent-range error is fail-closed: no part of the state is committed.
$before_recent_failure = $GLOBALS['qa_tron_options'][$option_key];
$adapter->fail_recent = true;
$recent_failed = $cursor->invoke($service, $scope, array($invoice), 3, 5);
$adapter->fail_recent = false;
if (!is_wp_error($recent_failed) || $before_recent_failure !== $GLOBALS['qa_tron_options'][$option_key]) {
    qa_tron_fail('failed TRON recent scan advanced persistent cursor state');
}

// Simulate a two-hour outage after historical coverage had completed. The gap
// is handed back to history before the recent tip advances.
$gap_state = $GLOBALS['qa_tron_options'][$option_key];
$gap_state['forward_next'] = (time() - 2 * HOUR_IN_SECONDS) * 1000;
$gap_state['history_next'] = $gap_state['history_target'] - 1;
$GLOBALS['qa_tron_options'][$option_key] = $gap_state;
$adapter->calls = array();
$gap_tick = $cursor->invoke($service, $scope, array($invoice), 3, 5);
$after_gap = $GLOBALS['qa_tron_options'][$option_key];
if (is_wp_error($gap_tick)
    || empty($meta_property->getValue($service)['backlog'])
    || $after_gap['history_next'] <= $gap_state['history_next']
    || ($adapter->calls[0]['max'] - $adapter->calls[0]['min']) > 21 * MINUTE_IN_SECONDS * 1000) {
    qa_tron_fail('TRON outage gap was lost or allowed to expand the recent query');
}

// Cron integration must expose backlog and withhold tail release until every
// historical slice has succeeded.
$adapter->emit_recent = false;
$db->scan_invoice = $invoice;
$stats = $service->scan_pending();
if (empty($stats['release_backlog'])
    || empty($stats['history_backlog_groups'])
    || 0 !== $db->release_calls) {
    qa_tron_fail('TRON historical backlog allowed exact-tail release');
}

fwrite(STDOUT, "OK: persistent recent-first TRON cursor passed\n");
