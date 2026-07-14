<?php

// Regression: public TXID submission obeys every operator/user-facing policy
// before consuming TronGrid quota. Administrator verification remains an
// explicit recovery path and is not disabled by those public switches.

require_once __DIR__ . '/test-plugin-core.php';

class QA_Public_Policy_DB_102 extends QA_DB
{
    public function acquire_check_slot($invoice_id, $seconds = 8) { return true; }
}

class QA_Public_Policy_Trongrid_102 extends QA_Trongrid
{
    public $txid_calls = 0;
    public function find_txid($address, $txid, $min_timestamp, $max_timestamp)
    {
        $this->txid_calls++;
        return new WP_Error('qa_admin_chain_reached', 'administrator reached chain verification');
    }
}

function qa_public_policy_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$policy_db = new QA_Public_Policy_DB_102();
$policy_seed = (array) $invoice;
unset($policy_seed['id']);
$policy_seed['invoice_no'] = 'JU-PUBLIC-POLICY-102';
$policy_seed['payment_id'] = 9301;
$policy_seed['zibll_order_num'] = '52000000009301';
$policy_seed['status'] = 'pending';
$policy_seed['txid'] = null;
$policy_seed['error_code'] = null;
$policy_invoice = $policy_db->insert_invoice($policy_seed);
$policy_trongrid = new QA_Public_Policy_Trongrid_102($settings);
$policy_service = new JIULIU_USDT_Invoices(
    $settings,
    $policy_db,
    new QA_Rate($settings),
    $policy_trongrid
);
$policy_txid = str_repeat('a', 64);

$policy_input = $valid_input;
$policy_input['frontend_manual_txid'] = 0;
$policy_input['pause_monitoring'] = 0;
$settings->update($policy_input);
$disabled = $policy_service->verify_public_txid($policy_invoice->id, $policy_txid, $created['public_token']);
if (!is_wp_error($disabled) || 'frontend_txid_disabled' !== $disabled->get_error_code() || $policy_trongrid->txid_calls) {
    qa_public_policy_fail_102('frontend_manual_txid=off did not block before TronGrid');
}
$disabled_admin = $policy_service->verify_admin_txid($policy_invoice->id, $policy_txid, true, false);
if (!is_wp_error($disabled_admin) || 'qa_admin_chain_reached' !== $disabled_admin->get_error_code() || 1 !== $policy_trongrid->txid_calls) {
    qa_public_policy_fail_102('frontend public-TXID switch incorrectly disabled administrator verification');
}
$policy_trongrid->txid_calls = 0;

$policy_input['frontend_manual_txid'] = 1;
$policy_input['pause_monitoring'] = 1;
$settings->update($policy_input);
$paused = $policy_service->verify_public_txid($policy_invoice->id, $policy_txid, $created['public_token']);
if (!is_wp_error($paused) || 'monitoring_paused' !== $paused->get_error_code() || $policy_trongrid->txid_calls) {
    qa_public_policy_fail_102('pause_monitoring=on did not block public TXID before TronGrid');
}
$paused_admin = $policy_service->verify_admin_txid($policy_invoice->id, $policy_txid, true, false);
if (!is_wp_error($paused_admin) || 'qa_admin_chain_reached' !== $paused_admin->get_error_code() || 1 !== $policy_trongrid->txid_calls) {
    qa_public_policy_fail_102('emergency monitoring pause incorrectly disabled administrator verification');
}
$policy_trongrid->txid_calls = 0;

$policy_input['pause_monitoring'] = 0;
$settings->update($policy_input);
$policy_db->update_invoice($policy_invoice->id, array('status' => 'closed_no_monitor'));
$closed = $policy_service->verify_public_txid($policy_invoice->id, $policy_txid, $created['public_token']);
if (!is_wp_error($closed) || 'closed_invoice_monitoring_disabled' !== $closed->get_error_code() || $policy_trongrid->txid_calls) {
    qa_public_policy_fail_102('closed_no_monitor public TXID did not block before TronGrid');
}

$admin = $policy_service->verify_admin_txid($policy_invoice->id, $policy_txid, true, false);
if (!is_wp_error($admin) || 'qa_admin_chain_reached' !== $admin->get_error_code() || 1 !== $policy_trongrid->txid_calls) {
    qa_public_policy_fail_102('administrator could not explicitly verify a publicly blocked invoice');
}

fwrite(STDOUT, "PASS: public TXID policy gates precede TronGrid while admin verification remains available\n");
