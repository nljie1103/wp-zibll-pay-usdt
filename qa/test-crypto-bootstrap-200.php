<?php

// v2 integration contract: clean plugin bootstrap, seeded route registry,
// dynamic Zibll methods, immutable invoice route snapshots and multi-chain
// replay/closed-order semantics. This test uses only WordPress/Zibll shims.

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);

$GLOBALS['qa_hooks'] = array();
$GLOBALS['qa_filters'] = array();
$GLOBALS['qa_options'] = array();

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function plugin_dir_path($file) { return dirname($file) . DIRECTORY_SEPARATOR; }
function plugin_dir_url($file) { return 'https://example.test/wp-content/plugins/jiuliu-crypto-payment/'; }
function plugin_basename($file) { return basename(dirname($file)) . '/' . basename($file); }
function register_activation_hook($file, $callback) { $GLOBALS['qa_hooks']['activation'][] = $callback; }
function register_deactivation_hook($file, $callback) { $GLOBALS['qa_hooks']['deactivation'][] = $callback; }
function add_action($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['qa_hooks'][$tag][] = $callback; return true; }
function add_filter($tag, $callback, $priority = 10, $args = 1) { $GLOBALS['qa_filters'][$tag][] = $callback; return true; }
function apply_filters($tag, $value) { return $value; }
function wp_next_scheduled($event) { return time() + 30; }
function get_option($key, $default = false) { return array_key_exists($key, $GLOBALS['qa_options']) ? $GLOBALS['qa_options'][$key] : $default; }
function update_option($key, $value, $autoload = null) { $GLOBALS['qa_options'][$key] = $value; return true; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, (array) $args); }
function wp_generate_password($length) { return str_repeat('x', $length); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_unslash($value) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return esc_html($value); }
function esc_url($value) { return (string) $value; }
function esc_html__($value) { return esc_html($value); }
function esc_attr__($value) { return esc_attr($value); }
function __($value) { return $value; }
function wp_create_nonce($action) { return 'nonce-' . substr(hash('sha256', $action), 0, 12); }
function wp_rand($min, $max) { return (int) $min; }
function current_time($type) { return '2026-07-15 10:00:00'; }
function get_date_from_gmt($value, $format) { return $value; }
function get_template() { return 'zibll'; }
function load_plugin_textdomain() { return true; }

class ZibPay
{
    public static $payments = array();
    public static $orders = array();
    public static function get_payment($id) { return isset(self::$payments[$id]) ? self::$payments[$id] : false; }
    public static function get_order($id, $fields = '') { return isset(self::$orders[$id]) ? self::$orders[$id] : false; }
    public static function get_order_by_payment_id($id, $fields = '') { return array(); }
    public static function payment_order($data) { return true; }
    public static function get_meta($id, $key) { return null; }
    public static function update_meta($id, $key, $value) { return true; }
}

class QA_Capture_WPDB
{
    public $prefix = 'wp_';
    public $prepared = array();
    public $queries = array();

    public function prepare($query)
    {
        $args = func_get_args();
        array_shift($args);
        $prepared = array('query' => $query, 'args' => $args);
        $this->prepared[] = $prepared;
        return $prepared;
    }

    public function query($prepared) { $this->queries[] = $prepared; return 1; }
    public function get_row($prepared) { $this->queries[] = $prepared; return null; }
    public function get_results($prepared) { $this->queries[] = $prepared; return array(); }
}

$GLOBALS['wpdb'] = new QA_Capture_WPDB();

$tests = 0;
$failures = array();
function qa_200_assert($condition, $message)
{
    global $tests, $failures;
    $tests++;
    if (!$condition) { $failures[] = $message; }
}
function qa_200_same($expected, $actual, $message)
{
    qa_200_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

require_once __DIR__ . '/../jiuliu-crypto-payment/jiuliu-crypto-payment.php';

// Loading the new entrypoint is itself a dependency-order test: every class
// must parse before the singleton wires constructor type dependencies.
$required_classes = array(
    'JIULIU_CRYPTO_Util', 'JIULIU_CRYPTO_Routes', 'JIULIU_CRYPTO_Settings',
    'JIULIU_CRYPTO_DB', 'JIULIU_CRYPTO_Rate', 'JIULIU_CRYPTO_Trongrid',
    'JIULIU_CRYPTO_EVM', 'JIULIU_CRYPTO_Invoices', 'JIULIU_CRYPTO_Zibll',
    'JIULIU_CRYPTO_Ajax', 'JIULIU_CRYPTO_Cron', 'JIULIU_CRYPTO_Admin',
    'JIULIU_CRYPTO_Plugin',
);
foreach ($required_classes as $class) {
    qa_200_assert(class_exists($class, false), 'New entrypoint did not load dependency ' . $class);
}
qa_200_same('2.0.0', JIULIU_CRYPTO_VERSION, 'Entrypoint version must be 2.0.0');
qa_200_same('jiuliu_crypto_settings', JIULIU_CRYPTO_Settings::OPTION_NAME, 'v2 must use only its new option name');

$plugin = jiuliu_crypto_payment();
qa_200_assert($plugin instanceof JIULIU_CRYPTO_Plugin, 'New singleton did not construct');
qa_200_assert(isset($GLOBALS['qa_hooks']['rest_api_init'], $GLOBALS['qa_hooks']['admin_menu']), 'Runtime hooks were not registered');
$plugin->zibll->register();
qa_200_assert(isset($GLOBALS['qa_filters']['zibpay_payment_methods'], $GLOBALS['qa_filters']['zibpay_initiate_paysdk']), 'Zibll gateway filters were not registered');

// A first installation seeds exactly the seven conservative presets, all
// disabled and without merchant-owned receiver/provider data.
$settings_seed = new JIULIU_CRYPTO_Settings();
$settings_seed->install_defaults();
$seeded = get_option(JIULIU_CRYPTO_Settings::OPTION_NAME);
qa_200_same(7, count($seeded['payment_routes']), 'Fresh install must seed seven routes');
foreach ($seeded['payment_routes'] as $route) {
    if (is_wp_error($route)) {
        qa_200_assert(false, 'Fresh route seeding produced WP_Error: ' . $route->get_error_code());
        continue;
    }
    qa_200_assert(empty($route['enabled']), 'A seeded route must be disabled');
    qa_200_same('', $route['receive_address'], 'A seeded route must not contain a receiver');
    qa_200_assert(0 === strpos($route['method'], 'ju_crypto_'), 'Every seeded method must use the ju_crypto namespace');
}

$tron = JIULIU_CRYPTO_Routes::from_preset('usdt_trc20', array(
    'enabled' => 1,
    'receive_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
));
$base = JIULIU_CRYPTO_Routes::from_preset('usdc_base', array(
    'enabled' => 1,
    'receive_address' => '0x1111111111111111111111111111111111111111',
    'rpc_url' => 'https://base.example-rpc.test/v1/project',
));
qa_200_assert(!is_wp_error($tron) && !is_wp_error($base), 'Enabled integration routes did not normalize');

$configured = $seeded;
$configured['enabled'] = 1;
$configured['pause_monitoring'] = 0;
$configured['payment_routes'] = array($tron, $base);
$configured['fixed_rate'] = '7.20000000';
$configured['minimum_local_amount'] = '1';
$configured['maximum_local_amount'] = '100000';
$configured['invoice_timeout'] = 15;
$configured['frontend_manual_txid'] = 1;
$configured['monitor_closed_orders'] = 1;
update_option(JIULIU_CRYPTO_Settings::OPTION_NAME, $configured, false);

class QA_Crypto_DB extends JIULIU_CRYPTO_DB
{
    public $rows = array();
    private $next_id = 1;
    public function settlement_tables_are_transactional() { return true; }
    public function acquire_payment_lock($payment_id, $timeout_seconds = 3) { return true; }
    public function release_payment_lock($payment_id) { return true; }
    public function get_by_order_num($order_num) {
        foreach ($this->rows as $row) { if ((string) $row->zibll_order_num === (string) $order_num) { return $row; } }
        return null;
    }
    public function get_reusable_by_payment_id($payment_id) { return null; }
    public function get_active_expected_raws($address, $route_id = '', $quote_scope = '') { return array(); }
    public function insert_invoice($data) {
        $data['id'] = $this->next_id++;
        $row = (object) $data;
        $this->rows[$row->id] = $row;
        return $row;
    }
    public function get_invoice($id) { return isset($this->rows[$id]) ? $this->rows[$id] : null; }
    public function refresh_invoice_attempt($id, $order_num, $token_hash) { return false; }
    public function rotate_invoice_public_token($id, $token_hash) { return true; }
    public function supersede_payment_invoices($payment_id, $except_order_num) { return 0; }
    public function log($event, $message, $invoice_id = 0, $level = 'info', $context = array(), $user_id = 0) { return true; }
}
class QA_Crypto_Rate extends JIULIU_CRYPTO_Rate
{
    public function get_rate($force = false, $asset_symbol = 'USDT', $route = array())
    {
        return array('rate' => 7.2, 'source' => 'qa_fixed');
    }
}

$settings = new JIULIU_CRYPTO_Settings();
$routes = new JIULIU_CRYPTO_Routes($configured['payment_routes']);
$db = new QA_Crypto_DB();
$rate = new QA_Crypto_Rate($settings);
$trongrid = new JIULIU_CRYPTO_Trongrid($settings);
$invoices = new JIULIU_CRYPTO_Invoices($settings, $db, $rate, $trongrid, $routes);
$zibll = new JIULIU_CRYPTO_Zibll($settings, $db, $invoices, $routes);

$methods = $zibll->add_payment_method(array('alipay' => array('name' => 'Alipay')), 'pay');
qa_200_assert(isset($methods['ju_crypto_usdt_trc20'], $methods['ju_crypto_usdc_base']), 'Enabled routes were not exposed as separate dynamic methods');
foreach (array_keys($methods) as $method_key) {
    if ('alipay' !== $method_key) { qa_200_assert(0 === strpos($method_key, 'ju_crypto_'), 'A crypto payment method escaped the ju_crypto namespace'); }
}
qa_200_same(JIULIU_CRYPTO_Zibll::SDK, $zibll->route_payment_sdk('other', array('payment_method' => 'ju_crypto_usdc_base')), 'Dynamic route did not select the v2 SDK');

ZibPay::$payments[1001] = array(
    'id' => 1001, 'order_num' => '52000000002001', 'method' => 'ju_crypto_usdc_base',
    'price' => 72, 'status' => '0',
);
$created_base = $invoices->create_for_zibll(array(
    'payment_id' => 1001, 'order_num' => '52000000002001', 'payment_method' => 'ju_crypto_usdc_base',
    'local_price' => 72, 'user_id' => 9,
));
qa_200_assert(!is_wp_error($created_base), 'Base invoice creation failed');
$base_invoice = is_wp_error($created_base) ? (object) array() : $created_base['invoice'];
qa_200_same('usdc_base', isset($base_invoice->route_id) ? $base_invoice->route_id : null, 'Invoice did not snapshot route ID');
qa_200_same('evm', isset($base_invoice->adapter) ? $base_invoice->adapter : null, 'Invoice did not snapshot adapter');
qa_200_same('8453', isset($base_invoice->chain_id) ? $base_invoice->chain_id : null, 'Invoice did not snapshot chain ID');
qa_200_same('USDC', isset($base_invoice->asset_symbol) ? $base_invoice->asset_symbol : null, 'Invoice did not snapshot asset symbol');
qa_200_same($base['contract_address'], isset($base_invoice->contract_address) ? $base_invoice->contract_address : null, 'Invoice did not snapshot contract');
qa_200_same($base['receive_address'], isset($base_invoice->receive_address) ? $base_invoice->receive_address : null, 'Invoice did not snapshot receiver');
qa_200_assert(isset($base_invoice->asset_amount, $base_invoice->expected_raw) && $base_invoice->asset_amount === JIULIU_CRYPTO_Util::raw_to_decimal($base_invoice->expected_raw, 6), 'asset_amount did not exactly represent expected_raw');
qa_200_assert(isset($base_invoice->quote_scope) && 1 === preg_match('/^[a-f0-9]{64}$/', $base_invoice->quote_scope), 'Invoice quote_scope is not a SHA-256 scope');

ZibPay::$payments[1002] = array(
    'id' => 1002, 'order_num' => '52000000002002', 'method' => 'ju_crypto_usdt_trc20',
    'price' => 72, 'status' => '0',
);
$created_tron = $invoices->create_for_zibll(array(
    'payment_id' => 1002, 'order_num' => '52000000002002', 'payment_method' => 'ju_crypto_usdt_trc20',
    'local_price' => 72, 'user_id' => 9,
));
qa_200_assert(!is_wp_error($created_tron), 'TRON invoice creation failed');
$tron_invoice = is_wp_error($created_tron) ? (object) array() : $created_tron['invoice'];
qa_200_assert(isset($tron_invoice->quote_scope, $base_invoice->quote_scope) && $tron_invoice->quote_scope !== $base_invoice->quote_scope, 'Two chains shared one quote scope');

$scope_method = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'tx_scope_from_invoice');
$scope_method->setAccessible(true);
$base_scope = $scope_method->invoke($invoices, $base_invoice);
$tron_scope = $scope_method->invoke($invoices, $tron_invoice);
qa_200_same('evm|8453', $base_scope, 'Base transaction scope is wrong');
qa_200_same('tron|tron-mainnet', $tron_scope, 'TRON transaction scope is wrong');
qa_200_assert($base_scope !== $tron_scope, 'Cross-chain transaction scopes collided');

// The production DB derives tx_key from chain scope, so an identical hash on
// two chains is independent while replay on the same chain remains identical.
$capture = new QA_Capture_WPDB();
$GLOBALS['wpdb'] = $capture;
$real_db = new JIULIU_CRYPTO_DB();
$same_txid = str_repeat('a', 64);
$real_db->get_by_txid($same_txid, $base_scope);
$base_tx_key = $capture->prepared[count($capture->prepared) - 1]['args'][0];
$real_db->get_by_txid($same_txid, $tron_scope);
$tron_tx_key = $capture->prepared[count($capture->prepared) - 1]['args'][0];
$real_db->get_by_txid($same_txid, $base_scope);
$base_tx_key_repeat = $capture->prepared[count($capture->prepared) - 1]['args'][0];
qa_200_assert($base_tx_key !== $tron_tx_key, 'Same transaction hash collided across chain scopes');
qa_200_same($base_tx_key, $base_tx_key_repeat, 'Same-chain replay did not produce a stable tx_key');

// Frontend financial copy must be unambiguous for every asset/network.
$render = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'render_frontend_details');
$render->setAccessible(true);
$html = $render->invoke($invoices, $base_invoice, $created_base['public_token']);
$copy = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');
qa_200_assert(false !== strpos($copy, '网站必须完整收到的金额（精确）'), 'Cashier omitted the exact net-receipt label');
qa_200_assert(false !== strpos($copy, '请确保网站实际收到上述完整金额'), 'Cashier omitted the complete-receipt warning');
qa_200_assert(false !== strpos($copy, '手续费由付款方另行承担，不得从页面金额中扣除'), 'Cashier omitted payer-bears-fees wording');
qa_200_assert(false !== strpos($copy, 'ETH'), 'Cashier did not name the route fee currency');

// Zibll close events choose the explicit monitored/non-monitored terminal
// states. A monitored closed order can only go to review; disabling monitoring
// removes it from automatic scans.
ZibPay::$orders[5001] = array('id' => 5001, 'payment_id' => 1001);
$capture->prepared = array();
$capture->queries = array();
$zibll->handle_order_closed(5001, 'user', 'customer closed');
$close_args = $capture->prepared[count($capture->prepared) - 1]['args'];
qa_200_same('closed', $close_args[0], 'Enabled closed-order observation did not select closed status');

$configured['monitor_closed_orders'] = 0;
update_option(JIULIU_CRYPTO_Settings::OPTION_NAME, $configured, false);
$settings_no_monitor = new JIULIU_CRYPTO_Settings();
$zibll_no_monitor = new JIULIU_CRYPTO_Zibll($settings_no_monitor, $real_db, $invoices, $routes);
$capture->prepared = array();
$capture->queries = array();
$zibll_no_monitor->handle_order_closed(5001, 'user', 'customer closed');
$close_no_monitor_args = $capture->prepared[count($capture->prepared) - 1]['args'];
qa_200_same('closed_no_monitor', $close_no_monitor_args[0], 'Disabled closed-order observation did not select closed_no_monitor status');

// Old monitored invoices remain candidates until a successful chain scan has
// explicitly released their exact-amount keys. Release SQL is invoice-scoped;
// there is no wall-clock-only bulk release for monitored states.
$capture->prepared = array();
$capture->queries = array();
$real_db->pending_for_scan(24, 500, true);
$history_query = $capture->prepared[2]['query'];
qa_200_assert(false !== strpos($history_query, 'OR active_key IS NOT NULL'), 'Old active invoices can age out before historical scan coverage');
$real_db->release_scanned_active_keys(array(19, 23), 24);
$scoped_release_query = $capture->prepared[count($capture->prepared) - 1]['query'];
qa_200_assert(false !== strpos($scoped_release_query, 'id IN (19,23)'), 'Covered-tail release was not restricted to scanned invoices');
qa_200_assert(!method_exists($real_db, 'release_old_active_keys'), 'Unsafe wall-clock-only active-key release remains callable');

// TRON's account transfer list is discovery-only. Every automatic candidate
// must pass walletsolidity receipt + unique event verification before claim.
class QA_Failing_Trongrid extends JIULIU_CRYPTO_Trongrid
{
    public $find_calls = 0;
    public function find_txid($address, $txid, $min_timestamp, $max_timestamp, $route = array(), $expected_raw = null)
    {
        $this->find_calls++;
        return new WP_Error('qa_receipt_rejected', 'receipt rejected');
    }
}
$strict_tron = new QA_Failing_Trongrid($settings);
$strict_service = new JIULIU_CRYPTO_Invoices($settings, $db, $rate, $strict_tron, $routes);
$best_transfer = new ReflectionMethod('JIULIU_CRYPTO_Invoices', 'process_best_transfer');
$best_transfer->setAccessible(true);
$strict_result = $best_transfer->invoke($strict_service, $tron_invoice, array(array(
    'transaction_id' => str_repeat('b', 64),
    'value' => $tron_invoice->expected_raw,
    'block_timestamp' => time() * 1000,
)), false, 'auto', false);
qa_200_same(1, $strict_tron->find_calls, 'Automatic TRON candidate skipped direct receipt/event verification');
qa_200_assert(is_wp_error($strict_result) && 'qa_receipt_rejected' === $strict_result->get_error_code(), 'Rejected TRON receipt reached settlement');

if ($failures) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . ' / ' . $tests . " v2 integration assertions failed\n");
    foreach ($failures as $failure) { fwrite(STDERR, ' - ' . $failure . "\n"); }
    exit(1);
}

fwrite(STDOUT, 'OK: ' . $tests . " v2 integration assertions passed\n");
