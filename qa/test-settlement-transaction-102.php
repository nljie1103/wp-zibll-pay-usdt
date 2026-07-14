<?php

// Deterministic regression coverage for the 1.0.2 settlement transaction.
// The DB shim snapshots invoice rows so rollback/commit order can be asserted.

require_once __DIR__ . '/test-plugin-core.php';

class ZibPay
{
    public static $payment = array(
        'id' => 101,
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
        'pay_num' => '',
    );
    public static $children = array(
        array('id' => 1011, 'status' => '0', 'pay_price' => 72),
    );
    public static $meta = array();
    public static $payment_order_calls = 0;
    public static $behavior = 'success';

    public static function get_payment($id) { return self::$payment; }
    public static function get_order_by_payment_id($id, $fields = '') { return self::$children; }
    public static function get_meta($id, $key)
    {
        return isset(self::$meta[$id][$key]) ? self::$meta[$id][$key] : null;
    }
    public static function payment_order($data)
    {
        self::$payment_order_calls++;
        if ('throw' === self::$behavior) {
            throw new Exception('simulated success-hook exception');
        }
        if ('false' === self::$behavior) {
            return false;
        }

        self::$payment['status'] = '1';
        self::$payment['pay_num'] = $data['pay_num'];
        foreach (self::$children as $index => $child) {
            self::$children[$index]['status'] = '1';
            self::$meta[$child['id']]['jiuliu_usdt_success_hooks_completed'] = 'yes';
        }
        return true;
    }
}

function qa_tx_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_tx_seed(QA_DB $db, $payment_id, $txid)
{
    return $db->insert_invoice(array(
        'invoice_no' => 'JU-TX-' . $payment_id,
        'payment_id' => $payment_id,
        'zibll_order_num' => '5200000000' . $payment_id,
        'user_id' => 0,
        'local_amount' => '72.00000000',
        'receive_address' => JIULIU_USDT_Settings::USDT_CONTRACT,
        'usdt_amount' => '10.001234',
        'expected_raw' => '10001234',
        'status' => 'processing',
        'txid' => $txid,
        'actual_amount' => '10.001234',
        'error_code' => null,
    ));
}

function qa_tx_theme_pending($payment_id, $order_num)
{
    ZibPay::$payment = array(
        'id' => $payment_id,
        'order_num' => $order_num,
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
        'pay_num' => '',
    );
    ZibPay::$children = array(
        array('id' => $payment_id * 10 + 1, 'status' => '0', 'pay_price' => 32),
        array('id' => $payment_id * 10 + 2, 'status' => '0', 'pay_price' => 40),
    );
    ZibPay::$meta = array();
    ZibPay::$payment_order_calls = 0;
    ZibPay::$behavior = 'success';
}

$settle = new ReflectionMethod('JIULIU_USDT_Invoices', 'settle_zibll');
$settle->setAccessible(true);

// The optimistic state can be pending while the locked snapshot is already
// closed. The theme settlement function must never be reached.
$closed_db = new QA_DB();
$closed_txid = str_repeat('1', 64);
$closed_invoice = qa_tx_seed($closed_db, 601, $closed_txid);
qa_tx_theme_pending(601, $closed_invoice->zibll_order_num);
$closed_db->guard_payment = ZibPay::$payment;
$closed_db->guard_payment['status'] = '-1';
$closed_db->guard_children = ZibPay::$children;
$closed_service = new JIULIU_USDT_Invoices($settings, $closed_db, new QA_Rate($settings), new QA_Trongrid($settings));
$settle->invoke($closed_service, $closed_invoice, $closed_txid, false);
$closed_current = $closed_db->get_invoice($closed_invoice->id);
if (0 !== ZibPay::$payment_order_calls || 'review' !== $closed_current->status || 'zibll_payment_closed' !== $closed_current->error_code) {
    qa_tx_fail('locked closed-parent snapshot did not abort before theme settlement');
}
if (array('begin', 'rollback') !== $closed_db->transaction_events || $closed_db->settlement_locks) {
    qa_tx_fail('closed-parent path did not rollback and release its settlement lock');
}

// A child can close between an earlier read and the transaction. The locked
// child snapshot is authoritative and prevents partial parent/child delivery.
$child_db = new QA_DB();
$child_txid = str_repeat('2', 64);
$child_invoice = qa_tx_seed($child_db, 602, $child_txid);
qa_tx_theme_pending(602, $child_invoice->zibll_order_num);
$child_db->guard_payment = ZibPay::$payment;
$child_db->guard_children = ZibPay::$children;
$child_db->guard_children[1]['status'] = '-1';
$child_service = new JIULIU_USDT_Invoices($settings, $child_db, new QA_Rate($settings), new QA_Trongrid($settings));
$settle->invoke($child_service, $child_invoice, $child_txid, false);
$child_current = $child_db->get_invoice($child_invoice->id);
if (0 !== ZibPay::$payment_order_calls || 'zibll_child_not_pending' !== $child_current->error_code) {
    qa_tx_fail('locked closed-child snapshot did not abort before theme settlement');
}

// Successful theme delivery and the plugin paid CAS must commit together.
$success_db = new QA_DB();
$success_txid = str_repeat('3', 64);
$success_invoice = qa_tx_seed($success_db, 603, $success_txid);
qa_tx_theme_pending(603, $success_invoice->zibll_order_num);
$success_service = new JIULIU_USDT_Invoices($settings, $success_db, new QA_Rate($settings), new QA_Trongrid($settings));
$success_result = $settle->invoke($success_service, $success_invoice, $success_txid, false);
if (!is_object($success_result) || 'paid' !== $success_db->get_invoice($success_invoice->id)->status) {
    qa_tx_fail('successful guarded settlement did not mark the invoice paid');
}
if (1 !== ZibPay::$payment_order_calls || array('begin', 'commit') !== $success_db->transaction_events) {
    qa_tx_fail('successful settlement did not follow begin -> theme -> commit exactly once');
}

// Once the theme call has started, false/exception/ambiguous commit can have
// non-transactional side effects. They must all become the non-replayable
// uncertain review state, never paid or ordinary retryable review.
foreach (array('false', 'throw', 'commit') as $case) {
    $case_db = new QA_DB();
    $case_txid = str_repeat('4', 63) . ('false' === $case ? 'a' : ('throw' === $case ? 'b' : 'c'));
    $case_id = 'false' === $case ? 604 : ('throw' === $case ? 605 : 606);
    $case_invoice = qa_tx_seed($case_db, $case_id, $case_txid);
    qa_tx_theme_pending($case_id, $case_invoice->zibll_order_num);
    if ('commit' === $case) {
        $case_db->commit_result = false;
    } else {
        ZibPay::$behavior = $case;
    }
    $case_service = new JIULIU_USDT_Invoices($settings, $case_db, new QA_Rate($settings), new QA_Trongrid($settings));
    $settle->invoke($case_service, $case_invoice, $case_txid, false);
    $case_current = $case_db->get_invoice($case_invoice->id);
    if ('review' !== $case_current->status || 'zibll_settlement_uncertain' !== $case_current->error_code) {
        qa_tx_fail($case . ' path did not enter non-replayable uncertain review');
    }
    $completion_logs = array_filter($case_db->logs, function ($log) {
        return 'payment_completed' === $log['event'];
    });
    if ($completion_logs || $case_db->settlement_locks) {
        qa_tx_fail($case . ' path emitted completion effects or leaked its lock');
    }
}

// A public replay of an uncertain invoice must fail before another chain call
// or theme settlement. Only the administrator's explicit confirmation is able
// to enter the recovery path.
$verify = new ReflectionMethod('JIULIU_USDT_Invoices', 'verify_txid_for_invoice');
$verify->setAccessible(true);
$uncertain = $case_db->get_invoice($case_invoice->id);
$blocked = $verify->invoke($case_service, $uncertain, $case_txid, false, 'public', false);
if (!is_wp_error($blocked) || 'uncertain_settlement_confirmation_required' !== $blocked->get_error_code()) {
    qa_tx_fail('uncertain settlement was replayable without administrator confirmation');
}

fwrite(STDOUT, "PASS: locked snapshots, atomic commit, rollback and uncertain replay guard\n");
