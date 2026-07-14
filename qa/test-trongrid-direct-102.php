<?php

// Standalone regression coverage for direct transaction verification in 1.0.2.

define('ABSPATH', __DIR__ . '/');
define('HOUR_IN_SECONDS', 3600);
define('JIULIU_USDT_VERSION', '1.0.2');

$GLOBALS['qa_transients'] = array();
$GLOBALS['qa_get_queue'] = array();
$GLOBALS['qa_post_queue'] = array();
$GLOBALS['qa_get_calls'] = array();
$GLOBALS['qa_post_calls'] = array();

class WP_Error
{
    private $code;
    private $message;

    public function __construct($code, $message = '')
    {
        $this->code = $code;
        $this->message = $message;
    }

    public function get_error_code()
    {
        return $this->code;
    }

    public function get_error_message()
    {
        return $this->message;
    }
}

function __($value, $domain = null) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_json_encode($value) { return json_encode($value); }
function apply_filters($tag, $value) { return $value; }
function add_query_arg($args, $url) { return $url . '?' . http_build_query($args); }
function get_transient($key) { return isset($GLOBALS['qa_transients'][$key]) ? $GLOBALS['qa_transients'][$key] : false; }
function set_transient($key, $value, $expiration) { $GLOBALS['qa_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['qa_transients'][$key]); return true; }
function wp_remote_get($url, $args)
{
    $GLOBALS['qa_get_calls'][] = array($url, $args);
    return array_shift($GLOBALS['qa_get_queue']);
}
function wp_remote_post($url, $args)
{
    $GLOBALS['qa_post_calls'][] = array($url, $args);
    return array_shift($GLOBALS['qa_post_queue']);
}
function wp_remote_retrieve_response_code($response) { return isset($response['code']) ? $response['code'] : 0; }
function wp_remote_retrieve_header($response, $name) { return isset($response['headers'][$name]) ? $response['headers'][$name] : ''; }
function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }

require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-util.php';

class JIULIU_USDT_Settings
{
    const USDT_CONTRACT = 'TXLAQ63Xg1NAzckPwKHvzw7CSEmLMEqcdj';

    private $values;

    public function __construct($values = array())
    {
        $this->values = $values;
    }

    public function get($key, $default = '')
    {
        return isset($this->values[$key]) ? $this->values[$key] : $default;
    }
}

require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-trongrid.php';

function qa_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_response($payload, $code = 200, $headers = array())
{
    return array('code' => $code, 'headers' => $headers, 'body' => json_encode($payload));
}

function qa_reset_http()
{
    $GLOBALS['qa_get_queue'] = array();
    $GLOBALS['qa_post_queue'] = array();
    $GLOBALS['qa_get_calls'] = array();
    $GLOBALS['qa_post_calls'] = array();
    $GLOBALS['qa_transients'] = array();
}

$settings = new JIULIU_USDT_Settings(array('trongrid_max_pages' => 10));
$chain = new JIULIU_USDT_Trongrid($settings);
$address = JIULIU_USDT_Settings::USDT_CONTRACT;
$decoded_address = JIULIU_USDT_Util::base58_decode($address);
$address_hex = '0x' . bin2hex(substr($decoded_address, 1, 20));
$txid = str_repeat('a', 64);
$timestamp = (int) round(microtime(true) * 1000) - 1000;
$receipt = array(
    'id' => $txid,
    'blockTimeStamp' => $timestamp,
    'receipt' => array('result' => 'SUCCESS'),
);
$event = array(
    'transaction_id' => $txid,
    'block_timestamp' => $timestamp,
    'event_name' => 'Transfer',
    'contract_address' => $address,
    'result' => array(
        'from' => '0x' . str_repeat('1', 40),
        'to' => $address_hex,
        'value' => '1250000',
    ),
);

// A successful direct lookup must POST the receipt request, GET confirmed
// events, compare Base58 with the 0x event address and clear old failures.
$GLOBALS['qa_transients'][JIULIU_USDT_Trongrid::FAILURE_TRANSIENT] = 3;
$GLOBALS['qa_post_queue'][] = qa_response($receipt);
$GLOBALS['qa_get_queue'][] = qa_response(array('success' => true, 'data' => array($event)));
$transfer = $chain->find_txid($address, $txid, $timestamp - 1000, $timestamp + 1000);
if (is_wp_error($transfer) || '1250000' !== $transfer['value']) {
    qa_fail('valid confirmed direct transaction was rejected');
}
if (1 !== count($GLOBALS['qa_post_calls']) || 1 !== count($GLOBALS['qa_get_calls'])) {
    qa_fail('direct verification did not use one receipt POST and one event GET');
}
if (false === strpos($GLOBALS['qa_post_calls'][0][0], '/walletsolidity/gettransactioninfobyid')) {
    qa_fail('receipt was not requested from walletsolidity');
}
if (false === strpos($GLOBALS['qa_get_calls'][0][0], 'only_confirmed=true')) {
    qa_fail('event request was not restricted to confirmed events');
}
if (isset($GLOBALS['qa_transients'][JIULIU_USDT_Trongrid::FAILURE_TRANSIENT])) {
    qa_fail('a fully successful direct lookup did not clear failure state');
}

// A non-successful receipt must be rejected before event lookup.
qa_reset_http();
$failed_receipt = $receipt;
$failed_receipt['receipt']['result'] = 'REVERT';
$GLOBALS['qa_post_queue'][] = qa_response($failed_receipt);
$failed = $chain->find_txid($address, $txid, $timestamp - 1000, $timestamp + 1000);
if (!is_wp_error($failed) || 'txid_not_successful' !== $failed->get_error_code() || count($GLOBALS['qa_get_calls'])) {
    qa_fail('non-SUCCESS receipt was not rejected before event lookup');
}

// Two otherwise valid transfers to the same receiver are ambiguous and must
// never be silently reduced to the first event.
qa_reset_http();
$GLOBALS['qa_post_queue'][] = qa_response($receipt);
$GLOBALS['qa_get_queue'][] = qa_response(array('success' => true, 'data' => array($event, $event)));
$ambiguous = $chain->find_txid($address, $txid, $timestamp - 1000, $timestamp + 1000);
if (!is_wp_error($ambiguous) || 'txid_ambiguous_transfer' !== $ambiguous->get_error_code()) {
    qa_fail('multiple matching transfer events were not rejected');
}

// Ambiguity must also be detected across event pages, not only within the
// first page returned by TronGrid.
qa_reset_http();
$unrelated = $event;
$unrelated['contract_address'] = '0x' . str_repeat('0', 40);
$first_events = array_fill(0, 200, $unrelated);
$first_events[0] = $event;
$GLOBALS['qa_post_queue'][] = qa_response($receipt);
$GLOBALS['qa_get_queue'][] = qa_response(array(
    'success' => true,
    'data' => $first_events,
    'meta' => array('fingerprint' => 'second-page'),
));
$GLOBALS['qa_get_queue'][] = qa_response(array('success' => true, 'data' => array($event)));
$paged_ambiguous = $chain->find_txid($address, $txid, $timestamp - 1000, $timestamp + 1000);
if (!is_wp_error($paged_ambiguous) || 'txid_ambiguous_transfer' !== $paged_ambiguous->get_error_code()) {
    qa_fail('matching transfers on different event pages were not rejected');
}
if (2 !== count($GLOBALS['qa_get_calls'])) {
    qa_fail('direct event verification did not follow the pagination fingerprint');
}

// Wrong contract and wrong recipient events must not be accepted.
qa_reset_http();
$wrong = $event;
$wrong['contract_address'] = '0x' . str_repeat('0', 40);
$GLOBALS['qa_post_queue'][] = qa_response($receipt);
$GLOBALS['qa_get_queue'][] = qa_response(array('success' => true, 'data' => array($wrong)));
$not_found = $chain->find_txid($address, $txid, $timestamp - 1000, $timestamp + 1000);
if (!is_wp_error($not_found) || 'txid_not_found' !== $not_found->get_error_code()) {
    qa_fail('event from a non-USDT contract was accepted');
}

// A successful first page must not erase the previous failure count if a later
// page fails; the second failure should increase the shared count to two.
qa_reset_http();
$GLOBALS['qa_transients'][JIULIU_USDT_Trongrid::FAILURE_TRANSIENT] = 1;
$full_page = array('success' => true, 'data' => array_fill(0, 200, array()), 'meta' => array('fingerprint' => 'next'));
$GLOBALS['qa_get_queue'][] = qa_response($full_page);
$GLOBALS['qa_get_queue'][] = array('code' => 500, 'headers' => array(), 'body' => '{}');
$paged = $chain->get_transfers($address, 0, $timestamp + 1000, 2);
if (!is_wp_error($paged) || 'trongrid_http_error' !== $paged->get_error_code()) {
    qa_fail('later pagination failure did not surface');
}
if (2 !== $GLOBALS['qa_transients'][JIULIU_USDT_Trongrid::FAILURE_TRANSIENT]) {
    qa_fail('intermediate page incorrectly cleared exponential failure state');
}

// Retry-After must understand both delta seconds and HTTP-date forms.
$parse_retry_after = new ReflectionMethod('JIULIU_USDT_Trongrid', 'parse_retry_after');
$parse_retry_after->setAccessible(true);
if (30 !== $parse_retry_after->invoke($chain, '30')) {
    qa_fail('numeric Retry-After was parsed incorrectly');
}
$date_delay = $parse_retry_after->invoke($chain, gmdate('D, d M Y H:i:s', time() + 90) . ' GMT');
if ($date_delay < 85 || $date_delay > 90) {
    qa_fail('HTTP-date Retry-After was parsed incorrectly');
}

fwrite(STDOUT, "PASS: TronGrid direct confirmed transaction verification\n");
