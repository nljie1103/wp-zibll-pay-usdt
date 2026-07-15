<?php

// Standalone strict EVM/ERC-20 adapter coverage for 2.1.1.

define('ABSPATH', __DIR__ . '/');
define('JIULIU_CRYPTO_VERSION', '2.1.3');

$GLOBALS['qa_rpc_queue'] = array();
$GLOBALS['qa_rpc_calls'] = array();
$GLOBALS['qa_rpc_handler'] = null;

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code, $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function __($value, $domain = null) { return $value; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function home_url($path = '/') { return 'https://example.test' . $path; }
function wp_json_encode($value) { return json_encode($value); }
function apply_filters($tag, $value) { return $value; }
function wp_remote_retrieve_response_code($response) { return isset($response['code']) ? $response['code'] : 0; }
function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }

function wp_remote_post($url, $args)
{
    $request = json_decode($args['body'], true);
    $GLOBALS['qa_rpc_calls'][] = array('url' => $url, 'args' => $args, 'request' => $request);

    if (is_callable($GLOBALS['qa_rpc_handler'])) {
        $value = call_user_func($GLOBALS['qa_rpc_handler'], $request, $args);
    } else {
        if (!$GLOBALS['qa_rpc_queue']) {
            return new WP_Error('qa_empty_queue', 'No mocked RPC response queued');
        }
        $value = array_shift($GLOBALS['qa_rpc_queue']);
    }
    if ($value instanceof WP_Error || (is_array($value) && isset($value['code']) && isset($value['body']))) {
        return $value;
    }
    if (is_array($value) && isset($value['qa_raw_body'])) {
        return array('code' => isset($value['qa_code']) ? $value['qa_code'] : 200, 'body' => $value['qa_raw_body']);
    }
    if (is_array($value) && isset($value['qa_error'])) {
        return array('code' => 200, 'body' => json_encode(array(
            'jsonrpc' => '2.0',
            'id' => $request['id'],
            'error' => $value['qa_error'],
        )));
    }
    return array('code' => 200, 'body' => json_encode(array(
        'jsonrpc' => '2.0',
        'id' => $request['id'],
        'result' => $value,
    )));
}

function wp_safe_remote_post($url, $args)
{
    return wp_remote_post($url, $args);
}

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-evm.php';

function qa_evm_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_evm_reset()
{
    $GLOBALS['qa_rpc_queue'] = array();
    $GLOBALS['qa_rpc_calls'] = array();
    $GLOBALS['qa_rpc_handler'] = null;
}

function qa_evm_expect_error($value, $code, $message)
{
    if (!is_wp_error($value) || $code !== $value->get_error_code()) {
        qa_evm_fail($message . ' (expected ' . $code . ')');
    }
}

function qa_evm_quantity($number)
{
    return '0x' . dechex((int) $number);
}

function qa_evm_topic($address)
{
    return '0x' . str_repeat('0', 24) . substr(strtolower($address), 2);
}

function qa_evm_data($raw)
{
    return '0x' . str_pad(dechex((int) $raw), 64, '0', STR_PAD_LEFT);
}

function qa_evm_block($number, $timestamp, $hash = null)
{
    return array(
        'number' => qa_evm_quantity($number),
        'timestamp' => qa_evm_quantity($timestamp),
        'hash' => $hash ? $hash : '0x' . str_pad(dechex($number + 1), 64, '0', STR_PAD_LEFT),
    );
}

function qa_evm_log($txid, $block_number, $block_hash, $contract, $receiver, $raw, $log_index = 0)
{
    return array(
        'address' => $contract,
        'topics' => array(
            JIULIU_CRYPTO_EVM::TRANSFER_TOPIC,
            qa_evm_topic('0x' . str_repeat('c', 40)),
            qa_evm_topic($receiver),
        ),
        'data' => qa_evm_data($raw),
        'transactionHash' => '0x' . $txid,
        'blockNumber' => qa_evm_quantity($block_number),
        'blockHash' => $block_hash,
        'logIndex' => qa_evm_quantity($log_index),
        'removed' => false,
    );
}

$contract = '0x' . str_repeat('a', 40);
$receiver = '0x' . str_repeat('b', 40);
$txid = str_repeat('1', 64);
$block_hash = '0x' . str_repeat('d', 64);
$now = time() - 30;
$timestamp_ms = $now * 1000;
$route = array(
    'rpc_url' => 'https://rpc.example.test/v1',
    'chain_id' => '1',
    'contract_address' => $contract,
    'receive_address' => $receiver,
    'decimals' => 6,
    'confirmations' => 12,
    'rpc_timeout' => 15,
    'scan_block_chunk' => 2,
    'scan_max_blocks' => 100,
    'scan_max_results' => 20,
);

$valid_log = qa_evm_log($txid, 100, $block_hash, $contract, $receiver, 1250000);
$receipt = array(
    'transactionHash' => '0x' . $txid,
    'status' => '0x1',
    'blockNumber' => '0x64',
    'blockHash' => $block_hash,
    'logs' => array($valid_log),
);
$valid_direct_queue = array('0x1', $receipt, '0x6f', qa_evm_block(100, $now, $block_hash));

// A receipt is accepted only after chain ID, success, canonical block,
// confirmation count, time, contract, recipient and raw amount all match.
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = $valid_direct_queue;
$evm = new JIULIU_CRYPTO_EVM($route);
$found = $evm->find_txid($receiver, '0x' . $txid, $timestamp_ms - 1000, $timestamp_ms + 1000, '1250000');
if (is_wp_error($found) || '1250000' !== $found['value'] || $txid !== $found['transaction_id'] || 12 !== $found['confirmations']) {
    qa_evm_fail('valid confirmed ERC-20 transaction was rejected');
}
$methods = array();
foreach ($GLOBALS['qa_rpc_calls'] as $call) {
    $methods[] = $call['request']['method'];
}
if ($methods !== array('eth_chainId', 'eth_getTransactionReceipt', 'eth_blockNumber', 'eth_getBlockByNumber')) {
    qa_evm_fail('direct verification did not use the strict RPC sequence');
}
if (0 !== $GLOBALS['qa_rpc_calls'][0]['args']['redirection'] || false === strpos($GLOBALS['qa_rpc_calls'][0]['args']['body'], 'eth_chainId')) {
    qa_evm_fail('RPC request transport hardening or JSON body was not applied');
}

// Invoice persistence uses required_confirmations/asset_decimals names. The
// adapter accepts those aliases so an immutable invoice snapshot is enforced.
$invoice_route = $route;
unset($invoice_route['confirmations'], $invoice_route['decimals']);
$invoice_route['required_confirmations'] = 12;
$invoice_route['asset_decimals'] = 8;
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = $valid_direct_queue;
$invoice_named = (new JIULIU_CRYPTO_EVM($invoice_route))->find_txid(
    $receiver,
    $txid,
    $timestamp_ms - 1000,
    $timestamp_ms + 1000,
    '1250000'
);
if (is_wp_error($invoice_named) || 8 !== $invoice_named['decimals'] || 12 !== $invoice_named['confirmations']) {
    qa_evm_fail('invoice route confirmation/decimal aliases were not enforced');
}

// Omitting expected_raw permits a valid unique transfer to be returned for an
// invoice-layer under/over-payment decision.
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = $valid_direct_queue;
$without_amount = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
if (is_wp_error($without_amount) || '1250000' !== $without_amount['value']) {
    qa_evm_fail('optional expected amount prevented invoice-layer review flow');
}

qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = $valid_direct_queue;
$wrong_amount = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000, '1250001');
qa_evm_expect_error($wrong_amount, 'txid_amount_mismatch', 'wrong base-unit amount was accepted');

// Chain identity is fail-closed and checked before any receipt lookup.
qa_evm_reset();
$GLOBALS['qa_rpc_queue'][] = '0x38';
$wrong_chain = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
qa_evm_expect_error($wrong_chain, 'evm_chain_id_mismatch', 'wrong EVM chain ID was accepted');
if (1 !== count($GLOBALS['qa_rpc_calls'])) {
    qa_evm_fail('chain ID mismatch did not stop before receipt lookup');
}

// Failed and insufficiently confirmed receipts never reach log acceptance.
qa_evm_reset();
$failed_receipt = $receipt;
$failed_receipt['status'] = '0x0';
$GLOBALS['qa_rpc_queue'] = array('0x1', $failed_receipt);
$failed = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
qa_evm_expect_error($failed, 'txid_not_successful', 'failed EVM receipt was accepted');

qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x1', $receipt, '0x6e'); // 11 confirmations.
$unconfirmed = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
qa_evm_expect_error($unconfirmed, 'txid_not_confirmed', 'insufficient confirmations were accepted');

// Receipt and canonical block hashes must agree.
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x1', $receipt, '0x6f', qa_evm_block(100, $now, '0x' . str_repeat('e', 64)));
$reorged = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
qa_evm_expect_error($reorged, 'evm_receipt_block_mismatch', 'receipt/canonical block mismatch was accepted');

// Wrong contract or recipient is unrelated, and two matching transfers are
// ambiguous even if one amount would happen to equal the invoice amount.
qa_evm_reset();
$wrong_contract_receipt = $receipt;
$wrong_contract_receipt['logs'][0]['address'] = '0x' . str_repeat('e', 40);
$GLOBALS['qa_rpc_queue'] = array('0x1', $wrong_contract_receipt, '0x6f', qa_evm_block(100, $now, $block_hash));
$unrelated = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
qa_evm_expect_error($unrelated, 'txid_not_found', 'wrong token contract was accepted');

qa_evm_reset();
$wrong_recipient_receipt = $receipt;
$wrong_recipient_receipt['logs'][0]['topics'][2] = qa_evm_topic('0x' . str_repeat('f', 40));
$GLOBALS['qa_rpc_queue'] = array('0x1', $wrong_recipient_receipt, '0x6f', qa_evm_block(100, $now, $block_hash));
$wrong_recipient = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000);
qa_evm_expect_error($wrong_recipient, 'txid_not_found', 'wrong transfer recipient was accepted');

qa_evm_reset();
$ambiguous_receipt = $receipt;
$second_log = $valid_log;
$second_log['data'] = qa_evm_data(999999);
$second_log['logIndex'] = '0x1';
$ambiguous_receipt['logs'][] = $second_log;
$GLOBALS['qa_rpc_queue'] = array('0x1', $ambiguous_receipt, '0x6f', qa_evm_block(100, $now, $block_hash));
$ambiguous = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000, '1250000');
qa_evm_expect_error($ambiguous, 'txid_ambiguous_transfer', 'multiple matching transfers were reduced to one');

qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = $valid_direct_queue;
$outside = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms + 1000, $timestamp_ms + 2000);
qa_evm_expect_error($outside, 'txid_outside_window', 'transaction outside payment window was accepted');

// Full-width uint256 values must be compared without float or platform-int
// conversion.
$uint256_max = '115792089237316195423570985008687907853269984665640564039457584007913129639935';
$huge_log = $valid_log;
$huge_log['data'] = '0x' . str_repeat('f', 64);
$huge_receipt = $receipt;
$huge_receipt['logs'] = array($huge_log);
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x1', $huge_receipt, '0x6f', qa_evm_block(100, $now, $block_hash));
$huge = (new JIULIU_CRYPTO_EVM($route))->find_txid($receiver, $txid, $timestamp_ms - 1000, $timestamp_ms + 1000, $uint256_max);
if (is_wp_error($huge) || $uint256_max !== $huge['value']) {
    qa_evm_fail('uint256 base-unit amount lost precision');
}

// eth_getLogs scanning uses binary-searched time bounds, bounded chunks,
// strict recipient topic and canonical block checks.
$scan_route = $route;
$scan_route['confirmations'] = 1;
$scan_genesis = time() - 1000;
$scan_tx_a = str_repeat('2', 64);
$scan_tx_b = str_repeat('3', 64);
qa_evm_reset();
$GLOBALS['qa_rpc_handler'] = function ($request) use ($contract, $receiver, $scan_genesis, $scan_tx_a, $scan_tx_b) {
    $method = $request['method'];
    if ('eth_chainId' === $method) {
        return '0x1';
    }
    if ('eth_blockNumber' === $method) {
        return '0x14'; // 20.
    }
    if ('eth_getBlockByNumber' === $method) {
        $number = hexdec(substr($request['params'][0], 2));
        return qa_evm_block($number, $scan_genesis + $number * 10);
    }
    if ('eth_getLogs' === $method) {
        $filter = $request['params'][0];
        if ($filter['address'] !== $contract || $filter['topics'][2] !== qa_evm_topic($receiver)) {
            return array('qa_error' => array('code' => -32602, 'message' => 'bad strict filter'));
        }
        $from = hexdec(substr($filter['fromBlock'], 2));
        $to = hexdec(substr($filter['toBlock'], 2));
        $logs = array();
        foreach (array(11 => $scan_tx_a, 13 => $scan_tx_b) as $block_number => $hash) {
            if ($block_number >= $from && $block_number <= $to) {
                $block = qa_evm_block($block_number, $scan_genesis + $block_number * 10);
                $logs[] = qa_evm_log($hash, $block_number, $block['hash'], $contract, $receiver, 1000000 + $block_number);
            }
        }
        return $logs;
    }
    return array('qa_error' => array('code' => -32601, 'message' => 'unexpected method'));
};
$scan_min = ($scan_genesis + 100) * 1000;
$scan_max = ($scan_genesis + 139) * 1000;
$scanned = (new JIULIU_CRYPTO_EVM($scan_route))->get_transfers($receiver, $scan_min, $scan_max, 3, 8);
if (is_wp_error($scanned) || 2 !== count($scanned) || $scan_tx_b !== $scanned[0]['transaction_id'] || $scan_tx_a !== $scanned[1]['transaction_id']) {
    qa_evm_fail('bounded eth_getLogs scan did not return strict transfers newest first');
}
$log_calls = array();
foreach ($GLOBALS['qa_rpc_calls'] as $call) {
    if ('eth_getLogs' === $call['request']['method']) {
        $log_calls[] = $call;
    }
}
if (2 !== count($log_calls)
    || '0xa' !== $log_calls[0]['request']['params'][0]['fromBlock']
    || '0xb' !== $log_calls[0]['request']['params'][0]['toBlock']
    || '0xc' !== $log_calls[1]['request']['params'][0]['fromBlock']
    || '0xd' !== $log_calls[1]['request']['params'][0]['toBlock']) {
    qa_evm_fail('eth_getLogs time range was not split into configured block chunks');
}

// A window that cannot be completely scanned under the configured page cap
// fails diagnostically instead of silently returning a partial history.
qa_evm_reset();
$GLOBALS['qa_rpc_handler'] = function ($request) use ($scan_genesis) {
    if ('eth_chainId' === $request['method']) { return '0x1'; }
    if ('eth_blockNumber' === $request['method']) { return '0x14'; }
    if ('eth_getBlockByNumber' === $request['method']) {
        $number = hexdec(substr($request['params'][0], 2));
        return qa_evm_block($number, $scan_genesis + $number * 10);
    }
    return array();
};
$too_wide = (new JIULIU_CRYPTO_EVM($scan_route))->get_transfers(
    $receiver,
    ($scan_genesis + 100) * 1000,
    ($scan_genesis + 169) * 1000,
    3,
    8
);
qa_evm_expect_error($too_wide, 'evm_scan_window_too_large', 'oversized block window was silently truncated');

// Same-transaction matching logs found during automatic scanning are isolated
// to that transaction. They must not let an attacker suppress unrelated valid
// receipts on the same route; direct find_txid above remains fail-closed.
qa_evm_reset();
$GLOBALS['qa_rpc_handler'] = function ($request) use ($contract, $receiver, $scan_genesis, $scan_tx_a, $scan_tx_b) {
    if ('eth_chainId' === $request['method']) { return '0x1'; }
    if ('eth_blockNumber' === $request['method']) { return '0x14'; }
    if ('eth_getBlockByNumber' === $request['method']) {
        $number = hexdec(substr($request['params'][0], 2));
        return qa_evm_block($number, $scan_genesis + $number * 10);
    }
    if ('eth_getLogs' === $request['method']) {
        $filter = $request['params'][0];
        $from = hexdec(substr($filter['fromBlock'], 2));
        $to = hexdec(substr($filter['toBlock'], 2));
        $logs = array();
        if (11 >= $from && 11 <= $to) {
            $block = qa_evm_block(11, $scan_genesis + 110);
            $logs[] = qa_evm_log($scan_tx_a, 11, $block['hash'], $contract, $receiver, 1000011, 0);
            $logs[] = qa_evm_log($scan_tx_a, 11, $block['hash'], $contract, $receiver, 1000012, 1);
        }
        if (13 >= $from && 13 <= $to) {
            $block = qa_evm_block(13, $scan_genesis + 130);
            $logs[] = qa_evm_log($scan_tx_b, 13, $block['hash'], $contract, $receiver, 1000013, 0);
        }
        return $logs;
    }
    return array();
};
$scan_ambiguous = (new JIULIU_CRYPTO_EVM($scan_route))->get_transfers($receiver, $scan_min, $scan_max, 3, 8);
if (is_wp_error($scan_ambiguous)
    || 1 !== count($scan_ambiguous)
    || $scan_tx_b !== $scan_ambiguous[0]['transaction_id']) {
    qa_evm_fail('scan did not isolate an ambiguous transaction while preserving an unrelated valid transfer');
}

// Connection test verifies chain identity, calls ERC-20 decimals(), and probes
// eth_getLogs so wrong contracts/metadata and incapable providers fail before
// an invoice is created.
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array(
    '0x1',
    qa_evm_data(6),
    '0x64',
    array('qa_error' => array('code' => -32601, 'message' => "eth_getLogs\n disabled")),
);
$unsupported = (new JIULIU_CRYPTO_EVM($route))->test_connection();
qa_evm_expect_error($unsupported, 'evm_rpc_remote_error', 'connection test ignored disabled eth_getLogs');
if (false !== strpos($unsupported->get_error_message(), "\n")) {
    qa_evm_fail('remote RPC diagnostic was not sanitized');
}

qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x1', qa_evm_data(6), '0x64', array());
$connection = (new JIULIU_CRYPTO_EVM($route))->test_connection();
if (is_wp_error($connection)
    || empty($connection['eth_getlogs'])
    || empty($connection['eth_call'])
    || 6 !== $connection['token_decimals']
    || 100 !== $connection['latest_block']) {
    qa_evm_fail('valid RPC endpoint failed the connection capability test');
}
$connection_methods = array();
foreach ($GLOBALS['qa_rpc_calls'] as $call) {
    $connection_methods[] = $call['request']['method'];
}
if ($connection_methods !== array('eth_chainId', 'eth_call', 'eth_blockNumber', 'eth_getLogs')) {
    qa_evm_fail('connection test did not use the strict metadata/capability sequence');
}
$decimals_call = $GLOBALS['qa_rpc_calls'][1]['request']['params'];
if ('latest' !== $decimals_call[1]
    || $contract !== $decimals_call[0]['to']
    || '0x313ce567' !== $decimals_call[0]['data']) {
    qa_evm_fail('connection test did not call decimals() on the configured token contract');
}

qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x1', qa_evm_data(18));
$wrong_decimals = (new JIULIU_CRYPTO_EVM($route))->test_connection();
qa_evm_expect_error($wrong_decimals, 'evm_token_decimals_mismatch', 'on-chain decimal mismatch was accepted');
if (2 !== count($GLOBALS['qa_rpc_calls'])) {
    qa_evm_fail('decimal mismatch did not stop before block/log capability probes');
}

qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x1', '0x06');
$malformed_decimals = (new JIULIU_CRYPTO_EVM($route))->test_connection();
qa_evm_expect_error($malformed_decimals, 'evm_invalid_token_decimals_response', 'non-ABI decimals response was accepted');

// BSC's widely used Binance-Peg stablecoins use 18 decimals. Verify that the
// adapter keeps the full metadata rather than silently falling back to six.
$bsc_route = $route;
$bsc_route['chain_id'] = '56';
$bsc_route['decimals'] = 18;
qa_evm_reset();
$GLOBALS['qa_rpc_queue'] = array('0x38', qa_evm_data(18), '0x64', array());
$bsc_connection = (new JIULIU_CRYPTO_EVM($bsc_route))->test_connection();
if (is_wp_error($bsc_connection) || 18 !== $bsc_connection['token_decimals']) {
    qa_evm_fail('valid 18-decimal BSC token metadata was rejected');
}

// Transport/protocol failures expose stable diagnostic error codes.
$protocol_cases = array(
    array(new WP_Error('timeout', 'timed out'), 'evm_rpc_network_error'),
    array(array('code' => 429, 'body' => '{}'), 'evm_rpc_rate_limited'),
    array(array('code' => 503, 'body' => '{}'), 'evm_rpc_http_error'),
    array(array('qa_raw_body' => '{bad json'), 'evm_rpc_json_error'),
    array(array('qa_raw_body' => json_encode(array('jsonrpc' => '2.0', 'id' => 999, 'result' => '0x1'))), 'evm_rpc_id_mismatch'),
);
foreach ($protocol_cases as $case) {
    qa_evm_reset();
    $GLOBALS['qa_rpc_queue'][] = $case[0];
    $result = (new JIULIU_CRYPTO_EVM($route))->test_connection();
    qa_evm_expect_error($result, $case[1], 'RPC protocol failure lacked a stable diagnostic code');
}

$small_response_route = $route;
$small_response_route['rpc_max_response_bytes'] = 1024;
qa_evm_reset();
$GLOBALS['qa_rpc_queue'][] = array('qa_raw_body' => str_repeat('x', 1025));
$too_large = (new JIULIU_CRYPTO_EVM($small_response_route))->test_connection();
qa_evm_expect_error($too_large, 'evm_rpc_response_too_large', 'oversized RPC response was decoded');

$invalid_route = $route;
$invalid_route['confirmations'] = 0;
qa_evm_reset();
$invalid_config = (new JIULIU_CRYPTO_EVM($invalid_route))->test_connection();
qa_evm_expect_error($invalid_config, 'invalid_evm_confirmations', 'invalid route confirmation count was silently clamped');
if (count($GLOBALS['qa_rpc_calls'])) {
    qa_evm_fail('invalid EVM route reached the RPC endpoint');
}

fwrite(STDOUT, "PASS: strict EVM/ERC-20 adapter verification and bounded scanning\n");
