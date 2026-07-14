<?php

class ZibPay
{
    public static $payment = array(
        'id' => 101,
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
    );
    public static $orders = array();

    public static function get_payment($id) { return self::$payment; }
    public static function get_order($id, $fields = '')
    {
        return isset(self::$orders[$id]) ? self::$orders[$id] : array();
    }
}

require_once __DIR__ . '/test-plugin-core.php';
require_once __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-zibll.php';

// The admin checkbox must have a default and must survive settings updates.
$defaults = $settings->defaults();
if (!array_key_exists('monitor_closed_orders', $defaults) || 1 !== (int) $defaults['monitor_closed_orders']) {
    fwrite(STDERR, "FAIL: monitor_closed_orders is missing or not enabled by default\n");
    exit(1);
}
$monitor_input = $valid_input;
$monitor_input['monitor_closed_orders'] = 0;
$settings->update($monitor_input);
if (0 !== (int) $settings->get('monitor_closed_orders')) {
    fwrite(STDERR, "FAIL: disabled closed-order monitor was not persisted\n");
    exit(1);
}
$monitor_input['monitor_closed_orders'] = 1;
$settings->update($monitor_input);
if (1 !== (int) $settings->get('monitor_closed_orders')) {
    fwrite(STDERR, "FAIL: enabled closed-order monitor was not persisted\n");
    exit(1);
}

class QA_Close_Settings extends JIULIU_USDT_Settings
{
    public $monitor = true;
    public function get($key, $default = null)
    {
        if ('monitor_closed_orders' === $key) { return $this->monitor ? 1 : 0; }
        return parent::get($key, $default);
    }
}

class QA_Close_DB extends QA_DB
{
    public $close_calls = array();
    public function close_payment_invoices($payment_id, $monitor, $type = '', $reason = '')
    {
        $this->close_calls[] = compact('payment_id', 'monitor', 'type', 'reason');
        return 1;
    }
}

$close_settings = new QA_Close_Settings();
$close_db = new QA_Close_DB();
$close_service = new JIULIU_USDT_Invoices($close_settings, $close_db, new QA_Rate($close_settings), new QA_Trongrid($close_settings));
$zibll = new JIULIU_USDT_Zibll($close_settings, $close_db, $close_service);
ZibPay::$orders[7001] = array('payment_id' => 991);

$close_settings->monitor = true;
$zibll->handle_order_closed(7001, 'timeout', 'native timeout');
$close_settings->monitor = false;
$zibll->handle_order_closed(7001, 'manual', 'admin close');
if (2 !== count($close_db->close_calls)) {
    fwrite(STDERR, "FAIL: Zibll order_closed events were not mapped to plugin invoices\n");
    exit(1);
}
if (
    991 !== (int) $close_db->close_calls[0]['payment_id']
    || true !== (bool) $close_db->close_calls[0]['monitor']
    || false !== (bool) $close_db->close_calls[1]['monitor']
) {
    fwrite(STDERR, "FAIL: closed-order monitor choice was not forwarded correctly\n");
    exit(1);
}

class QA_Monitor_Scan_DB extends QA_DB
{
    public $include_closed;
    public function pending_for_scan($late_grace_hours = 24, $limit = 500, $include_closed = true)
    {
        $this->include_closed = (bool) $include_closed;
        return array();
    }
    public function expire_due() { return 0; }
}

$close_settings->monitor = false;
$scan_db = new QA_Monitor_Scan_DB();
$scanner = new JIULIU_USDT_Invoices($close_settings, $scan_db, new QA_Rate($close_settings), new QA_Trongrid($close_settings));
$scanner->scan_pending();
if (false !== $scan_db->include_closed) {
    fwrite(STDERR, "FAIL: disabled monitor still requested closed invoices for chain scanning\n");
    exit(1);
}

$close_settings->monitor = true;
$scanner->scan_pending();
if (true !== $scan_db->include_closed) {
    fwrite(STDERR, "FAIL: enabled monitor did not request closed invoices for chain scanning\n");
    exit(1);
}

fwrite(STDOUT, "PASS: closed-order event mapping and monitor switch behavior\n");
