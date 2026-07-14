<?php

// Regression: an administrator can load an ordinary review row just before a
// competing settlement marks it uncertain. reject_invoice() must arbitrate on
// the live error_code in the same UPDATE and never erase that safety marker.

require_once __DIR__ . '/test-plugin-core.php';

class QA_Reject_WPDB_102
{
    public $prefix = 'wp_';
    public $row;
    public $prepared_args = array();

    public function __construct($row) { $this->row = $row; }
    public function prepare($sql)
    {
        $args = func_get_args();
        array_shift($args);
        $this->prepared_args = $args;
        return $sql;
    }
    public function query($sql)
    {
        $normalized = preg_replace('/\s+/', ' ', strtolower((string) $sql));
        $uncertain = 'zibll_settlement_uncertain';
        $has_guard = false !== strpos($normalized, "error_code <> '" . $uncertain . "'")
            || false !== strpos($normalized, "error_code != '" . $uncertain . "'");

        if ($uncertain === (string) $this->row->error_code && $has_guard) {
            return 0;
        }

        $this->row->status = 'rejected';
        $this->row->error_code = 'admin_rejected';
        return 1;
    }
}

function qa_uncertain_reject_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$stale_snapshot = (object) array('id' => 9201, 'status' => 'review', 'error_code' => 'amount_mismatch');
$live_uncertain = (object) array(
    'id' => 9201,
    'status' => 'review',
    'error_code' => 'zibll_settlement_uncertain',
);
$GLOBALS['wpdb'] = new QA_Reject_WPDB_102($live_uncertain);
$reject_db = new JIULIU_USDT_DB();

if ('amount_mismatch' !== $stale_snapshot->error_code) {
    qa_uncertain_reject_fail_102('test did not retain the stale ordinary-review snapshot');
}
if ($reject_db->reject_invoice($stale_snapshot->id)) {
    qa_uncertain_reject_fail_102('stale administrator action rejected a live uncertain settlement');
}
if ('review' !== $live_uncertain->status || 'zibll_settlement_uncertain' !== $live_uncertain->error_code) {
    qa_uncertain_reject_fail_102('failed rejection mutated the live uncertain marker');
}

$ordinary = (object) array('id' => 9202, 'status' => 'review', 'error_code' => 'amount_mismatch');
$GLOBALS['wpdb'] = new QA_Reject_WPDB_102($ordinary);
$reject_db = new JIULIU_USDT_DB();
if (!$reject_db->reject_invoice($ordinary->id) || 'rejected' !== $ordinary->status || 'admin_rejected' !== $ordinary->error_code) {
    qa_uncertain_reject_fail_102('atomic uncertainty guard prevented an ordinary review rejection');
}

fwrite(STDOUT, "PASS: reject_invoice atomically preserves uncertain settlements\n");
