<?php

// Static companion to the behavior test. It prevents a later refactor from
// moving the schema check below the expensive/irreversible chain-claim path.

function qa_schema_entry_contract_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_method_slice_102($source, $method, $next_method)
{
    $start = strpos($source, 'function ' . $method . '(');
    if (false === $start) {
        qa_schema_entry_contract_fail_102('missing method ' . $method);
    }
    $end = strpos($source, 'function ' . $next_method . '(', $start + 1);
    if (false === $end) {
        qa_schema_entry_contract_fail_102('missing boundary method ' . $next_method);
    }
    return substr($source, $start, $end - $start);
}

function qa_guard_precedes_102($slice, $danger, $label)
{
    $guard = strpos($slice, 'settlement_tables_are_transactional');
    $danger_position = strpos($slice, $danger);
    if (false === $guard || false === $danger_position || $guard >= $danger_position) {
        qa_schema_entry_contract_fail_102($label . ' does not guard before ' . $danger);
    }
}

$invoice_path = __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-invoices.php';
$cron_path = __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-cron.php';
$invoice_source = file_get_contents($invoice_path);
$cron_source = file_get_contents($cron_path);
if (false === $invoice_source || false === $cron_source) {
    qa_schema_entry_contract_fail_102('could not read chain entry-point implementations');
}

$check_order = qa_method_slice_102($invoice_source, 'check_order', 'verify_public_txid');
qa_guard_precedes_102($check_order, 'get_transfers(', 'check_order');

$verify = qa_method_slice_102($invoice_source, 'verify_txid_for_invoice', 'verify_public_token');
qa_guard_precedes_102($verify, 'find_txid(', 'manual verification');

$cron_run = qa_method_slice_102($cron_source, 'run', 'register_rest_route');
qa_guard_precedes_102($cron_run, 'scan_pending(', 'cron');

fwrite(STDOUT, "PASS: schema guards precede every chain-facing payment entry point\n");
