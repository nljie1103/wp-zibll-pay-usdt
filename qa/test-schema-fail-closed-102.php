<?php

// Behavior regression: every chain-facing payment entry point must stop before
// querying TronGrid or claiming a transaction when the financial tables cannot
// provide transactional settlement guarantees.

$GLOBALS['qa_schema_hooks_102'] = array();
function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['qa_schema_hooks_102'][$tag][] = $callback;
    return true;
}
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function wp_next_scheduled($event) { return time() + 30; }
function wp_schedule_event($timestamp, $recurrence, $event) { return true; }

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-cron.php';

class QA_Schema_Guard_DB_102 extends QA_DB
{
    public $claim_calls = 0;

    public function settlement_tables_are_transactional()
    {
        return new WP_Error('qa_schema_unsafe', 'simulated unsafe financial schema');
    }

    public function acquire_check_slot($invoice_id, $seconds = 8) { return true; }
    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true)
    {
        return array_values($this->rows);
    }
    public function expire_due() { return 0; }
    public function payment_ids_due_for_zibll_close($limit = 500) { return array(); }
    public function recover_stale_processing($minutes = 5) { return 0; }
    public function release_old_active_keys($late_grace_hours = 24) { return 0; }
    public function delete_old_logs($days) { return 0; }

    public function claim_invoice($id, $txid, $transfer, $allowed_statuses, $preserve_error_code = false)
    {
        $this->claim_calls++;
        return false;
    }
}

class QA_Schema_Guard_Trongrid_102 extends QA_Trongrid
{
    public $history_calls = 0;
    public $txid_calls = 0;
    public $transfer;

    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15)
    {
        $this->history_calls++;
        return array($this->transfer);
    }

    public function find_txid($address, $txid, $min_timestamp, $max_timestamp, $max_pages = 8, $timeout = 15)
    {
        $this->txid_calls++;
        return $this->transfer;
    }
}

function qa_schema_guard_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$schema_db = new QA_Schema_Guard_DB_102();
$seed = (array) $invoice;
unset($seed['id']);
$seed['invoice_no'] = 'JU-SCHEMA-GUARD-102';
$seed['payment_id'] = 8202;
$seed['zibll_order_num'] = '52000000008202';
$seed['status'] = 'pending';
$seed['txid'] = null;
$seed['error_code'] = null;
$guard_invoice = $schema_db->insert_invoice($seed);
$guard_txid = str_repeat('f', 64);
$schema_trongrid = new QA_Schema_Guard_Trongrid_102($settings);
$schema_trongrid->transfer = array(
    'transaction_id' => $guard_txid,
    'from' => 'TFromAddress',
    'to' => $guard_invoice->receive_address,
    'value' => $guard_invoice->expected_raw,
    'block_timestamp' => time() * 1000,
);
$schema_service = new JIULIU_USDT_Invoices(
    $settings,
    $schema_db,
    new QA_Rate($settings),
    $schema_trongrid
);

$check_result = $schema_service->check_order($guard_invoice->zibll_order_num, false);
if (!is_wp_error($check_result) || 'qa_schema_unsafe' !== $check_result->get_error_code()) {
    qa_schema_guard_fail_102('check_order did not expose the transactional-schema failure');
}
if ($schema_trongrid->history_calls || $schema_trongrid->txid_calls || $schema_db->claim_calls) {
    qa_schema_guard_fail_102('check_order reached chain lookup or claim with an unsafe schema');
}

$public_result = $schema_service->verify_public_txid(
    $guard_invoice->id,
    $guard_txid,
    $created['public_token']
);
$admin_result = $schema_service->verify_admin_txid($guard_invoice->id, $guard_txid, true, false);
if (
    !is_wp_error($public_result)
    || 'qa_schema_unsafe' !== $public_result->get_error_code()
    || !is_wp_error($admin_result)
    || 'qa_schema_unsafe' !== $admin_result->get_error_code()
) {
    qa_schema_guard_fail_102('manual verification did not expose the transactional-schema failure');
}
if ($schema_trongrid->history_calls || $schema_trongrid->txid_calls || $schema_db->claim_calls) {
    qa_schema_guard_fail_102('manual verification reached chain lookup or claim with an unsafe schema');
}

$cron_input = $valid_input;
$cron_input['pause_monitoring'] = 0;
$settings->update($cron_input);
$cron = new JIULIU_USDT_Cron($settings, $schema_db, $schema_service);
$cron_result = $cron->run();
if (!is_array($cron_result) || empty($cron_result['error'])) {
    qa_schema_guard_fail_102('cron did not report the transactional-schema failure');
}
if ($schema_trongrid->history_calls || $schema_trongrid->txid_calls || $schema_db->claim_calls) {
    qa_schema_guard_fail_102('cron reached chain lookup or claim with an unsafe schema');
}
if ($schema_db->scan_locked || get_transient('jiuliu_usdt_scan_lock')) {
    qa_schema_guard_fail_102('schema-failed cron leaked a scan lock');
}

fwrite(STDOUT, "PASS: unsafe settlement schema blocks polling, verification and cron before chain access\n");
