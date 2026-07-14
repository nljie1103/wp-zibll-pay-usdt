<?php

// Regression test: a unique-key race/retry must never return an already
// expired invoice to the cashier.

require_once __DIR__ . '/test-plugin-core.php';

class QA_Unique_DB extends QA_DB
{
    public function insert_invoice($data)
    {
        if ($this->get_by_order_num($data['zibll_order_num'])) {
            return new WP_Error('invoice_insert_failed', 'duplicate order number');
        }
        return parent::insert_invoice($data);
    }
}

$db = new QA_Unique_DB();
$old = $db->insert_invoice(array(
    'invoice_no' => 'JU-EXPIRED',
    'payment_id' => 701,
    'zibll_order_num' => '52000000000701',
    'user_id' => 7,
    'local_amount' => '72.00000000',
    'rate' => '7.20000000',
    'usdt_amount' => '10.001234',
    'expected_raw' => '10001234',
    'receive_address' => JIULIU_USDT_Settings::USDT_CONTRACT,
    'status' => 'expired',
    'public_token_hash' => hash('sha256', 'old'),
    'created_at' => JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - 3600),
    'expires_at' => JIULIU_USDT_Util::utc_mysql_from_timestamp(time() - 60),
    'updated_at' => JIULIU_USDT_Util::utc_now_mysql(),
));

$service = new JIULIU_USDT_Invoices($settings, $db, new QA_Rate($settings), new QA_Trongrid($settings));
$result = $service->create_for_zibll(array(
    'order_num' => $old->zibll_order_num,
    'payment_id' => $old->payment_id,
    'user_id' => 7,
    'local_price' => 72,
));

if (is_array($result) && isset($result['invoice'])) {
    $expires = JIULIU_USDT_Util::utc_timestamp_from_mysql($result['invoice']->expires_at);
    if ($expires < time()) {
        fwrite(STDERR, "FAIL: expired invoice was returned as a usable cashier quote\n");
        exit(1);
    }
}

fwrite(STDOUT, "PASS: expired invoice is never returned as a usable quote\n");
