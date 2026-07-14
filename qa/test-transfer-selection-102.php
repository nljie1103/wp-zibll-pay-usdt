<?php

// TronGrid is newest-first. A late duplicate must never take precedence over
// an earlier in-window payment with the same exact six-decimal amount.

require_once __DIR__ . '/test-plugin-core.php';

class QA_Transfer_Select_DB extends QA_DB
{
    public $scan_rows = array();
    public function acquire_check_slot($id, $seconds = 8) { return true; }
    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true) { return $this->scan_rows; }
    public function payment_ids_due_for_zibll_close($limit = 500) { return array(); }
    public function expire_due() { return 0; }
}

class QA_Transfer_Select_Chain extends JIULIU_USDT_Trongrid
{
    public $transfers = array();
    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15)
    {
        return $this->transfers;
    }
}

function qa_transfer_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_transfer_invoice(QA_Transfer_Select_DB $db, $number, $payment_id)
{
    return $db->insert_invoice(array(
        'invoice_no' => 'JU-SELECT-' . $payment_id,
        'payment_id' => $payment_id,
        'zibll_order_num' => $number,
        'user_id' => 0,
        'local_amount' => '72.00000000',
        'receive_address' => JIULIU_USDT_Settings::USDT_CONTRACT,
        'usdt_amount' => '10.001234',
        'expected_raw' => '10001234',
        'status' => 'closed',
        'txid' => null,
        'created_at' => JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - 3600),
        'expires_at' => JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - 1800),
        'updated_at' => JIULIU_USDT_Util::utc_now_mysql(),
    ));
}

$valid_txid = str_repeat('a', 64);
$late_txid = str_repeat('b', 64);
$transfer_settings = new JIULIU_USDT_Settings();
$transfer_input = $valid_input;
$transfer_input['monitor_closed_orders'] = 1;
$transfer_input['admin_email_notifications'] = 0;
$transfer_input['user_email_notifications'] = 0;
$transfer_settings->update($transfer_input);
$newest_first = array(
    array(
        'transaction_id' => $late_txid,
        'from' => 'TLate',
        'value' => '10001234',
        'block_timestamp' => (time() - 60) * 1000,
    ),
    array(
        'transaction_id' => $valid_txid,
        'from' => 'TValid',
        'value' => '10001234',
        'block_timestamp' => (time() - 2000) * 1000,
    ),
);

$check_db = new QA_Transfer_Select_DB();
$check_invoice = qa_transfer_invoice($check_db, '52000000000701', 701);
$check_chain = new QA_Transfer_Select_Chain($transfer_settings);
$check_chain->transfers = $newest_first;
$check_service = new JIULIU_USDT_Invoices($transfer_settings, $check_db, new QA_Rate($transfer_settings), $check_chain);
$check_result = $check_service->check_order($check_invoice->zibll_order_num, false);
if (!is_object($check_result) || 'review' !== $check_result->status || $valid_txid !== $check_result->txid) {
    qa_transfer_fail('single-order check selected the newer late duplicate instead of the valid transfer');
}

$scan_db = new QA_Transfer_Select_DB();
$scan_invoice = qa_transfer_invoice($scan_db, '52000000000702', 702);
$scan_db->scan_rows = array($scan_invoice);
$scan_chain = new QA_Transfer_Select_Chain($transfer_settings);
$scan_chain->transfers = $newest_first;
$scan_service = new JIULIU_USDT_Invoices($transfer_settings, $scan_db, new QA_Rate($transfer_settings), $scan_chain);
$stats = $scan_service->scan_pending();
$scan_current = $scan_db->get_invoice($scan_invoice->id);
if ($valid_txid !== $scan_current->txid || 1 !== $stats['review'] || 0 !== $stats['errors']) {
    qa_transfer_fail('scheduled scan did not select/count the valid transfer and review state correctly');
}

fwrite(STDOUT, "PASS: valid-before-late transfer selection and scan statistics\n");
