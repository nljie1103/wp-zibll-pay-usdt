<?php

// Closed/mixed Zibll orders must never be reopened or delivered by a late USDT
// transfer, regardless of whether Zibll's optional pending-order monitor is on.

$GLOBALS['qa_monitor_enabled'] = false;

class ZibPay
{
    public static $payment = array(
        'id' => 101,
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
    );
    public static $children = array();
    public static $payment_order_calls = 0;

    public static function get_payment($id) { return self::$payment; }
    public static function get_order_by_payment_id($id, $fields = '') { return self::$children; }
    public static function get_meta($id, $key) { return null; }
    public static function payment_order($data) { self::$payment_order_calls++; return true; }
}

require_once __DIR__ . '/test-plugin-core.php';

function qa_processing_invoice(QA_DB $db, $id_suffix, $payment_id, $order_num, $txid)
{
    global $invoice;
    $seed = (array) $invoice;
    unset($seed['id']);
    $seed['invoice_no'] = 'JU-CLOSED-' . $id_suffix;
    $seed['payment_id'] = $payment_id;
    $seed['zibll_order_num'] = $order_num;
    $seed['status'] = 'processing';
    $seed['txid'] = $txid;
    $seed['actual_raw'] = $seed['expected_raw'];
    $seed['actual_amount'] = $seed['usdt_amount'];
    return $db->insert_invoice($seed);
}

$db = new QA_DB();
$service = new JIULIU_USDT_Invoices($settings, $db, new QA_Rate($settings), new QA_Trongrid($settings));
$settle = new ReflectionMethod('JIULIU_USDT_Invoices', 'settle_zibll');
$settle->setAccessible(true);

$closed_txid = str_repeat('1', 64);
$closed = qa_processing_invoice($db, 'PARENT', 901, '52000000000901', $closed_txid);
ZibPay::$payment = array(
    'id' => 901,
    'order_num' => $closed->zibll_order_num,
    'method' => 'usdt_trc20',
    'price' => 72,
    'status' => '-1',
);
ZibPay::$children = array(array('id' => 9101, 'status' => '-1', 'pay_price' => 72));
ZibPay::$payment_order_calls = 0;
$closed_result = $settle->invoke($service, $closed, $closed_txid, false);
if (!is_wp_error($closed_result) || 'zibll_payment_closed' !== $closed_result->get_error_code()) {
    fwrite(STDERR, "FAIL: closed parent payment was not rejected\n");
    exit(1);
}
if ('review' !== $db->get_invoice($closed->id)->status || 0 !== ZibPay::$payment_order_calls) {
    fwrite(STDERR, "FAIL: closed parent payment reached delivery/reopen logic\n");
    exit(1);
}

$mixed_txid = str_repeat('2', 64);
$mixed = qa_processing_invoice($db, 'CHILD', 902, '52000000000902', $mixed_txid);
ZibPay::$payment = array(
    'id' => 902,
    'order_num' => $mixed->zibll_order_num,
    'method' => 'usdt_trc20',
    'price' => 72,
    'status' => '0',
);
ZibPay::$children = array(
    array('id' => 9201, 'status' => '0', 'pay_price' => 36),
    array('id' => 9202, 'status' => '-1', 'pay_price' => 36),
);
ZibPay::$payment_order_calls = 0;
$mixed_result = $settle->invoke($service, $mixed, $mixed_txid, false);
if (!is_wp_error($mixed_result) || 'zibll_child_not_pending' !== $mixed_result->get_error_code()) {
    fwrite(STDERR, "FAIL: mixed/closed child order set was not rejected\n");
    exit(1);
}
if ('review' !== $db->get_invoice($mixed->id)->status || 0 !== ZibPay::$payment_order_calls) {
    fwrite(STDERR, "FAIL: mixed child order set reached delivery logic\n");
    exit(1);
}

// Even an administrator force-verification must not reopen an invoice that
// was already closed by Zibll before the transfer arrived.
$inactive_txid = str_repeat('3', 64);
$inactive_seed = (array) $invoice;
unset($inactive_seed['id']);
$inactive_seed['invoice_no'] = 'JU-CLOSED-INACTIVE';
$inactive_seed['payment_id'] = 903;
$inactive_seed['zibll_order_num'] = '52000000000903';
$inactive_seed['status'] = 'closed';
$inactive_seed['txid'] = null;
$inactive = $db->insert_invoice($inactive_seed);
ZibPay::$payment = array(
    'id' => 903,
    'order_num' => $inactive->zibll_order_num,
    'method' => 'usdt_trc20',
    'price' => 72,
    'status' => '0',
);
ZibPay::$children = array(array('id' => 9301, 'status' => '0', 'pay_price' => 72));
ZibPay::$payment_order_calls = 0;
$process = new ReflectionMethod('JIULIU_USDT_Invoices', 'process_transfer');
$process->setAccessible(true);
$inactive_result = $process->invoke($service, $inactive, array(
    'transaction_id' => $inactive_txid,
    'from' => 'TClosedSender',
    'value' => $inactive->expected_raw,
    'block_timestamp' => time() * 1000,
), true, 'admin');
if (!is_object($inactive_result) || 'review' !== $inactive_result->status || 'inactive_invoice_payment' !== $inactive_result->error_code) {
    fwrite(STDERR, "FAIL: closed plugin invoice did not remain review-only under admin force\n");
    exit(1);
}
if (0 !== ZibPay::$payment_order_calls) {
    fwrite(STDERR, "FAIL: closed plugin invoice reached delivery logic under admin force\n");
    exit(1);
}
if ($GLOBALS['qa_monitor_enabled']) {
    fwrite(STDERR, "FAIL: monitor-disabled safety precondition changed unexpectedly\n");
    exit(1);
}

fwrite(STDOUT, "PASS: closed parent and mixed child orders remain review-only with no delivery\n");
