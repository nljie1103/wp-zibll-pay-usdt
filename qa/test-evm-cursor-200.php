<?php

// Persistent EVM cursor coverage: recent-first scans, historical backfill,
// no repeated genesis binary search, error-safe progress and dense isolation.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('JIULIU_CRYPTO_VERSION', '2.1.0');

$GLOBALS['qa_options'] = array();
$GLOBALS['qa_rpc_calls'] = array();
$GLOBALS['qa_logs'] = array();
$GLOBALS['qa_fail_logs'] = false;
$GLOBALS['qa_timeout_logs'] = false;

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
function wp_json_encode($value) { return json_encode($value); }
function apply_filters($tag, $value) { return $value; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['qa_options']) ? $GLOBALS['qa_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['qa_options'][$key] = $value; return true; }
function get_date_from_gmt($value, $format) { return $value; }
function wp_remote_retrieve_response_code($response) { return isset($response['code']) ? $response['code'] : 0; }
function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }

function qa_cursor_quantity($number) { return '0x' . dechex((int) $number); }
function qa_cursor_hash($number) { return '0x' . str_pad(dechex((int) $number + 1), 64, '0', STR_PAD_LEFT); }
function qa_cursor_topic($address) { return '0x' . str_repeat('0', 24) . substr(strtolower($address), 2); }
function qa_cursor_data($raw) { return '0x' . str_pad(dechex((int) $raw), 64, '0', STR_PAD_LEFT); }

function qa_cursor_log($txid, $block, $index, $contract, $receiver, $raw)
{
    return array(
        'address' => $contract,
        'topics' => array(
            JIULIU_CRYPTO_EVM::TRANSFER_TOPIC,
            qa_cursor_topic('0x' . str_repeat('c', 40)),
            qa_cursor_topic($receiver),
        ),
        'data' => qa_cursor_data($raw),
        'transactionHash' => '0x' . $txid,
        'blockNumber' => qa_cursor_quantity($block),
        'blockHash' => qa_cursor_hash($block),
        'logIndex' => qa_cursor_quantity($index),
        'removed' => false,
    );
}

function wp_remote_post($url, $args)
{
    $request = json_decode($args['body'], true);
    $GLOBALS['qa_rpc_calls'][] = $request;
    $method = $request['method'];
    if ('eth_chainId' === $method) {
        $result = '0x1';
    } elseif ('eth_blockNumber' === $method) {
        $result = '0x64';
    } elseif ('eth_getBlockByNumber' === $method) {
        $number = hexdec(substr($request['params'][0], 2));
        $result = array(
            'number' => qa_cursor_quantity($number),
            'timestamp' => qa_cursor_quantity(1000 + $number * 10),
            'hash' => qa_cursor_hash($number),
        );
    } elseif ('eth_getLogs' === $method) {
        if ($GLOBALS['qa_timeout_logs']) {
            return array('code' => 200, 'body' => json_encode(array(
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'error' => array('code' => -32000, 'message' => 'query timeout'),
            )));
        }
        if ($GLOBALS['qa_fail_logs']) {
            return array('code' => 200, 'body' => json_encode(array(
                'jsonrpc' => '2.0',
                'id' => $request['id'],
                'error' => array('code' => -32000, 'message' => 'temporary backend failure'),
            )));
        }
        $filter = $request['params'][0];
        $from = hexdec(substr($filter['fromBlock'], 2));
        $to = hexdec(substr($filter['toBlock'], 2));
        $result = array();
        foreach ($GLOBALS['qa_logs'] as $log) {
            $block = hexdec(substr($log['blockNumber'], 2));
            if ($block >= $from && $block <= $to) {
                $result[] = $log;
            }
        }
    } else {
        return new WP_Error('qa_unhandled_rpc', $method);
    }
    return array('code' => 200, 'body' => json_encode(array(
        'jsonrpc' => '2.0', 'id' => $request['id'], 'result' => $result,
    )));
}


function wp_safe_remote_post($url, $args)
{
    return wp_remote_post($url, $args);
}

class QA_Cursor_WPDB
{
    public function prepare($query) { return $query; }
    public function get_var($query) { return 1; }
}
$GLOBALS['wpdb'] = new QA_Cursor_WPDB();

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-settings.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-db.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-rate.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-trongrid.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-evm.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-invoices.php';

function qa_cursor_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

class QA_Cursor_Settings extends JIULIU_CRYPTO_Settings
{
    public function get($key, $default = null)
    {
        if ('invoice_timeout' === $key) { return 15; }
        return $default;
    }
}
class QA_Cursor_DB extends JIULIU_CRYPTO_DB
{
    public $events = array();
    public function settlement_tables_are_transactional() { return true; }
    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0)
    {
        $this->events[] = array('event' => $event, 'context' => $context);
        return true;
    }
}
class QA_Cursor_Routes
{
    private $route;
    public function __construct($route) { $this->route = $route; }
    public function get_by_id($id, $enabled_only = false) { return $this->route; }
}

$contract = '0x' . str_repeat('a', 40);
$receiver = '0x' . str_repeat('b', 40);
$route = array(
    'id' => 'qa_evm', 'method' => 'ju_crypto_qa_evm', 'enabled' => 1,
    'adapter' => 'evm', 'chain_key' => 'ethereum', 'chain_id' => '1',
    'asset_symbol' => 'USDC', 'asset_decimals' => 6,
    'network' => 'QA EVM', 'contract_address' => $contract,
    'receive_address' => $receiver, 'fee_symbol' => 'ETH',
    'required_confirmations' => 1, 'rpc_url' => 'https://rpc.example.test/v1',
    'rpc_headers' => array(), 'rpc_timeout' => 5,
    'scan_block_chunk' => 4, 'scan_max_blocks' => 40, 'scan_max_results' => 10,
);
$scope = hash('sha256', 'qa-scope');
$invoice = (object) array(
    'id' => 7, 'route_id' => 'qa_evm', 'payment_method' => 'ju_crypto_qa_evm',
    'adapter' => 'evm', 'chain_key' => 'ethereum', 'chain_id' => '1',
    'asset_symbol' => 'USDC', 'asset_decimals' => 6, 'network' => 'QA EVM',
    'contract_address' => $contract, 'receive_address' => $receiver,
    'fee_symbol' => 'ETH', 'required_confirmations' => 1,
    'quote_scope' => $scope, 'created_at' => gmdate('Y-m-d H:i:s', 1010),
    'status' => 'closed',
);

$settings = new QA_Cursor_Settings();
$db = new QA_Cursor_DB();
$rate = new JIULIU_CRYPTO_Rate($settings);
$trongrid = new JIULIU_CRYPTO_Trongrid($settings);
$service = new JIULIU_CRYPTO_Invoices($settings, $db, $rate, $trongrid, new QA_Cursor_Routes($route));
$cursor = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'get_evm_cursor_transfers');
$cursor->setAccessible(true);
$cursor_scope = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'evm_cursor_scope');
$cursor_scope->setAccessible(true);
if ($cursor_scope->invoke($service, $scope, 1) === $cursor_scope->invoke($service, $scope, 64)) {
    qa_cursor_fail('different EVM confirmation snapshots shared one cursor scope');
}

// Even though the invoice is decades old, the latest confirmed interval is
// scanned first and a new payment at block 99 is returned immediately.
$recent_txid = str_repeat('1', 64);
$GLOBALS['qa_logs'] = array(qa_cursor_log($recent_txid, 99, 0, $contract, $receiver, 1234567));
$first = $cursor->invoke($service, $scope, array($invoice), 1, 5);
if (is_wp_error($first) || 1 !== count($first) || $recent_txid !== $first[0]['transaction_id']) {
    qa_cursor_fail('an old invoice starved the latest confirmed payment scan');
}
$option_key = 'jiuliu_crypto_evm_cursor_' . $scope;
if (empty($GLOBALS['qa_options'][$option_key]['forward_next']) || 101 !== $GLOBALS['qa_options'][$option_key]['forward_next']) {
    qa_cursor_fail('the confirmed forward cursor was not persisted');
}
$cursor_meta = new ReflectionProperty('JIULIU_CRYPTO_Invoices', 'last_evm_cursor_meta');
$cursor_meta->setAccessible(true);
$first_meta = $cursor_meta->getValue($service);
if (empty($first_meta['backlog'])) {
    qa_cursor_fail('an unfinished historical interval was not reported as release backlog');
}

// A defensive guard rejects accidental mixed-finality groups even if a caller
// forgets to use the confirmation-specific grouping key.
$high_finality = clone $invoice;
$high_finality->id = 8;
$high_finality->required_confirmations = 64;
$mixed = $cursor->invoke($service, hash('sha256', 'mixed-policy'), array($invoice, $high_finality), 1, 5);
if (!is_wp_error($mixed) || 'evm_mixed_confirmation_policy' !== $mixed->get_error_code()) {
    qa_cursor_fail('a mixed EVM confirmation group was accepted');
}

// Settlement re-checks the immutable invoice finality snapshot immediately
// before any txid claim/database mutation.
$confirmation_invoice = clone $invoice;
$confirmation_invoice->required_confirmations = 12;
$confirmation_invoice->status = 'pending';
$confirmation_invoice->error_code = null;
$process = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'process_transfer');
$process->setAccessible(true);
$under_confirmed = $process->invoke($service, $confirmation_invoice, array(
    'transaction_id' => str_repeat('9', 64),
    'confirmations' => 11,
), false, 'auto', false);
if (!is_wp_error($under_confirmed) || 'txid_insufficient_confirmations' !== $under_confirmed->get_error_code()) {
    qa_cursor_fail('an under-confirmed EVM transfer reached settlement');
}

// A later tick uses persisted block positions. It may read the confirmed head,
// but must not repeat the genesis-to-head timestamp binary search.
$GLOBALS['qa_rpc_calls'] = array();
$second = $cursor->invoke($service, $scope, array($invoice), 1, 5);
if (is_wp_error($second)) {
    qa_cursor_fail('the second cursor tick failed');
}
foreach ($GLOBALS['qa_rpc_calls'] as $call) {
    if ('eth_getBlockByNumber' === $call['method'] && '0x0' === $call['params'][0]) {
        qa_cursor_fail('a persisted cursor repeated the genesis binary search');
    }
}

// A recent-range RPC failure must not advance either cursor.
$before = $GLOBALS['qa_options'][$option_key];
$GLOBALS['qa_fail_logs'] = true;
$failed = $cursor->invoke($service, $scope, array($invoice), 1, 5);
$GLOBALS['qa_fail_logs'] = false;
if (!is_wp_error($failed) || $before !== $GLOBALS['qa_options'][$option_key]) {
    qa_cursor_fail('a failed EVM scan advanced persistent cursor state');
}

// Dense ranges split recursively. A single over-limit block is isolated, but a
// valid transfer in the adjacent newer block still survives and can settle.
$dense_route = $route;
$dense_route['scan_max_results'] = 1;
$dense = new JIULIU_CRYPTO_EVM($dense_route);
$GLOBALS['qa_logs'] = array(
    qa_cursor_log(str_repeat('2', 64), 99, 0, $contract, $receiver, 1),
    qa_cursor_log(str_repeat('3', 64), 99, 1, $contract, $receiver, 2),
    qa_cursor_log(str_repeat('4', 64), 100, 0, $contract, $receiver, 3),
);
$head = $dense->get_confirmed_head(5);
$dense_result = is_wp_error($head) ? $head : $dense->get_transfers_by_block_range($receiver, 98, 100, $head, 5);
if (is_wp_error($dense_result)
    || 1 !== count($dense_result['transfers'])
    || str_repeat('4', 64) !== $dense_result['transfers'][0]['transaction_id']
    || empty($dense_result['isolated_blocks'])
    || 99 !== (int) $dense_result['isolated_blocks'][0]['block']) {
    qa_cursor_fail('dense-block isolation starved an adjacent valid transfer');
}

// Isolation also persists in the route cursor. A later clean tick must not
// relabel that historical gap as complete and release exact-amount tails.
$dense_scope = hash('sha256', 'dense-cursor-scope');
$dense_service = new JIULIU_CRYPTO_Invoices(
    $settings,
    $db,
    $rate,
    $trongrid,
    new QA_Cursor_Routes($dense_route)
);
$dense_tick = $cursor->invoke($dense_service, $dense_scope, array($invoice), 1, 5);
if (is_wp_error($dense_tick)) {
    qa_cursor_fail('dense cursor tick failed instead of isolating one block');
}
$dense_meta = $cursor_meta->getValue($dense_service);
if (empty($dense_meta['coverage_incomplete']) || empty($dense_meta['isolated_blocks'])) {
    qa_cursor_fail('an isolated dense block did not hold cursor coverage incomplete');
}
$GLOBALS['qa_logs'] = array();
$clean_tick = $cursor->invoke($dense_service, $dense_scope, array($invoice), 1, 5);
$clean_meta = $cursor_meta->getValue($dense_service);
if (is_wp_error($clean_tick) || empty($clean_meta['coverage_incomplete']) || empty($clean_meta['isolated_blocks'])) {
    qa_cursor_fail('a later clean tick forgot a persisted dense-block coverage hold');
}

// A provider that asks clients to split every interval cannot fan one Cron
// tick into thousands of RPC requests. Hitting the hard budget fails the whole
// range, so its caller leaves both persistent cursors unchanged.
$budget_route = $route;
$budget_route['scan_max_blocks'] = 200;
$budget_adapter = new JIULIU_CRYPTO_EVM($budget_route);
$budget_head = $budget_adapter->get_confirmed_head(5);
$GLOBALS['qa_rpc_calls'] = array();
$GLOBALS['qa_timeout_logs'] = true;
$budget_result = $budget_adapter->get_transfers_by_block_range($receiver, 0, 100, $budget_head, 5);
$GLOBALS['qa_timeout_logs'] = false;
$log_call_count = 0;
foreach ($GLOBALS['qa_rpc_calls'] as $call) {
    if ('eth_getLogs' === $call['method']) {
        $log_call_count++;
    }
}
if (!is_wp_error($budget_result)
    || 'evm_log_request_budget_exceeded' !== $budget_result->get_error_code()
    || JIULIU_CRYPTO_EVM::MAX_LOG_REQUESTS_PER_RANGE !== $log_call_count) {
    qa_cursor_fail('recursive EVM range splitting escaped its hard RPC budget');
}

fwrite(STDOUT, "OK: persistent EVM cursor and dense-block isolation passed\n");
