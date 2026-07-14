<?php

// Regression: checking the high-risk acknowledgement without also choosing
// force settlement must not replay or downgrade an uncertain Zibll settlement.

require_once __DIR__ . '/test-plugin-core.php';

class QA_Uncertain_Trongrid_102 extends QA_Trongrid
{
    public $find_calls = 0;

    public function find_txid($address, $txid, $min_timestamp, $max_timestamp, $max_pages = 8, $timeout = 15)
    {
        $this->find_calls++;
        return new WP_Error('unexpected_chain_lookup', 'guard should run before the chain lookup');
    }
}

function qa_uncertain_guard_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$uncertain_db = new QA_DB();
$uncertain_txid = str_repeat('d', 64);
$uncertain_invoice = $uncertain_db->insert_invoice(array(
    'invoice_no' => 'JU-UNCERTAIN-102',
    'payment_id' => 7102,
    'zibll_order_num' => '52000000007102',
    'status' => 'review',
    'txid' => $uncertain_txid,
    'error_code' => 'zibll_settlement_uncertain',
));
$uncertain_trongrid = new QA_Uncertain_Trongrid_102($settings);
$uncertain_service = new JIULIU_USDT_Invoices(
    $settings,
    $uncertain_db,
    new QA_Rate($settings),
    $uncertain_trongrid
);

$result = $uncertain_service->verify_admin_txid(
    $uncertain_invoice->id,
    $uncertain_txid,
    false,
    true
);
$current = $uncertain_db->get_invoice($uncertain_invoice->id);

if (!is_wp_error($result) || 'uncertain_settlement_force_required' !== $result->get_error_code()) {
    qa_uncertain_guard_fail_102('admin confirmation without force did not fail closed');
}
if (0 !== $uncertain_trongrid->find_calls) {
    qa_uncertain_guard_fail_102('admin confirmation without force reached the chain/replay path');
}
if (
    !$current
    || 'review' !== $current->status
    || 'zibll_settlement_uncertain' !== $current->error_code
    || $uncertain_txid !== $current->txid
) {
    qa_uncertain_guard_fail_102('failed recovery attempt downgraded or mutated the uncertain state');
}

fwrite(STDOUT, "PASS: uncertain settlement requires both admin confirmation and force\n");
