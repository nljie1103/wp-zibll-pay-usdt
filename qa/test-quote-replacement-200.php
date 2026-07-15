<?php

// 2.1.0 quote replacement contract. The unique Zibll order number must move
// from an old quote to its replacement atomically, and never across payment_id
// ownership boundaries.

define('ABSPATH', __DIR__ . '/');

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code, $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($value, $domain = null) { return $value; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function wp_generate_uuid4() { return '00000000-0000-4000-8000-000000000000'; }

class QA_Quote_WPDB
{
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $last_error = '';
    public $rows = array();
    public $next_id = 2;
    public $fail_start = false;
    public $fail_insert = false;
    public $fail_retire = false;
    public $fail_activate = false;
    public $fail_commit = false;
    public $insert_calls = 0;
    private $snapshot = null;

    public function __construct($old_payment_id = 77)
    {
        $this->rows[1] = array(
            'id' => 1,
            'invoice_no' => 'OLD-INVOICE',
            'payment_id' => $old_payment_id,
            'zibll_order_num' => 'ORDER-2026-001',
            'status' => 'pending',
            'active_key' => str_repeat('a', 64),
            'updated_at' => '2026-07-15 00:00:00',
            'error_code' => null,
        );
    }

    public function prepare($query)
    {
        $args = array_slice(func_get_args(), 1);
        $index = 0;
        return preg_replace_callback('/%([sdf])/', function ($matches) use (&$index, $args) {
            $value = array_key_exists($index, $args) ? $args[$index] : null;
            $index++;
            if ('d' === $matches[1]) {
                return (string) (int) $value;
            }
            if ('f' === $matches[1]) {
                return (string) (float) $value;
            }
            return "'" . str_replace("'", "''", (string) $value) . "'";
        }, $query);
    }

    public function get_row($query)
    {
        if (!preg_match('/WHERE id = ([0-9]+)/', $query, $matches)) {
            return null;
        }
        $id = (int) $matches[1];
        return isset($this->rows[$id]) ? (object) $this->rows[$id] : null;
    }

    public function insert($table, $data)
    {
        $this->insert_calls++;
        if ($this->fail_insert) {
            $this->last_error = 'simulated insert failure';
            return false;
        }

        foreach ($this->rows as $row) {
            if ((string) $row['zibll_order_num'] === (string) $data['zibll_order_num']) {
                $this->last_error = 'Duplicate entry for zibll_order_num';
                return false;
            }
            if (!empty($row['active_key']) && !empty($data['active_key']) && $row['active_key'] === $data['active_key']) {
                $this->last_error = 'Duplicate entry for active_key';
                return false;
            }
        }

        $id = $this->next_id++;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        $this->insert_id = $id;
        $this->last_error = '';
        return 1;
    }

    public function query($query)
    {
        $trimmed = trim($query);
        if ('START TRANSACTION' === $trimmed) {
            if ($this->fail_start) {
                return false;
            }
            $this->snapshot = array(
                'rows' => unserialize(serialize($this->rows)),
                'next_id' => $this->next_id,
                'insert_id' => $this->insert_id,
            );
            return 1;
        }
        if ('ROLLBACK' === $trimmed) {
            if (is_array($this->snapshot)) {
                $this->rows = $this->snapshot['rows'];
                $this->next_id = $this->snapshot['next_id'];
                $this->insert_id = $this->snapshot['insert_id'];
            }
            $this->snapshot = null;
            return 1;
        }
        if ('COMMIT' === $trimmed) {
            if ($this->fail_commit) {
                return false;
            }
            $this->snapshot = null;
            return 1;
        }

        if (false !== strpos($query, "status = 'superseded'")) {
            if ($this->fail_retire) {
                return 0;
            }
            if (!preg_match("/SET zibll_order_num = '((?:''|[^'])*)'.*WHERE id = ([0-9]+).*zibll_order_num = '((?:''|[^'])*)'/s", $query, $matches)) {
                return false;
            }
            $id = (int) $matches[2];
            $expected = str_replace("''", "'", $matches[3]);
            if (!isset($this->rows[$id])
                || (string) $this->rows[$id]['zibll_order_num'] !== $expected
                || !in_array($this->rows[$id]['status'], array('pending', 'expired'), true)) {
                return 0;
            }
            $this->rows[$id]['zibll_order_num'] = str_replace("''", "'", $matches[1]);
            $this->rows[$id]['status'] = 'superseded';
            $this->rows[$id]['error_code'] = 'quote_replaced';
            return 1;
        }

        if (false !== strpos($query, 'SET zibll_order_num =')) {
            if ($this->fail_activate) {
                return 0;
            }
            if (!preg_match("/SET zibll_order_num = '((?:''|[^'])*)' WHERE id = ([0-9]+) AND zibll_order_num = '((?:''|[^'])*)'/s", $query, $matches)) {
                return false;
            }
            $id = (int) $matches[2];
            $expected = str_replace("''", "'", $matches[3]);
            if (!isset($this->rows[$id]) || (string) $this->rows[$id]['zibll_order_num'] !== $expected) {
                return 0;
            }
            $this->rows[$id]['zibll_order_num'] = str_replace("''", "'", $matches[1]);
            return 1;
        }

        return false;
    }
}

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-db.php';

function qa_quote_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_quote_assert($condition, $message)
{
    if (!$condition) {
        qa_quote_fail($message);
    }
}

function qa_quote_data()
{
    return array(
        'invoice_no' => 'NEW-INVOICE',
        'payment_id' => 77,
        'zibll_order_num' => 'ORDER-2026-001',
        'status' => 'pending',
        'active_key' => str_repeat('b', 64),
        'updated_at' => '2026-07-15 00:01:00',
    );
}

function qa_quote_run($failure = '')
{
    global $wpdb;
    $wpdb = new QA_Quote_WPDB();
    if ($failure) {
        $property = 'fail_' . $failure;
        $wpdb->$property = true;
    }
    $db = new JIULIU_CRYPTO_DB();
    $result = $db->insert_replacing_order_quote(qa_quote_data(), 1, 'ORDER-2026-001', 77);
    return array($result, $wpdb);
}

// The happy path transfers the order number exactly once and retires the old
// quote only after the replacement row exists.
list($result, $wpdb) = qa_quote_run();
qa_quote_assert(!is_wp_error($result) && 2 === (int) $result->id, 'atomic quote replacement did not return the new row');
qa_quote_assert('superseded' === $wpdb->rows[1]['status'], 'successful replacement did not retire the old quote');
qa_quote_assert('ORDER-2026-001' !== $wpdb->rows[1]['zibll_order_num'], 'old quote retained the unique Zibll order number');
qa_quote_assert('ORDER-2026-001' === $wpdb->rows[2]['zibll_order_num'], 'new quote did not receive the unique Zibll order number');

// Every transactional failure must leave the original quote usable and remove
// any temporary replacement row.
foreach (array('start', 'insert', 'retire', 'activate', 'commit') as $failure) {
    list($result, $wpdb) = qa_quote_run($failure);
    qa_quote_assert(is_wp_error($result), $failure . ' failure was reported as success');
    qa_quote_assert(1 === count($wpdb->rows), $failure . ' failure leaked a temporary replacement row');
    qa_quote_assert('pending' === $wpdb->rows[1]['status'], $failure . ' failure retired the old quote');
    qa_quote_assert('ORDER-2026-001' === $wpdb->rows[1]['zibll_order_num'], $failure . ' failure stole the old order number');
    qa_quote_assert(str_repeat('a', 64) === $wpdb->rows[1]['active_key'], $failure . ' failure released the old exact-amount reservation');
}

// A caller may never replace an order number owned by another Zibll parent
// payment, even if the row is otherwise pending and replaceable.
$wpdb = new QA_Quote_WPDB(999);
$db = new JIULIU_CRYPTO_DB();
$result = $db->insert_replacing_order_quote(qa_quote_data(), 1, 'ORDER-2026-001', 77);
qa_quote_assert(is_wp_error($result) && 'invoice_quote_changed' === $result->get_error_code(), 'cross-payment order ownership was not rejected');
qa_quote_assert(0 === $wpdb->insert_calls, 'cross-payment ownership check occurred after inserting a replacement');
qa_quote_assert('pending' === $wpdb->rows[1]['status'] && 'ORDER-2026-001' === $wpdb->rows[1]['zibll_order_num'], 'cross-payment rejection modified the owner quote');

fwrite(STDOUT, "OK: 2.1.0 atomic quote replacement and payment ownership contracts passed\n");
