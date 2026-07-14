<?php

// Deterministic interleaving tests for the two financial uniqueness guards:
// one invoice winner per order race and one invoice owner per chain txid race.

require_once __DIR__ . '/test-plugin-core.php';

// A second request for the same payment_id must fail fast while the quote lock
// is owned, and every early return must release an acquired lock.
$lock_db = new QA_DB();
$lock_service = new JIULIU_USDT_Invoices($settings, $lock_db, new QA_Rate($settings), new QA_Trongrid($settings));
$lock_db->payment_locks[810] = true;
$busy = $lock_service->create_for_zibll(array(
    'order_num' => '52000000000810',
    'payment_id' => 810,
    'user_id' => 8,
    'local_price' => 72,
));
if (!is_wp_error($busy) || 'quote_busy' !== $busy->get_error_code() || $lock_db->rows) {
    fwrite(STDERR, "FAIL: held payment_id lock did not reject the competing quote cleanly\n");
    exit(1);
}
unset($lock_db->payment_locks[810]);
$out_of_range = $lock_service->create_for_zibll(array(
    'order_num' => '52000000000811',
    'payment_id' => 811,
    'user_id' => 8,
    'local_price' => 0.5,
));
if (!is_wp_error($out_of_range) || 'amount_out_of_range' !== $out_of_range->get_error_code()) {
    fwrite(STDERR, "FAIL: lock early-return precondition did not reach amount validation\n");
    exit(1);
}
if (!empty($lock_db->payment_locks[811])) {
    fwrite(STDERR, "FAIL: payment_id lock leaked after an early validation return\n");
    exit(1);
}

class QA_Low_Min_Settings extends JIULIU_USDT_Settings
{
    public function get($key, $default = null)
    {
        if ('minimum_local_amount' === $key) { return '0.01'; }
        return parent::get($key, $default);
    }
}
$tail_settings = new QA_Low_Min_Settings();
$tail_db = new QA_DB();
$tail_service = new JIULIU_USDT_Invoices($tail_settings, $tail_db, new QA_Rate($tail_settings), new QA_Trongrid($tail_settings));
$tiny = $tail_service->create_for_zibll(array(
    'order_num' => '52000000000812',
    'payment_id' => 812,
    'user_id' => 8,
    'local_price' => 0.1,
));
if (!is_wp_error($tiny) || 'amount_too_small_for_unique_quote' !== $tiny->get_error_code()) {
    fwrite(STDERR, "FAIL: unsafe tiny unique-tail space was not rejected\n");
    exit(1);
}
$threshold = $tail_service->create_for_zibll(array(
    'order_num' => '52000000000813',
    'payment_id' => 813,
    'user_id' => 8,
    'local_price' => 0.72,
));
if (is_wp_error($threshold) || '100999' !== $threshold['invoice']->expected_raw || '0.100999' !== $threshold['invoice']->usdt_amount) {
    fwrite(STDERR, "FAIL: safe bounded unique-tail quote was not generated deterministically\n");
    exit(1);
}

// More than thirty occupied tails must not cause a random-retry failure while
// a deterministic free slot still exists.
$dense_db = new QA_DB();
for ($tail = 1; $tail <= 999; $tail++) {
    if (500 === $tail) { continue; }
    $dense_db->insert_invoice(array(
        'invoice_no' => 'JU-DENSE-' . $tail,
        'payment_id' => 9000 + $tail,
        'zibll_order_num' => 'DENSE-' . $tail,
        'receive_address' => JIULIU_USDT_Settings::USDT_CONTRACT,
        'expected_raw' => (string) (10000000 + $tail),
        'active_key' => hash('sha256', JIULIU_USDT_Settings::USDT_CONTRACT . '|' . (10000000 + $tail)),
        'status' => 'pending',
    ));
}
$dense_service = new JIULIU_USDT_Invoices($settings, $dense_db, new QA_Rate($settings), new QA_Trongrid($settings));
$dense_result = $dense_service->create_for_zibll(array(
    'order_num' => '52000000000814',
    'payment_id' => 814,
    'user_id' => 8,
    'local_price' => 72,
));
if (is_wp_error($dense_result) || '10000500' !== $dense_result['invoice']->expected_raw) {
    fwrite(STDERR, "FAIL: deterministic tail search did not find the remaining free slot\n");
    exit(1);
}

class QA_Insert_Race_DB extends QA_DB
{
    private $injected_winner = false;

    public function get_reusable_by_payment_id($payment_id) { return null; }

    public function insert_invoice($data)
    {
        if (!$this->injected_winner) {
            $this->injected_winner = true;
            // Simulate another request committing the identical order number
            // after our initial lookup but before our INSERT.
            parent::insert_invoice($data);
            return new WP_Error('invoice_duplicate', 'simulated concurrent winner');
        }
        return parent::insert_invoice($data);
    }
}

$race_db = new QA_Insert_Race_DB();
$race_service = new JIULIU_USDT_Invoices($settings, $race_db, new QA_Rate($settings), new QA_Trongrid($settings));
$race_result = $race_service->create_for_zibll(array(
    'order_num' => '52000000000801',
    'payment_id' => 801,
    'user_id' => 8,
    'local_price' => 72,
));
if (is_wp_error($race_result)) {
    fwrite(STDERR, 'FAIL: concurrent invoice winner was not reused: ' . $race_result->get_error_code() . "\n");
    exit(1);
}
if (1 !== count($race_db->rows) || 'pending' !== $race_result['invoice']->status) {
    fwrite(STDERR, "FAIL: invoice insert race did not converge to one pending winner\n");
    exit(1);
}
if (hash('sha256', $race_result['public_token']) !== $race_result['invoice']->public_token_hash) {
    fwrite(STDERR, "FAIL: invoice race winner did not receive the returned public token\n");
    exit(1);
}

class QA_Tx_Race_DB extends QA_DB
{
    private $tx_owners = array();

    // Simulate both workers having completed their optimistic pre-read before
    // either transaction commits. Atomic ownership is enforced only in claim.
    public function get_by_txid($txid) { return null; }

    public function claim_invoice($id, $txid, $transfer, $allowed_statuses, $preserve_error_code = false)
    {
        $key = strtolower((string) $txid);
        if (isset($this->tx_owners[$key]) && (int) $this->tx_owners[$key] !== (int) $id) {
            return false;
        }
        if (!isset($this->rows[$id]) || !in_array($this->rows[$id]->status, $allowed_statuses, true)) {
            return false;
        }
        $this->tx_owners[$key] = (int) $id;
        return $this->update_invoice($id, array(
            'status' => 'processing',
            'txid' => $key,
            'from_address' => isset($transfer['from']) ? $transfer['from'] : '',
            'actual_raw' => JIULIU_USDT_Util::normalize_raw($transfer['value']),
            'actual_amount' => JIULIU_USDT_Util::raw_to_decimal($transfer['value'], 6),
            'block_timestamp' => $transfer['block_timestamp'],
            'error_code' => null,
        ));
    }
}

$tx_db = new QA_Tx_Race_DB();
$base = (array) $invoice;
unset($base['id']);
$base['status'] = 'pending';
$base['txid'] = null;
$base['actual_raw'] = null;
$base['actual_amount'] = null;
$first = $tx_db->insert_invoice($base);
$base['invoice_no'] = 'JU-TX-RACE-2';
$base['payment_id'] = 802;
$base['zibll_order_num'] = '52000000000802';
$base['expected_raw'] = '20000000';
$base['usdt_amount'] = '20.000000';
$second = $tx_db->insert_invoice($base);

$tx_service = new JIULIU_USDT_Invoices($settings, $tx_db, new QA_Rate($settings), new QA_Trongrid($settings));
$process = new ReflectionMethod('JIULIU_USDT_Invoices', 'process_transfer');
$process->setAccessible(true);
$shared_txid = str_repeat('f', 64);
$transfer = array(
    'transaction_id' => $shared_txid,
    'from' => 'TConcurrentSender',
    // Deliberately mismatch both invoices so no Zibll settlement is required.
    'value' => '30000000',
    'block_timestamp' => time() * 1000,
);
$first_result = $process->invoke($tx_service, $first, $transfer, false, 'auto');
$second_result = $process->invoke($tx_service, $second, $transfer, false, 'auto');

if (!is_object($first_result) || 'review' !== $first_result->status) {
    fwrite(STDERR, "FAIL: first txid claimant did not retain ownership for review\n");
    exit(1);
}
if (!is_wp_error($second_result) || 'invoice_claim_failed' !== $second_result->get_error_code()) {
    fwrite(STDERR, "FAIL: second concurrent txid claimant was not rejected atomically\n");
    exit(1);
}
$owners = array_filter($tx_db->rows, function ($row) use ($shared_txid) {
    return !empty($row->txid) && strtolower($row->txid) === $shared_txid;
});
if (1 !== count($owners)) {
    fwrite(STDERR, "FAIL: shared txid was bound to more than one invoice\n");
    exit(1);
}

fwrite(STDOUT, "PASS: concurrent invoice insert and atomic txid ownership guards\n");
