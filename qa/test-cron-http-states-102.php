<?php

// Regression: the external monitor endpoint must never report partial work,
// emergency pause, lock contention, or exceptions as HTTP-200 success.

function add_action($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function wp_next_scheduled($event) { return time() + 30; }
function wp_schedule_event($timestamp, $recurrence, $event) { return true; }

class QA_Cron_HTTP_Response_102
{
    private $data;
    private $status = 200;
    public function __construct($data) { $this->data = $data; }
    public function get_data() { return $this->data; }
    public function set_status($status) { $this->status = (int) $status; }
    public function get_status() { return $this->status; }
}
function rest_ensure_response($data) { return new QA_Cron_HTTP_Response_102($data); }

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-cron.php';

class QA_Cron_HTTP_DB_102 extends QA_DB
{
    public function recover_stale_processing($minutes = 5) { return 0; }
    public function release_old_active_keys($late_grace_hours = 24) { return 0; }
    public function delete_old_logs($days) { return 0; }
}

class QA_Cron_HTTP_Invoices_102 extends JIULIU_USDT_Invoices
{
    public $mode = 'ok';
    public function scan_pending()
    {
        if ('error' === $this->mode) {
            throw new Exception('simulated cron exception');
        }
        if ('partial' === $this->mode) {
            return array('checked' => 3, 'paid' => 1, 'review' => 0, 'errors' => 1, 'closed' => 0);
        }
        return array('checked' => 1, 'paid' => 0, 'review' => 0, 'errors' => 0, 'closed' => 0);
    }
}

function qa_cron_http_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_cron_http_assert_102($response, $status, $http)
{
    $data = $response->get_data();
    if (
        !empty($data['success'])
        || $status !== $data['status']
        || $http !== $response->get_status()
    ) {
        qa_cron_http_fail_102($status . ' returned a false success or incorrect HTTP status');
    }
}

$cron_http_input = $valid_input;
$cron_http_input['pause_monitoring'] = 0;
$settings->update($cron_http_input);
$cron_http_db = new QA_Cron_HTTP_DB_102();
$cron_http_scanner = new QA_Cron_HTTP_Invoices_102(
    $settings,
    $cron_http_db,
    new QA_Rate($settings),
    new QA_Trongrid($settings)
);
$cron_http = new JIULIU_USDT_Cron($settings, $cron_http_db, $cron_http_scanner);

$cron_http_scanner->mode = 'partial';
qa_cron_http_assert_102($cron_http->rest_run(), 'partial', 503);

$cron_http_input['pause_monitoring'] = 1;
$settings->update($cron_http_input);
qa_cron_http_assert_102($cron_http->rest_run(), 'paused', 503);

$cron_http_input['pause_monitoring'] = 0;
$settings->update($cron_http_input);
set_transient('jiuliu_usdt_scan_lock', 1);
qa_cron_http_assert_102($cron_http->rest_run(), 'busy', 409);
delete_transient('jiuliu_usdt_scan_lock');

$cron_http_scanner->mode = 'error';
qa_cron_http_assert_102($cron_http->rest_run(), 'error', 503);

$cron_http_scanner->mode = 'ok';
$ok = $cron_http->rest_run();
$ok_data = $ok->get_data();
if (empty($ok_data['success']) || 'ok' !== $ok_data['status'] || 200 !== $ok->get_status()) {
    qa_cron_http_fail_102('healthy cron did not return HTTP-200 success');
}

fwrite(STDOUT, "PASS: cron partial, paused, busy, error and healthy HTTP semantics\n");
