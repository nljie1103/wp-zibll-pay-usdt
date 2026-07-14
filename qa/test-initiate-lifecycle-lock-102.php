<?php

// Regression: Zibll mutates the payment row before dispatching its gateway
// filter. The initiate_pay request must therefore hold the same settlement
// lock for the whole request, and a competing request must fail closed.

class QA_Initiate_JSON_Exception_102 extends Exception
{
    public $payload;
    public $status;

    public function __construct($payload, $status)
    {
        parent::__construct('wp_send_json_error');
        $this->payload = $payload;
        $this->status = $status;
    }
}

function wp_send_json_error($payload = null, $status_code = null)
{
    // WordPress terminates the request here. Throwing lets the standalone QA
    // process inspect the response without allowing execution to continue.
    throw new QA_Initiate_JSON_Exception_102($payload, $status_code);
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
    public static function get_payment($id) { return self::$payment; }
}

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-zibll.php';

function qa_initiate_lock_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$lock_db = new QA_DB();
$lock_service = new JIULIU_USDT_Invoices(
    $settings,
    $lock_db,
    new QA_Rate($settings),
    new QA_Trongrid($settings)
);
$lock_zibll = new JIULIU_USDT_Zibll($settings, $lock_db, $lock_service);

// Use a pending unrelated gateway: all mutable pending initiate requests share
// the lifecycle lock, while ownership checks are scoped to entering/leaving
// USDT and are covered separately by test-initiate-prechange-auth-102.php.
ZibPay::$payment = array(
    'id' => 901,
    'order_num' => '52000000000901',
    'method' => 'alipay',
    'price' => 72,
    'status' => '0',
);
$_REQUEST['payment_id'] = '901';
$_REQUEST['payment_method'] = 'wechat';
$lock_zibll->lock_initiate_payment();
if (empty($lock_db->settlement_locks[901])) {
    qa_initiate_lock_fail_102('initiate_pay did not acquire the settlement lock');
}

// A duplicate callback in the same request must be idempotent and the shutdown
// release must free every lock acquired by this request.
$lock_zibll->lock_initiate_payment();
$lock_zibll->release_initiate_payment_locks();
if (!empty($lock_db->settlement_locks[901])) {
    qa_initiate_lock_fail_102('shutdown release leaked the initiate_pay settlement lock');
}

$busy_db = new QA_DB();
$busy_db->settlement_locks[902] = true;
$busy_service = new JIULIU_USDT_Invoices(
    $settings,
    $busy_db,
    new QA_Rate($settings),
    new QA_Trongrid($settings)
);
$busy_zibll = new JIULIU_USDT_Zibll($settings, $busy_db, $busy_service);
ZibPay::$payment['id'] = 902;
ZibPay::$payment['order_num'] = '52000000000902';
$_REQUEST['payment_id'] = '902';
$busy_error = null;
try {
    $busy_zibll->lock_initiate_payment();
} catch (QA_Initiate_JSON_Exception_102 $e) {
    $busy_error = $e;
}

if (!$busy_error) {
    qa_initiate_lock_fail_102('a competing settlement lock did not block initiate_pay');
}
if (
    409 !== (int) $busy_error->status
    || !is_array($busy_error->payload)
    || !isset($busy_error->payload['code'])
    || 'jiuliu_usdt_payment_busy' !== $busy_error->payload['code']
) {
    qa_initiate_lock_fail_102('busy initiate_pay did not return the expected 409 error');
}

// The busy request did not acquire this pre-existing lock and must never
// release it during shutdown.
$busy_zibll->release_initiate_payment_locks();
if (empty($busy_db->settlement_locks[902])) {
    qa_initiate_lock_fail_102('busy initiate_pay released another request\'s settlement lock');
}

unset($_REQUEST['payment_id'], $_REQUEST['payment_method']);
fwrite(STDOUT, "PASS: initiate_pay lifecycle settlement lock and busy rejection\n");
