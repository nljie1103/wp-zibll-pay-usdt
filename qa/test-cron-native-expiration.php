<?php

// Regression test: the plugin's scheduled scan must exercise Zibll's native
// payment-expiry helper even when Zibll's optional pending-order monitor/floating
// reminder is disabled and no browser visits the cashier.

$GLOBALS['qa_native_expiry_phase'] = 'bootstrap';
$GLOBALS['qa_native_expiry_calls'] = 0;
$GLOBALS['qa_monitor_enabled'] = false;

function add_filter($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_action($tag, $callback, $priority = 10, $accepted_args = 1) { return true; }
function wp_next_scheduled($event) { return time() + 30; }
function wp_schedule_event($timestamp, $recurrence, $event) { return true; }

class ZibPay
{
    public static $payment = array(
        'id' => 101,
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
    );
    public static $closed = 0;

    public static function get_payment($id) { return self::$payment; }
    public static function close_payment($id, $type = 'timeout', $reason = '')
    {
        self::$closed++;
        self::$payment['status'] = -1;
        return true;
    }
}

function zibpay_get_payment_pay_over_time(array &$payment)
{
    $GLOBALS['qa_native_expiry_calls']++;
    if ('expired' === $GLOBALS['qa_native_expiry_phase']) {
        ZibPay::close_payment($payment['id'], 'timeout', 'QA timeout');
        $payment['status'] = -1;
        return 'over';
    }
    return strtotime(current_time('mysql')) + 1800;
}

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-cron.php';

class QA_Scan_DB extends QA_DB
{
    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true)
    {
        return array_values(array_filter($this->rows, function ($row) {
            return in_array($row->status, array('pending', 'expired', 'superseded'), true);
        }));
    }
    public function expire_due()
    {
        $count = 0;
        foreach ($this->rows as $row) {
            if ('pending' === $row->status && JIULIU_USDT_Util::utc_timestamp_from_mysql($row->expires_at) < time()) {
                $row->status = 'expired';
                $count++;
            }
        }
        return $count;
    }
    public function recover_stale_processing($minutes = 5) { return 0; }
    public function release_old_active_keys($late_grace_hours = 24) { return 0; }
    public function delete_old_logs($days) { return 0; }
    public function payment_ids_due_for_zibll_close($limit = 500) { return array(101); }
}

class QA_Empty_Trongrid extends JIULIU_USDT_Trongrid
{
    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15) { return array(); }
}

$scan_db = new QA_Scan_DB();
$seed = (array) $invoice;
unset($seed['id']);
$seed['status'] = 'pending';
$seed['payment_id'] = 101;
$seed['zibll_order_num'] = ZibPay::$payment['order_num'];
$scan_invoice = $scan_db->insert_invoice($seed);
$scanner = new JIULIU_USDT_Invoices($settings, $scan_db, new QA_Rate($settings), new QA_Empty_Trongrid($settings));

$GLOBALS['qa_native_expiry_phase'] = 'expired';
$GLOBALS['qa_native_expiry_calls'] = 0;
ZibPay::$closed = 0;
$cron = new JIULIU_USDT_Cron($settings, $scan_db, $scanner);
$cron->run();

if ($GLOBALS['qa_monitor_enabled']) {
    fwrite(STDERR, "FAIL: test precondition expected the Zibll monitor to be disabled\n");
    exit(1);
}
if ($GLOBALS['qa_native_expiry_calls'] < 1 || 1 !== ZibPay::$closed) {
    fwrite(STDERR, "FAIL: scheduled scan did not trigger Zibll native payment expiration\n");
    exit(1);
}

fwrite(STDOUT, "PASS: scheduled scan triggers native Zibll expiration with monitor disabled\n");
