<?php

// Regression test for Zibll's rotating 520... cashier number: one stable
// payment_id must keep exactly one pending blockchain quote/invoice.

class ZibPay
{
    public static $payment = array(
        'order_num' => '52000000000001',
        'method' => 'usdt_trc20',
        'price' => 72,
        'status' => '0',
    );

    public static function get_payment($id) { return self::$payment; }
}

require_once __DIR__ . '/test-plugin-core.php';

$open_tab = $invoices->create_for_zibll(array(
    'order_num' => $invoice->zibll_order_num,
    'payment_id' => $invoice->payment_id,
    'user_id' => 7,
    'local_price' => 72,
));
if (is_wp_error($open_tab)) {
    fwrite(STDERR, "FAIL: could not capture the first cashier-tab token\n");
    exit(1);
}
$first_tab_token = $open_tab['public_token'];
$before = clone $open_tab['invoice'];
$before_count = count($db->rows);
$before_token_hash = $before->public_token_hash;

ZibPay::$payment = array(
    'order_num' => '52000000000999',
    'method' => 'usdt_trc20',
    'price' => 72,
    'status' => '0',
);
$reused = $invoices->create_for_zibll(array(
    // Deliberately stale callback number; the plugin must re-read payment_id.
    'order_num' => $before->zibll_order_num,
    'payment_id' => $before->payment_id,
    'user_id' => 7,
    'local_price' => 72,
));

if (is_wp_error($reused)) {
    fwrite(STDERR, 'FAIL: rotating order number returned ' . $reused->get_error_code() . "\n");
    exit(1);
}
$after = $reused['invoice'];
if ((int) $before->id !== (int) $after->id || $before_count !== count($db->rows)) {
    fwrite(STDERR, "FAIL: payment_id rotation created a second invoice\n");
    exit(1);
}
if ('52000000000999' !== $after->zibll_order_num) {
    fwrite(STDERR, "FAIL: reusable invoice did not adopt the current parent order number\n");
    exit(1);
}
foreach (array('expected_raw', 'usdt_amount', 'rate', 'created_at', 'expires_at') as $field) {
    if ((string) $before->{$field} !== (string) $after->{$field}) {
        fwrite(STDERR, "FAIL: stable quote field changed during payment_id reuse: {$field}\n");
        exit(1);
    }
}
if ($before_token_hash === $after->public_token_hash || hash('sha256', $reused['public_token']) !== $after->public_token_hash) {
    fwrite(STDERR, "FAIL: payment_id reuse did not rotate the public token safely\n");
    exit(1);
}
if (empty($after->previous_public_token_hash) || hash('sha256', $first_tab_token) !== $after->previous_public_token_hash) {
    fwrite(STDERR, "FAIL: payment_id reuse did not retain the immediately previous cashier token\n");
    exit(1);
}
$verify_token = new ReflectionMethod('JIULIU_USDT_Invoices', 'verify_public_token');
$verify_token->setAccessible(true);
if (!$verify_token->invoke($invoices, $after, $first_tab_token) || !$verify_token->invoke($invoices, $after, $reused['public_token'])) {
    fwrite(STDERR, "FAIL: current and immediately previous cashier tabs are not both usable\n");
    exit(1);
}

// A changed parent price must be rejected without mutating the stable quote.
ZibPay::$payment['order_num'] = '52000000001000';
ZibPay::$payment['price'] = 73;
$changed = $invoices->create_for_zibll(array(
    'order_num' => $after->zibll_order_num,
    'payment_id' => $after->payment_id,
    'user_id' => 7,
    'local_price' => 72,
));
if (!is_wp_error($changed) || 'zibll_price_changed' !== $changed->get_error_code()) {
    fwrite(STDERR, "FAIL: changed parent price was not rejected\n");
    exit(1);
}
if ($before_count !== count($db->rows) || '52000000000999' !== $db->get_invoice($after->id)->zibll_order_num) {
    fwrite(STDERR, "FAIL: rejected price change mutated or duplicated the invoice\n");
    exit(1);
}

fwrite(STDOUT, "PASS: payment_id single-invoice reuse, stable quote and price-change rejection\n");
