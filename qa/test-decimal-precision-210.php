<?php

// String-only quote and 6/18-decimal exact-tail contract.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value) { return $value; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function absint($value) { return abs((int) $value); }
function apply_filters($tag, $value) { return $value; }
function wp_generate_password($length) { return str_repeat('x', $length); }
function wp_rand($min, $max) { return (int) $min; }

class JIULIU_CRYPTO_Settings
{
    private $values;
    public function __construct($values = array()) { $this->values = $values; }
    public function get($key, $default = null) { return array_key_exists($key, $this->values) ? $this->values[$key] : $default; }
}

class JIULIU_CRYPTO_DB
{
    public $inserted;
    public function settlement_tables_are_transactional() { return true; }
    public function acquire_payment_lock($payment_id, $timeout = 3) { return true; }
    public function release_payment_lock($payment_id) { return true; }
    public function get_by_order_num($order_num) { return null; }
    public function get_reusable_by_payment_id($payment_id) { return null; }
    public function get_active_expected_raws($address, $route_id = '', $quote_scope = '') { return array(); }
    public function insert_invoice($data) {
        $data['id'] = 1;
        $this->inserted = (object) $data;
        return $this->inserted;
    }
    public function get_invoice($id) { return $this->inserted; }
    public function supersede_payment_invoices($payment_id, $except_order_num) { return 0; }
    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0) { return true; }
}

class JIULIU_CRYPTO_Rate
{
    public function get_rate($force = false, $asset_symbol = 'USDT', $route = array())
    {
        return array('rate' => '7.20000000', 'source' => 'qa_fixed');
    }
}

class JIULIU_CRYPTO_Trongrid {}

class QA_Precision_Routes
{
    private $route;
    public function __construct($route) { $this->route = $route; }
    public function get_by_method($method, $enabled_only = false) {
        return $method === $this->route['method'] && (!$enabled_only || !empty($this->route['enabled'])) ? $this->route : null;
    }
    public function get_by_id($id, $enabled_only = false) {
        return $id === $this->route['id'] && (!$enabled_only || !empty($this->route['enabled'])) ? $this->route : null;
    }
}

class ZibPay
{
    public static $payment;
    public static function get_payment($id) { return self::$payment; }
}

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-invoices.php';

$tests = 0;
$failures = array();
function qa_precision_same($expected, $actual, $message)
{
    global $tests, $failures;
    $tests++;
    if ($expected !== $actual) {
        $failures[] = $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')';
    }
}
function qa_precision_true($condition, $message)
{
    qa_precision_same(true, (bool) $condition, $message);
}

qa_precision_same('10000000', JIULIU_CRYPTO_Util::quote_to_raw('72.00000000', '7.20000000', '0', 6), 'Six-decimal quote is wrong');
qa_precision_same('333334', JIULIU_CRYPTO_Util::quote_to_raw('1', '3', '0', 6), 'Quote division was not rounded upward');
qa_precision_same('10550000', JIULIU_CRYPTO_Util::quote_to_raw('72', '7.2', '5.5', 6), 'Positive markup is wrong');
qa_precision_same('5000000', JIULIU_CRYPTO_Util::quote_to_raw('72', '7.2', '-50', 6), 'Negative markup is wrong');
qa_precision_same('10000000000000000000', JIULIU_CRYPTO_Util::quote_to_raw('72', '7.2', '0', 18), 'Eighteen-decimal quote is wrong');
qa_precision_same('999999999999999998000000000000000001', JIULIU_CRYPTO_Util::raw_multiply('999999999999999999', '999999999999999999'), 'Large multiplication used lossy native arithmetic');
qa_precision_same(false, JIULIU_CRYPTO_Util::decimal_to_raw('1.0000001', 6), 'Non-zero hidden precision was silently discarded');
qa_precision_same('10.000001', JIULIU_CRYPTO_Util::raw_to_display_decimal('10000001000000000000', 18, 6), 'Scaled 18-decimal display is wrong');
qa_precision_same(false, JIULIU_CRYPTO_Util::raw_to_display_decimal('10000001000000000001', 18, 6), 'Hidden non-zero chain units were silently hidden');

$route = array(
    'id' => 'usdt_bsc',
    'method' => 'ju_crypto_usdt_bsc',
    'enabled' => 1,
    'adapter' => 'evm',
    'chain_key' => 'bsc',
    'chain_id' => '56',
    'asset_symbol' => 'USDT',
    'asset_decimals' => 18,
    'network' => 'BNB Smart Chain (BEP20)',
    'contract_address' => '0x55d398326f99059ff775485246999027b3197955',
    'receive_address' => '0x1111111111111111111111111111111111111111',
    'fee_symbol' => 'BNB',
    'required_confirmations' => 15,
);
$settings = new JIULIU_CRYPTO_Settings(array(
    'enabled' => 1,
    'pause_monitoring' => 0,
    'minimum_local_amount' => '1',
    'maximum_local_amount' => '100000',
    'invoice_timeout' => 15,
    'rate_markup' => '0',
));
$db = new JIULIU_CRYPTO_DB();
$routes = new QA_Precision_Routes($route);
$service = new JIULIU_CRYPTO_Invoices($settings, $db, new JIULIU_CRYPTO_Rate(), new JIULIU_CRYPTO_Trongrid(), $routes);
ZibPay::$payment = array(
    'id' => 71,
    'order_num' => '52000000000071',
    'method' => 'ju_crypto_usdt_bsc',
    'price' => '72.00000000',
);
$created = $service->create_for_zibll(array(
    'payment_id' => 71,
    'order_num' => '52000000000071',
    'payment_method' => 'ju_crypto_usdt_bsc',
    'local_price' => '72.00000000',
    'user_id' => 7,
));
qa_precision_true(!is_wp_error($created), '18-decimal BSC invoice creation failed');
$invoice = is_wp_error($created) ? (object) array() : $created['invoice'];
qa_precision_same('10000001000000000000', isset($invoice->expected_raw) ? $invoice->expected_raw : null, 'Visible unique tail was not scaled into 18-decimal base units');
qa_precision_same('10.000001', isset($invoice->asset_amount) ? $invoice->asset_amount : null, 'Payer-facing amount exceeded six decimals');
qa_precision_true(isset($invoice->expected_raw) && strlen($invoice->expected_raw) > strlen((string) PHP_INT_MAX), 'Integration fixture did not exceed PHP_INT_MAX');

$display = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'expected_display_amount');
$display->setAccessible(true);
qa_precision_same('10.000001', $display->invoke($service, $invoice), 'Invoice display was controlled by padded DECIMAL storage');

$scope = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'route_scope');
$scope->setAccessible(true);
$route_six = $route;
$route_six['asset_decimals'] = 6;
qa_precision_true($scope->invoke($service, $route) !== $scope->invoke($service, $route_six), 'Different asset precision shared one quote/cursor scope');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $tests . " precision assertions failed\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, ' - ' . $failure . "\n");
    }
    exit(1);
}

fwrite(STDOUT, 'OK: ' . $tests . " string-decimal and 6/18 precision assertions passed\n");
