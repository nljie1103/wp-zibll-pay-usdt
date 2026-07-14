<?php

// Regression: request A can hold an ordinary review snapshot while request B
// has already changed the live row to the non-replayable uncertain state. The
// claim UPDATE itself, not the stale PHP object, must arbitrate that race.

require_once __DIR__ . '/test-plugin-core.php';

if (!function_exists('esc_sql')) {
    function esc_sql($value) { return addslashes((string) $value); }
}

class QA_Claim_WPDB_102
{
    public $prefix = 'wp_';
    public $row;
    public $prepared_sql = '';
    public $prepared_args = array();

    public function __construct($row) { $this->row = $row; }

    public function prepare($sql)
    {
        $args = func_get_args();
        array_shift($args);
        $this->prepared_sql = $sql;
        $this->prepared_args = $args;
        return $sql;
    }

    public function query($sql)
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower((string) $sql));
        $is_preserving = false !== strpos($normalized, 'error_code = error_code');
        $uncertain = 'zibll_settlement_uncertain';

        // Model the WHERE predicate emitted by the production method. Missing
        // either predicate deliberately recreates the race this test guards.
        if ($is_preserving) {
            if (
                false === strpos($normalized, "error_code = '" . $uncertain . "'")
                || $uncertain !== (string) $this->row->error_code
            ) {
                return 0;
            }
        } elseif (
            $uncertain === (string) $this->row->error_code
            && false === strpos($normalized, "error_code <> '" . $uncertain . "'")
            && false === strpos($normalized, "error_code != '" . $uncertain . "'")
        ) {
            // An unguarded ordinary claim would overwrite the current
            // uncertain row even though its caller read an older snapshot.
            $this->apply_update(false);
            return 1;
        } elseif (!$is_preserving && $uncertain === (string) $this->row->error_code) {
            return 0;
        }

        $this->apply_update($is_preserving);
        return 1;
    }

    private function apply_update($preserve)
    {
        $this->row->status = 'processing';
        $this->row->txid = isset($this->prepared_args[0]) ? $this->prepared_args[0] : '';
        if (!$preserve) {
            $this->row->error_code = null;
        }
    }
}

function qa_uncertain_claim_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$txid = str_repeat('e', 64);
$stale_snapshot = (object) array(
    'id' => 8102,
    'status' => 'review',
    'error_code' => 'ordinary_manual_review',
);
$live_row = (object) array(
    'id' => 8102,
    'status' => 'review',
    'txid' => $txid,
    'error_code' => 'zibll_settlement_uncertain',
);
$GLOBALS['wpdb'] = new QA_Claim_WPDB_102($live_row);
$claim_db = new JIULIU_USDT_DB();
$transfer = array(
    'transaction_id' => $txid,
    'from' => 'TFromAddress',
    'value' => '10001234',
    'block_timestamp' => 1783828800000,
);

if ('ordinary_manual_review' !== $stale_snapshot->error_code) {
    qa_uncertain_claim_fail_102('test precondition did not retain the stale ordinary-review snapshot');
}
$ordinary_claim = $claim_db->claim_invoice(
    $stale_snapshot->id,
    $txid,
    $transfer,
    array('review'),
    false
);
if ($ordinary_claim) {
    qa_uncertain_claim_fail_102('stale ordinary claim overwrote the live uncertain row');
}
if (
    'review' !== $live_row->status
    || 'zibll_settlement_uncertain' !== $live_row->error_code
    || $txid !== $live_row->txid
) {
    qa_uncertain_claim_fail_102('rejected ordinary claim mutated the live uncertain state');
}

// This flag is set only after the service has required admin + force + explicit
// high-risk confirmation. The atomic SQL must then require that the live row is
// still uncertain and preserve its marker while moving it to processing.
$confirmed_claim = $claim_db->claim_invoice(
    $live_row->id,
    $txid,
    $transfer,
    array('review'),
    true
);
if (!$confirmed_claim) {
    qa_uncertain_claim_fail_102('confirmed uncertain recovery could not atomically claim the live row');
}
if ('processing' !== $live_row->status || 'zibll_settlement_uncertain' !== $live_row->error_code) {
    qa_uncertain_claim_fail_102('confirmed uncertain claim did not preserve the uncertain marker');
}

fwrite(STDOUT, "PASS: stale ordinary claim cannot clear a live uncertain settlement\n");
