<?php

// Self-contained tests for the plugin's pure/core behavior. This deliberately
// supplies a tiny WordPress shim and never loads WordPress or a database.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
define('JIULIU_USDT_VERSION', 'qa');

$GLOBALS['qa_options'] = array();
$GLOBALS['qa_transients'] = array();
$GLOBALS['qa_remote_queue'] = array();
$GLOBALS['qa_remote_requests'] = array();
$GLOBALS['qa_mails'] = array();

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code = '', $message = '', $data = null)
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text) { return $text; }
function esc_html__($text) { return $text; }
function esc_attr__($text) { return $text; }
function esc_html($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function esc_attr($text) { return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'); }
function wp_unslash($value) { return $value; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['qa_options']) ? $GLOBALS['qa_options'][$key] : $default; }
function update_option($key, $value) { $GLOBALS['qa_options'][$key] = $value; return true; }
function get_transient($key) { return array_key_exists($key, $GLOBALS['qa_transients']) ? $GLOBALS['qa_transients'][$key] : false; }
function set_transient($key, $value) { $GLOBALS['qa_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['qa_transients'][$key]); return true; }
function wp_generate_password($length) { return str_repeat('x', $length); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function current_time($type) { return '2026-07-12 12:00:00'; }
function get_date_from_gmt($value) { return $value; }
function apply_filters($tag, $value) { return $value; }
function do_action($tag, $value = null) { return null; }
function wp_rand($min, $max) { return min($max, max($min, 1234)); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function add_query_arg($args, $url) { return $url . (false === strpos($url, '?') ? '?' : '&') . http_build_query($args); }
function wp_remote_get($url, $args = array())
{
    $GLOBALS['qa_remote_requests'][] = array('url' => $url, 'args' => $args);
    if (!$GLOBALS['qa_remote_queue']) {
        return new WP_Error('empty_mock_queue', 'No mocked response queued');
    }
    return array_shift($GLOBALS['qa_remote_queue']);
}
function wp_remote_retrieve_response_code($response) { return isset($response['code']) ? $response['code'] : 0; }
function wp_remote_retrieve_body($response) { return isset($response['body']) ? $response['body'] : ''; }
function wp_create_nonce($action) { return 'nonce-' . $action; }
function is_email($email) { return false !== filter_var($email, FILTER_VALIDATE_EMAIL); }
function get_bloginfo($key) { return 'QA Site'; }
function wp_specialchars_decode($value) { return html_entity_decode($value, ENT_QUOTES, 'UTF-8'); }
function wp_mail($to, $subject, $message) { $GLOBALS['qa_mails'][] = compact('to', 'subject', 'message'); return true; }
function get_userdata($id) { return false; }
function get_current_user_id()
{
    return isset($GLOBALS['qa_current_user_id']) ? absint($GLOBALS['qa_current_user_id']) : 0;
}
function zib_get_qrcode_base64($payload) { return 'data:image/png;base64,' . base64_encode($payload); }

require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-util.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-settings.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-db.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-rate.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-trongrid.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-invoices.php';

$tests = 0;
$failures = array();

function qa_assert($condition, $message)
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function qa_same($expected, $actual, $message)
{
    qa_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

// Utility and TRON Base58Check tests.
$contract = JIULIU_USDT_Settings::USDT_CONTRACT;
qa_assert(JIULIU_USDT_Util::is_valid_tron_address($contract), 'Official USDT contract must be a valid TRON address');
qa_assert(!JIULIU_USDT_Util::is_valid_tron_address(substr($contract, 0, -1) . 'u'), 'Tampered TRON checksum must fail');
qa_assert(!JIULIU_USDT_Util::is_valid_tron_address('T0' . str_repeat('a', 32)), 'Invalid Base58 character must fail');
qa_same('10000001', JIULIU_USDT_Util::decimal_to_raw('10.000001', 6), 'decimal_to_raw must preserve six decimals');
qa_same('10.000001', JIULIU_USDT_Util::raw_to_decimal('10000001', 6), 'raw_to_decimal must preserve six decimals');
qa_same('0.000001', JIULIU_USDT_Util::raw_to_decimal('1', 6), 'raw_to_decimal must left-pad fractional values');
qa_same('123', JIULIU_USDT_Util::normalize_raw('000123'), 'normalize_raw must remove leading zeros');
qa_assert(JIULIU_USDT_Util::is_valid_txid(str_repeat('a', 64)), '64 hex characters must be a valid txid');
qa_assert(!JIULIU_USDT_Util::is_valid_txid(str_repeat('g', 64)), 'Non-hex txid must fail');

// Settings validation and effective enablement.
$settings = new JIULIU_USDT_Settings();
$settings->install_defaults();
$valid_input = array(
    'enabled' => 1,
    'receive_address' => $contract,
    'rate_mode' => 'fixed',
    'fixed_rate' => '7.20',
    'rate_markup' => '0',
    'invoice_timeout' => 15,
    'minimum_local_amount' => 1,
    'maximum_local_amount' => 1000,
    'late_grace_hours' => 24,
    'log_retention_days' => 90,
    'cron_token' => str_repeat('a', 64),
);
$updated = $settings->update($valid_input);
qa_assert(!is_wp_error($updated), 'Valid settings must save');
qa_assert($settings->is_enabled(), 'Gateway must enable with valid TRON address');
$invalid = $valid_input;
$invalid['receive_address'] = 'T' . str_repeat('x', 33);
qa_assert(is_wp_error($settings->update($invalid)), 'Invalid TRON address must be rejected');
$invalid = $valid_input;
$invalid['fixed_rate'] = 0;
qa_same('invalid_rate', $settings->update($invalid)->get_error_code(), 'Zero fixed rate must be rejected');
$invalid = $valid_input;
$invalid['maximum_local_amount'] = 0.5;
qa_same('invalid_limits', $settings->update($invalid)->get_error_code(), 'Maximum below minimum must be rejected');

// Exchange-rate success, cache and fallback paths.
$auto = $valid_input;
$auto['rate_mode'] = 'auto';
$settings->update($auto);
$GLOBALS['qa_remote_queue'][] = array(
    'code' => 200,
    'body' => json_encode(array('tether' => array('cny' => 7.31, 'last_updated_at' => time()))),
);
$rate = new JIULIU_USDT_Rate($settings);
$rate_result = $rate->get_rate(true);
qa_same(7.31, $rate_result['rate'], 'Automatic CNY rate must parse CoinGecko response');
qa_same('coingecko', $rate_result['source'], 'Automatic rate source must be recorded');
$GLOBALS['qa_remote_queue'][] = array('code' => 500, 'body' => '');
$fallback = $rate->get_rate(true);
qa_assert($fallback['fallback'], 'Automatic rate HTTP failure must fall back');
qa_same(7.2, $fallback['rate'], 'Fallback must use configured fixed rate');

// TronGrid response normalization and mandatory transfer fields.
$trongrid = new JIULIU_USDT_Trongrid($settings);
$normalize = new ReflectionMethod('JIULIU_USDT_Trongrid', 'normalize_transfer');
$normalize->setAccessible(true);
$transfer = array(
    'transaction_id' => str_repeat('b', 64),
    'from' => 'TFromAddress',
    'to' => $contract,
    'value' => '10001234',
    'block_timestamp' => 1234567890000,
    'type' => 'Transfer',
    'token_info' => array('address' => $contract, 'decimals' => 6),
);
$normalized = $normalize->invoke($trongrid, $transfer, $contract);
qa_assert(is_array($normalized), 'Valid confirmed transfer shape must normalize');
qa_same('10001234', $normalized['value'], 'Normalized transfer must retain raw amount');
$wrong = $transfer;
$wrong['token_info']['decimals'] = 18;
qa_assert(false === $normalize->invoke($trongrid, $wrong, $contract), 'Wrong token decimals must be rejected');
$wrong = $transfer;
$wrong['to'] = 'TWrongAddress';
qa_assert(false === $normalize->invoke($trongrid, $wrong, $contract), 'Wrong recipient must be rejected');
$wrong = $transfer;
$wrong['type'] = 'Approval';
qa_assert(false === $normalize->invoke($trongrid, $wrong, $contract), 'Non-transfer event must be rejected');

// Invoice quoting and public response without WordPress or a database.
class QA_DB extends JIULIU_USDT_DB
{
    public $rows = array();
    public $logs = array();
    public $payment_locks = array();
    public $settlement_locks = array();
    public $scan_locked = false;
    public $guard_payment = null;
    public $guard_children = null;
    public $transaction_events = array();
    public $commit_result = true;
    private $transaction_rows = null;
    private $next_id = 1;

    public function plugin_schema_is_ready($refresh = false) { return true; }
    public function settlement_tables_are_transactional() { return true; }

    public function acquire_payment_lock($payment_id, $timeout_seconds = 3)
    {
        $key = (int) $payment_id;
        if (!empty($this->payment_locks[$key])) { return false; }
        $this->payment_locks[$key] = true;
        return true;
    }
    public function release_payment_lock($payment_id)
    {
        unset($this->payment_locks[(int) $payment_id]);
    }
    public function acquire_settlement_lock($payment_id, $timeout_seconds = 3)
    {
        $key = (int) $payment_id;
        if (!empty($this->settlement_locks[$key])) { return false; }
        $this->settlement_locks[$key] = true;
        return true;
    }
    public function release_settlement_lock($payment_id)
    {
        unset($this->settlement_locks[(int) $payment_id]);
    }
    public function acquire_scan_lock()
    {
        if ($this->scan_locked) { return false; }
        $this->scan_locked = true;
        return true;
    }
    public function release_scan_lock() { $this->scan_locked = false; }
    public function begin_zibll_settlement_guard($payment_id, $invoice_id = 0, $txid = '')
    {
        if (!class_exists('ZibPay')) { return new WP_Error('zibll_api_missing', 'missing'); }
        $this->transaction_rows = unserialize(serialize($this->rows));
        $this->transaction_events[] = 'begin';
        return array(
            'payment' => null !== $this->guard_payment ? $this->guard_payment : ZibPay::get_payment($payment_id),
            'children' => null !== $this->guard_children ? $this->guard_children : ZibPay::get_order_by_payment_id($payment_id, 'id,status,pay_price'),
            'invoice' => isset($this->rows[$invoice_id]) ? (array) $this->rows[$invoice_id] : array(),
            'txid' => $txid,
        );
    }
    public function commit_zibll_settlement_guard()
    {
        $this->transaction_events[] = 'commit';
        if (!$this->commit_result) { return false; }
        $this->transaction_rows = null;
        return true;
    }
    public function rollback_zibll_settlement_guard()
    {
        $this->transaction_events[] = 'rollback';
        if (null !== $this->transaction_rows) {
            $this->rows = $this->transaction_rows;
            $this->transaction_rows = null;
        }
        return true;
    }

    public function get_by_order_num($order_num)
    {
        foreach ($this->rows as $row) {
            if ($row->zibll_order_num === $order_num) { return $row; }
        }
        return null;
    }
    public function get_reusable_by_payment_id($payment_id)
    {
        $found = null;
        foreach ($this->rows as $row) {
            if ((int) $row->payment_id === (int) $payment_id && 'pending' === $row->status) { $found = $row; }
        }
        return $found;
    }
    public function refresh_invoice_attempt($id, $order_num, $token_hash)
    {
        if (
            !isset($this->rows[$id])
            || 'pending' !== $this->rows[$id]->status
            || JIULIU_USDT_Util::utc_timestamp_from_mysql($this->rows[$id]->expires_at) < time()
        ) {
            return false;
        }
        return $this->update_invoice($id, array(
            'zibll_order_num' => $order_num,
            'previous_public_token_hash' => isset($this->rows[$id]->public_token_hash) ? $this->rows[$id]->public_token_hash : null,
            'public_token_hash' => $token_hash,
            'last_checked_at' => null,
        ));
    }
    public function rotate_invoice_public_token($id, $token_hash)
    {
        if (!isset($this->rows[$id]) || 'pending' !== $this->rows[$id]->status) { return false; }
        return $this->update_invoice($id, array(
            'previous_public_token_hash' => isset($this->rows[$id]->public_token_hash) ? $this->rows[$id]->public_token_hash : null,
            'public_token_hash' => $token_hash,
            'last_checked_at' => null,
        ));
    }
    public function get_active_expected_raws($address)
    {
        $raws = array();
        foreach ($this->rows as $row) {
            if ((string) $row->receive_address === (string) $address && !empty($row->active_key)) {
                $raws[] = (string) $row->expected_raw;
            }
        }
        return $raws;
    }
    public function supersede_payment_invoices($payment_id, $except_order_num) { return 0; }
    public function insert_invoice($data)
    {
        $data['id'] = $this->next_id++;
        $row = (object) $data;
        $this->rows[$row->id] = $row;
        return $row;
    }
    public function update_invoice($id, $data)
    {
        if (!isset($this->rows[$id])) { return false; }
        foreach ($data as $key => $value) { $this->rows[$id]->{$key} = $value; }
        $this->rows[$id]->updated_at = JIULIU_USDT_Util::utc_now_mysql();
        return true;
    }
    public function get_invoice($id) { return isset($this->rows[$id]) ? $this->rows[$id] : null; }
    public function get_by_txid($txid)
    {
        foreach ($this->rows as $row) {
            if (!empty($row->txid) && strtolower($row->txid) === strtolower($txid)) { return $row; }
        }
        return null;
    }
    public function claim_invoice($id, $txid, $transfer, $allowed_statuses, $preserve_error_code = false)
    {
        if (!isset($this->rows[$id]) || !in_array($this->rows[$id]->status, $allowed_statuses, true)) { return false; }
        if (!empty($this->rows[$id]->txid) && strtolower($this->rows[$id]->txid) !== strtolower($txid)) { return false; }
        if ($this->get_by_txid($txid) && $this->get_by_txid($txid)->id !== $id) { return false; }
        $current_uncertain = isset($this->rows[$id]->error_code)
            && 'zibll_settlement_uncertain' === (string) $this->rows[$id]->error_code;
        if (($preserve_error_code && !$current_uncertain) || (!$preserve_error_code && $current_uncertain)) { return false; }
        $this->update_invoice($id, array(
            'status' => 'processing',
            'txid' => strtolower($txid),
            'from_address' => isset($transfer['from']) ? $transfer['from'] : '',
            'actual_raw' => JIULIU_USDT_Util::normalize_raw($transfer['value']),
            'actual_amount' => JIULIU_USDT_Util::raw_to_decimal($transfer['value'], 6),
            'block_timestamp' => $transfer['block_timestamp'],
            'error_code' => $preserve_error_code && isset($this->rows[$id]->error_code)
                ? $this->rows[$id]->error_code
                : null,
        ));
        return true;
    }
    public function mark_invoice_paid($id, $txid, $note)
    {
        if (
            !isset($this->rows[$id])
            || 'processing' !== $this->rows[$id]->status
            || strtolower((string) $this->rows[$id]->txid) !== strtolower((string) $txid)
        ) { return false; }
        return $this->update_invoice($id, array(
            'status' => 'paid',
            'paid_at' => JIULIU_USDT_Util::utc_now_mysql(),
            'error_code' => null,
            'note' => $note,
        ));
    }
    public function mark_processing_review($id, $txid, $error_code, $note)
    {
        if (
            !isset($this->rows[$id])
            || 'processing' !== $this->rows[$id]->status
            || strtolower((string) $this->rows[$id]->txid) !== strtolower((string) $txid)
        ) { return false; }
        return $this->update_invoice($id, array(
            'status' => 'review',
            'error_code' => $error_code,
            'note' => $note,
        ));
    }
    public function mark_settlement_uncertain($id, $txid, $note)
    {
        if (
            !isset($this->rows[$id])
            || !in_array($this->rows[$id]->status, array('processing', 'paid'), true)
            || strtolower((string) $this->rows[$id]->txid) !== strtolower((string) $txid)
        ) { return false; }
        return $this->update_invoice($id, array(
            'status' => 'review',
            'error_code' => 'zibll_settlement_uncertain',
            'note' => $note,
        ));
    }
    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0)
    {
        $this->logs[] = compact('event', 'message', 'invoice_id', 'level', 'context', 'user_id');
        return true;
    }
}

class QA_Rate extends JIULIU_USDT_Rate
{
    public function get_rate($force = false) { return array('rate' => 7.2, 'source' => 'fixed', 'fallback' => false); }
}

class QA_Trongrid extends JIULIU_USDT_Trongrid {}

$fixed = $valid_input;
$fixed['admin_email_notifications'] = 0;
$fixed['user_email_notifications'] = 0;
$settings->update($fixed);
$db = new QA_DB();
$invoices = new JIULIU_USDT_Invoices($settings, $db, new QA_Rate($settings), new QA_Trongrid($settings));
$created = $invoices->create_for_zibll(array(
    'order_num' => '52000000000001',
    'payment_id' => 101,
    'user_id' => 7,
    'local_price' => 72,
));
qa_assert(!is_wp_error($created), 'Valid Zibll data must create an invoice');
$invoice = $created['invoice'];
qa_same('10000999', $invoice->expected_raw, 'Quote must use ceil(base amount) plus a bounded deterministic unique suffix');
qa_same('10.000999', $invoice->usdt_amount, 'Invoice must display exact six-decimal amount');
qa_same(hash('sha256', $created['public_token']), $invoice->public_token_hash, 'Only the public-token hash must be stored');
$response = $invoices->build_zibll_response(array(
    'order_num' => '52000000000001',
    'payment_id' => 101,
    'user_id' => 7,
    'local_price' => 72,
));
qa_same(0, $response['error'], 'Existing pending invoice must build a cashier response');
qa_same('jiuliu_usdt_trc20', $response['check_sdk'], 'Cashier response must use the registered SDK key');
qa_assert(false !== strpos($response['more_html'], $contract), 'Cashier details must contain the configured receive address');
qa_assert(false === strpos($response['more_html'], $invoice->public_token_hash), 'Cashier must never expose the stored token hash');
qa_assert(false !== strpos($response['more_html'], '实际到账'), 'Cashier must identify the displayed amount as the website net receipt');
qa_assert(false !== strpos($response['more_html'], '手续费'), 'Cashier must explain network/exchange fees');
qa_assert(false !== strpos($response['more_html'], '不得从'), 'Cashier must say fees cannot be deducted from the displayed amount');

// Transfer matching must send late or mismatched payments to manual review.
$process = new ReflectionMethod('JIULIU_USDT_Invoices', 'process_transfer');
$process->setAccessible(true);
$late = clone $invoice;
$late->id = 99;
$late->zibll_order_num = '52000000000099';
$late->status = 'pending';
$late->txid = null;
$late->created_at = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - 3600);
$late->expires_at = JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - 1800);
$db->rows[$late->id] = $late;
$late_transfer = array(
    'transaction_id' => str_repeat('c', 64),
    'from' => 'TFrom',
    'value' => $late->expected_raw,
    'block_timestamp' => (time() - 60) * 1000,
);
$late_result = $process->invoke($invoices, $late, $late_transfer, false);
qa_same('review', $late_result->status, 'Late payment must enter manual review');
qa_same('late_payment', $late_result->error_code, 'Late payment must retain a machine-readable reason');

$summary = count($failures) . ' failure(s), ' . $tests . ' assertion(s)';
if ($failures) {
    fwrite(STDERR, "FAIL: {$summary}\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: {$summary}\n");
