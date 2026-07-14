<?php

// Browser polling must use the short one-page/5-second chain request and a
// compare-and-set slot; explicit admin checks may use the configured deep path.

require_once __DIR__ . '/test-plugin-core.php';

class QA_Check_DB extends QA_DB
{
    public $slot_calls = 0;
    private $occupied = false;

    public function acquire_check_slot($id, $seconds = 8)
    {
        $this->slot_calls++;
        if ($this->occupied) { return false; }
        $this->occupied = true;
        return true;
    }
}

class QA_Capture_Trongrid extends JIULIU_USDT_Trongrid
{
    public $calls = array();
    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15)
    {
        $this->calls[] = compact('address', 'min_timestamp', 'max_timestamp', 'max_pages', 'timeout');
        return array();
    }
}

$db = new QA_Check_DB();
$seed = (array) $invoice;
unset($seed['id']);
$seed['invoice_no'] = 'JU-CHECK-TIMEOUT';
$seed['payment_id'] = 950;
$seed['zibll_order_num'] = '52000000000950';
$seed['status'] = 'pending';
$row = $db->insert_invoice($seed);
$chain = new QA_Capture_Trongrid($settings);
$service = new JIULIU_USDT_Invoices($settings, $db, new QA_Rate($settings), $chain);

$service->check_order($row->zibll_order_num, false);
$service->check_order($row->zibll_order_num, false);
if (2 !== $db->slot_calls || 1 !== count($chain->calls)) {
    fwrite(STDERR, "FAIL: concurrent browser polling was not collapsed by the check slot\n");
    exit(1);
}
if (1 !== (int) $chain->calls[0]['max_pages'] || 5 !== (int) $chain->calls[0]['timeout']) {
    fwrite(STDERR, "FAIL: browser polling did not use the bounded TronGrid request\n");
    exit(1);
}

$service->check_order($row->zibll_order_num, true);
if (2 !== count($chain->calls)) {
    fwrite(STDERR, "FAIL: forced admin check did not reach TronGrid\n");
    exit(1);
}
if (10 !== (int) $chain->calls[1]['max_pages'] || 15 !== (int) $chain->calls[1]['timeout']) {
    fwrite(STDERR, "FAIL: forced admin check did not use the deep TronGrid request\n");
    exit(1);
}

fwrite(STDOUT, "PASS: check-slot concurrency throttle and browser/admin request bounds\n");
