<?php

// Deterministic 2.0.0 EVM cursor recovery tests. These use a fake adapter so
// recent/history failures and exact block boundaries can be asserted without a
// network dependency.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['qa_recovery_options'] = array();

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
function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['qa_recovery_options']) ? $GLOBALS['qa_recovery_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null)
{
    $GLOBALS['qa_recovery_options'][$key] = $value;
    return true;
}

class QA_Recovery_WPDB
{
    public function prepare($query) { return $query; }
    public function get_var($query) { return 1; }
}
$GLOBALS['wpdb'] = new QA_Recovery_WPDB();

class JIULIU_CRYPTO_Settings
{
    public function get($key, $default = null)
    {
        if ('invoice_timeout' === $key) { return 15; }
        if ('trongrid_max_pages' === $key) { return 1; }
        if ('late_grace_hours' === $key) { return 24; }
        if ('monitor_closed_orders' === $key) { return 1; }
        return $default;
    }
}

class JIULIU_CRYPTO_DB
{
    public $pending = array();
    public $released = array();
    public $updated = array();
    public $events = array();

    public function settlement_tables_are_transactional() { return true; }
    public function pending_for_scan($hours, $limit, $monitor) { return $this->pending; }
    public function update_invoice($id, $data) { $this->updated[] = (int) $id; return true; }
    public function release_scanned_active_keys($ids, $hours) { $this->released = array_merge($this->released, $ids); return count($ids); }
    public function expire_due() { return true; }
    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0)
    {
        $this->events[] = array('event' => $event, 'context' => $context);
        return true;
    }
}

class JIULIU_CRYPTO_Rate {}
class JIULIU_CRYPTO_Trongrid {}

class JIULIU_CRYPTO_EVM
{
    public static $head = 100;
    public static $ranges = array();
    public static $call_count = 0;
    public static $fail_on_call = 0;
    private $route;

    public function __construct($route) { $this->route = $route; }
    public function get_confirmed_head($timeout)
    {
        return array(
            'latest_block' => self::$head + (int) $this->route['required_confirmations'],
            'confirmed_block' => self::$head,
            'confirmations' => (int) $this->route['required_confirmations'],
        );
    }
    public function locate_block_at_or_after($timestamp_ms, $upper_block, $timeout) { return 0; }
    public function get_transfers_by_block_range($receiver, $from, $to, $head, $timeout)
    {
        self::$call_count++;
        self::$ranges[] = array('from' => (int) $from, 'to' => (int) $to);
        if (self::$fail_on_call && self::$call_count === self::$fail_on_call) {
            return new WP_Error('qa_history_failure', 'simulated history RPC failure');
        }
        return array('transfers' => array(), 'isolated_blocks' => array());
    }
    public function sort_transfers_newest_first($left, $right) { return 0; }
}

class QA_Recovery_Routes
{
    private $route;
    public function __construct($route) { $this->route = $route; }
    public function get_by_id($id, $enabled_only = false) { return $this->route; }
}

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-invoices.php';

function qa_recovery_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_recovery_assert($condition, $message)
{
    if (!$condition) {
        qa_recovery_fail($message);
    }
}

function qa_recovery_route()
{
    return array(
        'id' => 'qa_recovery',
        'method' => 'ju_crypto_qa_recovery',
        'enabled' => 1,
        'adapter' => 'evm',
        'chain_key' => 'ethereum',
        'chain_id' => '1',
        'asset_symbol' => 'USDC',
        'asset_decimals' => 6,
        'network' => 'QA EVM',
        'contract_address' => '0x' . str_repeat('a', 40),
        'receive_address' => '0x' . str_repeat('b', 40),
        'fee_symbol' => 'ETH',
        'required_confirmations' => 1,
        'scan_block_chunk' => 4,
        'scan_max_blocks' => 40,
    );
}

function qa_recovery_invoice($id = 71)
{
    $route = qa_recovery_route();
    $created = time() - 48 * HOUR_IN_SECONDS;
    return (object) array(
        'id' => $id,
        'route_id' => $route['id'],
        'payment_method' => $route['method'],
        'adapter' => $route['adapter'],
        'chain_key' => $route['chain_key'],
        'chain_id' => $route['chain_id'],
        'asset_symbol' => $route['asset_symbol'],
        'asset_decimals' => $route['asset_decimals'],
        'network' => $route['network'],
        'contract_address' => $route['contract_address'],
        'receive_address' => $route['receive_address'],
        'fee_symbol' => $route['fee_symbol'],
        'required_confirmations' => 1,
        'quote_scope' => hash('sha256', 'qa-recovery-quote-scope'),
        'expected_raw' => '1234567',
        'created_at' => gmdate('Y-m-d H:i:s', $created),
        'expires_at' => gmdate('Y-m-d H:i:s', time() - 30 * HOUR_IN_SECONDS),
        'status' => 'expired',
        'active_key' => hash('sha256', 'qa-active-key'),
    );
}

function qa_recovery_service($db)
{
    $route = qa_recovery_route();
    return new JIULIU_CRYPTO_Invoices(
        new JIULIU_CRYPTO_Settings(),
        $db,
        new JIULIU_CRYPTO_Rate(),
        new JIULIU_CRYPTO_Trongrid(),
        new QA_Recovery_Routes($route)
    );
}

function qa_recovery_cursor_state($scope, $invoice, $forward, $history, $target)
{
    return array(
        'version' => 1,
        'scope' => $scope,
        'forward_next' => $forward,
        'history_next' => $history,
        'history_target' => $target,
        'earliest_ms' => (JIULIU_CRYPTO_Util::utc_timestamp_from_mysql($invoice->created_at) - 5) * 1000,
        'updated_at' => time() - 60,
    );
}

$db = new JIULIU_CRYPTO_DB();
$service = qa_recovery_service($db);
$invoice = qa_recovery_invoice();
$cursor = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'get_evm_cursor_transfers');
$cursor->setAccessible(true);

// A long outage leaves forward_next far behind the confirmed tip. The newest
// interval is scanned immediately and the skipped boundary is handed to the
// descending cursor. The next tick starts history at the exact adjacent block.
$gap_scope = hash('sha256', 'qa-forward-gap');
$gap_key = 'jiuliu_crypto_evm_cursor_' . $gap_scope;
$GLOBALS['qa_recovery_options'][$gap_key] = qa_recovery_cursor_state($gap_scope, $invoice, 80, 49, 50);
JIULIU_CRYPTO_EVM::$ranges = array();
JIULIU_CRYPTO_EVM::$call_count = 0;
JIULIU_CRYPTO_EVM::$fail_on_call = 0;
$first = $cursor->invoke($service, $gap_scope, array($invoice), 1, 5);
qa_recovery_assert(!is_wp_error($first), 'forward-gap recovery failed on the recent interval');
qa_recovery_assert(
    array(array('from' => 97, 'to' => 100)) === JIULIU_CRYPTO_EVM::$ranges,
    'forward-gap recovery did not prioritize the newest four blocks'
);
qa_recovery_assert(
    101 === $GLOBALS['qa_recovery_options'][$gap_key]['forward_next']
        && 96 === $GLOBALS['qa_recovery_options'][$gap_key]['history_next'],
    'the skipped outage interval was not handed to the history cursor'
);

JIULIU_CRYPTO_EVM::$ranges = array();
JIULIU_CRYPTO_EVM::$call_count = 0;
$second = $cursor->invoke($service, $gap_scope, array($invoice), 1, 5);
qa_recovery_assert(!is_wp_error($second), 'history handoff failed on the next cursor tick');
qa_recovery_assert(
    isset(JIULIU_CRYPTO_EVM::$ranges[1])
        && array('from' => 96, 'to' => 96) === JIULIU_CRYPTO_EVM::$ranges[1],
    'the first skipped block after the recent interval was not scanned by history'
);
qa_recovery_assert(
    95 === $GLOBALS['qa_recovery_options'][$gap_key]['history_next'],
    'history did not descend past the contiguous handoff block'
);

// A successful recent scan and failed history scan persist independently: the
// tip advances, while history remains exactly at its last verified block.
$error_scope = hash('sha256', 'qa-history-error');
$error_key = 'jiuliu_crypto_evm_cursor_' . $error_scope;
$GLOBALS['qa_recovery_options'][$error_key] = qa_recovery_cursor_state($error_scope, $invoice, 99, 60, 50);
JIULIU_CRYPTO_EVM::$ranges = array();
JIULIU_CRYPTO_EVM::$call_count = 0;
JIULIU_CRYPTO_EVM::$fail_on_call = 2;
$partial = $cursor->invoke($service, $error_scope, array($invoice), 1, 5);
qa_recovery_assert(!is_wp_error($partial), 'a history-only RPC failure discarded a successful recent scan');
$state = $GLOBALS['qa_recovery_options'][$error_key];
qa_recovery_assert(101 === $state['forward_next'], 'history failure prevented the successful recent cursor from advancing');
qa_recovery_assert(60 === $state['history_next'], 'history failure advanced an unverified history cursor');
$meta = new ReflectionProperty('JIULIU_CRYPTO_Invoices', 'last_evm_cursor_meta');
$meta->setAccessible(true);
$partial_meta = $meta->getValue($service);
qa_recovery_assert(!empty($partial_meta['history_error']) && !empty($partial_meta['backlog']), 'history failure was not exposed as release backlog');

// An invoice can be older than the late grace window while RPC history remains
// incomplete. scan_pending() must retain its exact-amount active key until the
// full invoice interval has actually been covered.
JIULIU_CRYPTO_EVM::$fail_on_call = 0;
$scope_method = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'evm_cursor_scope');
$scope_method->setAccessible(true);
$scan_scope = $scope_method->invoke($service, $invoice->quote_scope, $invoice->required_confirmations);
$scan_key = 'jiuliu_crypto_evm_cursor_' . $scan_scope;

$backlog_db = new JIULIU_CRYPTO_DB();
$backlog_db->pending = array($invoice);
$backlog_service = qa_recovery_service($backlog_db);
$GLOBALS['qa_recovery_options'][$scan_key] = qa_recovery_cursor_state($scan_scope, $invoice, 101, 60, 50);
JIULIU_CRYPTO_EVM::$ranges = array();
JIULIU_CRYPTO_EVM::$call_count = 0;
$stats = $backlog_service->scan_pending();
qa_recovery_assert(empty($backlog_db->released), 'an old exact-amount key was released before historical coverage completed');
qa_recovery_assert(!empty($stats['release_backlog']) && 1 === (int) $stats['history_backlog_groups'], 'incomplete historical coverage was not reported');

// Once history_next is below history_target, the same old invoice is eligible
// for a scoped release in that successful scan (the DB still enforces age and
// terminal-state predicates).
$covered_db = new JIULIU_CRYPTO_DB();
$covered_db->pending = array($invoice);
$covered_service = qa_recovery_service($covered_db);
$GLOBALS['qa_recovery_options'][$scan_key] = qa_recovery_cursor_state($scan_scope, $invoice, 101, 49, 50);
JIULIU_CRYPTO_EVM::$ranges = array();
JIULIU_CRYPTO_EVM::$call_count = 0;
$stats = $covered_service->scan_pending();
qa_recovery_assert(array($invoice->id) === $covered_db->released, 'a fully covered old invoice did not receive a scoped active-key release');
qa_recovery_assert(empty($stats['release_backlog']) && 1 === (int) $stats['active_keys_released'], 'completed coverage was still reported as backlog');

fwrite(STDOUT, "OK: EVM outage handoff, partial progress and coverage-gated release passed\n");
