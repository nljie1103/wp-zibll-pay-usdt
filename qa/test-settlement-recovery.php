<?php

// Regression test for interrupted Zibll settlements. It imports the core shim
// and asserts that a paid parent with an incomplete child is not reported as a
// fully paid USDT invoice.

require_once __DIR__ . '/test-plugin-core.php';

class ZibPay
{
    public static $payment = array(
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
    );
    public static $children = array();
    public static $meta = array();

    public static function get_payment($id) { return self::$payment; }
    public static function get_order_by_payment_id($id, $fields = '') { return self::$children; }
    public static function payment_order($data) { return true; }
    public static function get_meta($id, $key)
    {
        return isset(self::$meta[$id][$key]) ? self::$meta[$id][$key] : null;
    }
}

$txid = str_repeat('d', 64);
$db = new QA_DB();
$row = $db->insert_invoice(array(
    'invoice_no' => 'JU-RECOVERY',
    'payment_id' => 501,
    'zibll_order_num' => '52000000000501',
    'user_id' => 0,
    'local_amount' => '72.00000000',
    'usdt_amount' => '10.001234',
    'expected_raw' => '10001234',
    'status' => 'processing',
    'txid' => $txid,
    'actual_amount' => '10.001234',
));

ZibPay::$payment = array(
    'id' => 501,
    'order_num' => $row->zibll_order_num,
    'method' => 'usdt_trc20',
    'status' => '1',
    'pay_num' => $txid,
    'price' => 72,
);
ZibPay::$children = array(
    array('id' => 9001, 'status' => '1'),
    array('id' => 9002, 'status' => '0'),
);

$service = new JIULIU_USDT_Invoices($settings, $db, new QA_Rate($settings), new QA_Trongrid($settings));
$settle = new ReflectionMethod('JIULIU_USDT_Invoices', 'settle_zibll');
$settle->setAccessible(true);
$settle->invoke($service, $row, $txid, false);
$current = $db->get_invoice($row->id);

if ('paid' === $current->status) {
    fwrite(STDERR, "FAIL: incomplete child order was incorrectly marked paid\n");
    exit(1);
}
if ('zibll_settlement_uncertain' !== $current->error_code) {
    fwrite(STDERR, "FAIL: incomplete child order did not retain the expected review reason; got " . (string) $current->error_code . "\n");
    exit(1);
}

$complete_txid = str_repeat('e', 64);
$complete = $db->insert_invoice(array(
    'invoice_no' => 'JU-COMPLETE',
    'payment_id' => 502,
    'zibll_order_num' => '52000000000502',
    'user_id' => 0,
    'local_amount' => '72.00000000',
    'usdt_amount' => '10.001234',
    'expected_raw' => '10001234',
    'status' => 'processing',
    'txid' => $complete_txid,
    'actual_amount' => '10.001234',
));
ZibPay::$payment = array(
    'id' => 502,
    'order_num' => $complete->zibll_order_num,
    'method' => 'usdt_trc20',
    'status' => '1',
    'pay_num' => $complete_txid,
    'price' => 72,
);
ZibPay::$children = array(
    array('id' => 9011, 'status' => '1'),
    array('id' => 9012, 'status' => '1'),
);
ZibPay::$meta = array(
    9011 => array('jiuliu_usdt_success_hooks_completed' => 'yes'),
    9012 => array('jiuliu_usdt_success_hooks_completed' => 'yes'),
);

$settle->invoke($service, $complete, $complete_txid, false);
if ('paid' !== $db->get_invoice($complete->id)->status) {
    fwrite(STDERR, "FAIL: fully completed settlement did not transition to paid\n");
    exit(1);
}

// A retry after the atomic paid transition must not emit completion effects a
// second time.
$settle->invoke($service, $complete, $complete_txid, false);
$completion_logs = array_filter($db->logs, function ($log) {
    return 'payment_completed' === $log['event'];
});
if (1 !== count($completion_logs)) {
    fwrite(STDERR, "FAIL: idempotent retry emitted duplicate completion effects\n");
    exit(1);
}

fwrite(STDOUT, "PASS: settlement recovery, completion and idempotent retry\n");
