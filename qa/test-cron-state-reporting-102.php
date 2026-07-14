<?php

// Regression: disabling new USDT quotes must not abandon existing invoices.
// The dedicated emergency pause is the only setting that stops scans, and the
// REST endpoint must expose paused/busy/error as non-success states.

$GLOBALS['qa_cron_hooks_102'] = array();

function add_action($tag, $callback, $priority = 10, $accepted_args = 1)
{
    $GLOBALS['qa_cron_hooks_102'][$tag][] = $callback;
    return true;
}
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function wp_next_scheduled($event) { return time() + 30; }
function wp_schedule_event($timestamp, $recurrence, $event) { return true; }

class QA_REST_Response_102
{
    private $data;
    private $status = 200;

    public function __construct($data) { $this->data = $data; }
    public function get_data() { return $this->data; }
    public function set_status($status) { $this->status = (int) $status; }
    public function get_status() { return $this->status; }
}

function rest_ensure_response($data) { return new QA_REST_Response_102($data); }

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-cron.php';

class QA_Cron_State_DB_102 extends QA_DB
{
    public function recover_stale_processing($minutes = 5) { return 0; }
    public function release_old_active_keys($late_grace_hours = 24) { return 0; }
    public function delete_old_logs($days) { return 0; }
}

class QA_Cron_State_Invoices_102 extends JIULIU_USDT_Invoices
{
    public $mode = 'ok';
    public $scan_calls = 0;

    public function scan_pending()
    {
        $this->scan_calls++;
        if ('error' === $this->mode) {
            throw new Exception('simulated scan failure');
        }
        return array('checked' => 1, 'paid' => 0, 'review' => 0, 'errors' => 0, 'closed' => 0);
    }
}

function qa_cron_state_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_cron_response_data_102($response)
{
    if (!is_object($response) || !is_callable(array($response, 'get_data'))) {
        qa_cron_state_fail_102('REST cron did not return a response object');
    }
    return $response->get_data();
}

$cron_input = $valid_input;
$cron_input['enabled'] = 0;
$cron_input['pause_monitoring'] = 0;
$settings->update($cron_input);

$cron_db = new QA_Cron_State_DB_102();
$cron_scanner = new QA_Cron_State_Invoices_102(
    $settings,
    $cron_db,
    new QA_Rate($settings),
    new QA_Trongrid($settings)
);
$cron = new JIULIU_USDT_Cron($settings, $cron_db, $cron_scanner);

$disabled_result = $cron->run();
if (1 !== $cron_scanner->scan_calls || !isset($disabled_result['checked']) || 1 !== $disabled_result['checked']) {
    qa_cron_state_fail_102('enabled=off abandoned invoices that were already issued');
}

$cron_input['pause_monitoring'] = 1;
$settings->update($cron_input);
$paused_before = $cron_scanner->scan_calls;
$paused_run = $cron->run();
if (empty($paused_run['paused']) || $paused_before !== $cron_scanner->scan_calls) {
    qa_cron_state_fail_102('pause_monitoring did not stop the existing-invoice scan');
}
$paused_response = $cron->rest_run();
$paused_data = qa_cron_response_data_102($paused_response);
if (!empty($paused_data['success']) || 'paused' !== $paused_data['status']) {
    qa_cron_state_fail_102('REST cron falsely reported a paused scan as successful');
}

$cron_input['pause_monitoring'] = 0;
$settings->update($cron_input);
set_transient('jiuliu_usdt_scan_lock', 1);
$busy_response = $cron->rest_run();
$busy_data = qa_cron_response_data_102($busy_response);
if (!empty($busy_data['success']) || 'busy' !== $busy_data['status']) {
    qa_cron_state_fail_102('REST cron falsely reported a busy scan as successful');
}
delete_transient('jiuliu_usdt_scan_lock');

$cron_scanner->mode = 'error';
$error_response = $cron->rest_run();
$error_data = qa_cron_response_data_102($error_response);
if (!empty($error_data['success']) || 'error' !== $error_data['status']) {
    qa_cron_state_fail_102('REST cron falsely reported a failed scan as successful');
}
if (503 !== $error_response->get_status()) {
    qa_cron_state_fail_102('REST cron error did not use HTTP 503');
}
if ($cron_db->scan_locked || get_transient('jiuliu_usdt_scan_lock')) {
    qa_cron_state_fail_102('failed cron scan leaked its database or transient lock');
}

fwrite(STDOUT, "PASS: cron new-order switch, emergency pause and REST state reporting\n");
