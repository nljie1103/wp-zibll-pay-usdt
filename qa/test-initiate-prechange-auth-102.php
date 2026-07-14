<?php

// Regression: the priority-6 initiate_pay guard runs before Zibll refreshes
// order_num/method. Requests entering or leaving USDT must prove ownership at
// that point, while unrelated and terminal theme states remain untouched.

define('JIULIU_USDT_FILE', __FILE__);
$GLOBALS['qa_initiate_auth_hooks_102'] = array();
$GLOBALS['qa_cookie_allowed_102'] = false;
$GLOBALS['qa_cookie_calls_102'] = 0;

function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['qa_initiate_auth_hooks_102'][$tag][] = array(
        'callback' => $callback,
        'priority' => $priority,
    );
    return true;
}
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function plugin_basename($file) { return basename($file); }
function zibpay_posts_order_check_cookie_token($order)
{
    $GLOBALS['qa_cookie_calls_102']++;
    return !empty($GLOBALS['qa_cookie_allowed_102']);
}

class QA_Initiate_Auth_JSON_102 extends Exception
{
    public $payload;
    public $status;
    public function __construct($payload, $status)
    {
        parent::__construct('wp_send_json_error');
        $this->payload = $payload;
        $this->status = (int) $status;
    }
}
function wp_send_json_error($payload = null, $status_code = null)
{
    // WordPress exits here. Throwing proves the theme handler cannot continue.
    throw new QA_Initiate_Auth_JSON_102($payload, $status_code);
}

class ZibPay
{
    public static $payment = array(
        'id' => 101,
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
    );
    public static $orders = array(
        array('id' => 1001, 'user_id' => 7, 'status' => '0'),
    );
    public static $order_reads = 0;

    public static function get_payment($id) { return self::$payment; }
    public static function get_order_by_payment_id($id, $fields = '')
    {
        self::$order_reads++;
        return self::$orders;
    }
}

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-zibll.php';

function qa_initiate_auth_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_initiate_auth_case_102($config)
{
    global $settings;

    ZibPay::$payment = !empty($config['missing']) ? array() : array(
        'id' => $config['payment_id'],
        'order_num' => '5200000000' . $config['payment_id'],
        'method' => $config['current_method'],
        'price' => 72,
        'status' => (string) $config['status'],
    );
    ZibPay::$orders = array(array(
        'id' => $config['payment_id'] * 10 + 1,
        'user_id' => $config['owner_id'],
        'status' => '0',
    ));
    ZibPay::$order_reads = 0;
    $GLOBALS['qa_current_user_id'] = $config['current_user_id'];
    $GLOBALS['qa_cookie_allowed_102'] = $config['cookie_allowed'];
    $GLOBALS['qa_cookie_calls_102'] = 0;
    $_REQUEST = array(
        'payment_id' => (string) $config['payment_id'],
        'payment_method' => $config['requested_method'],
    );

    $db = new QA_DB();
    if (!empty($config['preheld'])) {
        $db->settlement_locks[$config['payment_id']] = true;
    }
    $service = new JIULIU_USDT_Invoices(
        $settings,
        $db,
        new QA_Rate($settings),
        new QA_Trongrid($settings)
    );
    $zibll = new JIULIU_USDT_Zibll($settings, $db, $service);
    $exception = null;
    try {
        $zibll->lock_initiate_payment();
    } catch (QA_Initiate_Auth_JSON_102 $e) {
        $exception = $e;
    }

    $held_before_release = !empty($db->settlement_locks[$config['payment_id']]);
    $zibll->release_initiate_payment_locks();
    $held_after_release = !empty($db->settlement_locks[$config['payment_id']]);
    return array(
        'exception' => $exception,
        'held_before_release' => $held_before_release,
        'held_after_release' => $held_after_release,
        'order_reads' => ZibPay::$order_reads,
        'cookie_calls' => $GLOBALS['qa_cookie_calls_102'],
    );
}

// Verify the guard is actually registered before Zibll's priority-10 AJAX
// handler on both authenticated and guest routes.
$hook_db = new QA_DB();
$hook_service = new JIULIU_USDT_Invoices($settings, $hook_db, new QA_Rate($settings), new QA_Trongrid($settings));
$hook_zibll = new JIULIU_USDT_Zibll($settings, $hook_db, $hook_service);
$hook_zibll->register();
foreach (array('wp_ajax_initiate_pay', 'wp_ajax_nopriv_initiate_pay') as $hook) {
    if (
        empty($GLOBALS['qa_initiate_auth_hooks_102'][$hook][0])
        || 6 !== (int) $GLOBALS['qa_initiate_auth_hooks_102'][$hook][0]['priority']
    ) {
        qa_initiate_auth_fail_102($hook . ' ownership guard is not registered at priority 6');
    }
}

$base = array(
    'payment_id' => 9101,
    'current_method' => 'usdt_trc20',
    'requested_method' => 'alipay',
    'status' => '0',
    'owner_id' => 55,
    'current_user_id' => 99,
    'cookie_allowed' => false,
    'preheld' => false,
    'missing' => false,
);

$current_usdt_denied = qa_initiate_auth_case_102($base);
if (
    !$current_usdt_denied['exception']
    || 403 !== $current_usdt_denied['exception']->status
    || 'jiuliu_usdt_payment_forbidden' !== $current_usdt_denied['exception']->payload['code']
) {
    qa_initiate_auth_fail_102('unauthorized request leaving USDT was not terminated with 403');
}
if ($current_usdt_denied['held_after_release']) {
    qa_initiate_auth_fail_102('denied USDT request leaked its lifecycle lock');
}

$switch = $base;
$switch['payment_id'] = 9102;
$switch['current_method'] = 'alipay';
$switch['requested_method'] = 'usdt_trc20';
$switch_denied = qa_initiate_auth_case_102($switch);
if (!$switch_denied['exception'] || 403 !== $switch_denied['exception']->status) {
    qa_initiate_auth_fail_102('unauthorized request switching into USDT was not terminated with 403');
}

$legal = $switch;
$legal['payment_id'] = 9103;
$legal['current_user_id'] = 55;
$legal_user = qa_initiate_auth_case_102($legal);
if ($legal_user['exception'] || !$legal_user['held_before_release'] || $legal_user['held_after_release']) {
    qa_initiate_auth_fail_102('matching logged-in owner did not pass with a scoped lifecycle lock');
}

$guest = $switch;
$guest['payment_id'] = 9104;
$guest['owner_id'] = 0;
$guest['current_user_id'] = 0;
$guest_denied = qa_initiate_auth_case_102($guest);
if (!$guest_denied['exception'] || 403 !== $guest_denied['exception']->status || 1 !== $guest_denied['cookie_calls']) {
    qa_initiate_auth_fail_102('guest without the order cookie was not rejected before theme mutation');
}
$guest['payment_id'] = 9105;
$guest['cookie_allowed'] = true;
$guest_allowed = qa_initiate_auth_case_102($guest);
if ($guest_allowed['exception'] || 1 !== $guest_allowed['cookie_calls'] || !$guest_allowed['held_before_release']) {
    qa_initiate_auth_fail_102('guest with a valid order cookie did not pass ownership validation');
}

// All pending initiate requests share the lifecycle lock: otherwise a
// concurrent non-USDT -> USDT switch could race past a supposedly unrelated
// request. A normal non-USDT request is allowed while still holding the lock
// through shutdown; contention deliberately receives the same 409.
$unrelated = $base;
$unrelated['payment_id'] = 9106;
$unrelated['current_method'] = 'alipay';
$unrelated['requested_method'] = 'wechat';
$unrelated_result = qa_initiate_auth_case_102($unrelated);
if (
    $unrelated_result['exception']
    || 0 !== $unrelated_result['order_reads']
    || !$unrelated_result['held_before_release']
    || $unrelated_result['held_after_release']
) {
    qa_initiate_auth_fail_102('non-USDT request did not complete with a scoped lifecycle lock');
}
$unrelated['payment_id'] = 9109;
$unrelated['preheld'] = true;
$unrelated_busy = qa_initiate_auth_case_102($unrelated);
if (!$unrelated_busy['exception'] || 409 !== $unrelated_busy['exception']->status || !$unrelated_busy['held_after_release']) {
    qa_initiate_auth_fail_102('contended pending non-USDT request did not fail with lifecycle-lock 409');
}

// Terminal/missing states are returned by the theme before its mutable pending
// path. They bypass this lock even when an older settlement lock is present.
foreach (array('1', '-1') as $terminal_status) {
    $terminal = $base;
    $terminal['payment_id'] = '1' === $terminal_status ? 9107 : 9108;
    $terminal['status'] = $terminal_status;
    $terminal['preheld'] = true;
    $terminal_result = qa_initiate_auth_case_102($terminal);
    if ($terminal_result['exception'] || 0 !== $terminal_result['order_reads'] || !$terminal_result['held_after_release']) {
        qa_initiate_auth_fail_102('paid/closed state was not left to the theme handler');
    }
}
$missing = $base;
$missing['payment_id'] = 9110;
$missing['missing'] = true;
$missing['preheld'] = true;
$missing_result = qa_initiate_auth_case_102($missing);
if ($missing_result['exception'] || 0 !== $missing_result['order_reads'] || !$missing_result['held_after_release']) {
    qa_initiate_auth_fail_102('missing payment state was not left to the theme handler');
}

unset($GLOBALS['qa_current_user_id']);
$_REQUEST = array();
fwrite(STDOUT, "PASS: priority-6 initiate ownership checks and scoped gateway handling\n");
