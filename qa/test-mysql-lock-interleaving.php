<?php

// Optional real-MySQL/MariaDB two-connection test. Local runs skip when no QA
// database is configured; GitHub Actions supplies an isolated MariaDB service.

$host = getenv('QA_MYSQL_HOST');
if (!$host) {
    fwrite(STDOUT, "SKIP: QA_MYSQL_HOST is not configured for the real lock test\n");
    exit(0);
}

$port = (int) (getenv('QA_MYSQL_PORT') ?: 3306);
$user = getenv('QA_MYSQL_USER') ?: 'root';
$password = getenv('QA_MYSQL_PASSWORD') ?: '';
$database = getenv('QA_MYSQL_DATABASE') ?: 'jiuliu_qa';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$first = new mysqli($host, $user, $password, $database, $port);
$second = new mysqli($host, $user, $password, $database, $port);
$first->set_charset('utf8mb4');
$second->set_charset('utf8mb4');

$suffix = preg_replace('/[^0-9]/', '', (string) getmypid());
$parent = 'qa_parent_' . $suffix;
$child = 'qa_child_' . $suffix;
$invoice = 'qa_invoice_' . $suffix;

function qa_mysql_fail($message)
{
    throw new RuntimeException($message);
}

try {
    $first->query("CREATE TABLE `{$parent}` (id BIGINT UNSIGNED PRIMARY KEY, status INT NOT NULL, pay_num VARCHAR(80) NULL) ENGINE=InnoDB");
    $first->query("CREATE TABLE `{$child}` (id BIGINT UNSIGNED PRIMARY KEY, payment_id BIGINT UNSIGNED NOT NULL, status INT NOT NULL, KEY payment_id (payment_id)) ENGINE=InnoDB");
    $first->query("CREATE TABLE `{$invoice}` (id BIGINT UNSIGNED PRIMARY KEY, payment_id BIGINT UNSIGNED NOT NULL, status VARCHAR(24) NOT NULL, txid VARCHAR(80) NULL) ENGINE=InnoDB");
    $first->query("INSERT INTO `{$parent}` VALUES (1,0,NULL)");
    $first->query("INSERT INTO `{$child}` VALUES (11,1,0),(12,1,0)");
    $first->query("INSERT INTO `{$invoice}` VALUES (21,1,'processing','tx-one')");

    // Settlement owns the parent row. A close request must wait; after the
    // settlement commits, its status=0 predicates affect no rows.
    $first->begin_transaction();
    $first->query("SELECT * FROM `{$parent}` WHERE id=1 FOR UPDATE")->fetch_assoc();
    $first->query("SELECT * FROM `{$child}` WHERE payment_id=1 ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $first->query("SELECT * FROM `{$invoice}` WHERE id=21 FOR UPDATE")->fetch_assoc();
    $second->query('SET SESSION innodb_lock_wait_timeout=1');

    $timed_out = false;
    try {
        $second->query("UPDATE `{$parent}` SET status=-1 WHERE id=1 AND status=0");
    } catch (mysqli_sql_exception $exception) {
        $timed_out = in_array((int) $exception->getCode(), array(1205, 1213), true);
    }
    if (!$timed_out) {
        qa_mysql_fail('close request was not blocked by the settlement row lock');
    }

    $first->query("UPDATE `{$parent}` SET status=1,pay_num='tx-one' WHERE id=1 AND status=0");
    $first->query("UPDATE `{$child}` SET status=1 WHERE payment_id=1 AND status=0");
    $first->query("UPDATE `{$invoice}` SET status='paid' WHERE id=21 AND status='processing'");
    $first->commit();

    $second->query("UPDATE `{$parent}` SET status=-1 WHERE id=1 AND status=0");
    if (0 !== $second->affected_rows) {
        qa_mysql_fail('close request reopened or changed an already-paid parent');
    }
    $second->query("UPDATE `{$child}` SET status=-1 WHERE payment_id=1 AND status=0");
    if (0 !== $second->affected_rows) {
        qa_mysql_fail('close request changed an already-paid child');
    }

    $parent_state = $first->query("SELECT status FROM `{$parent}` WHERE id=1")->fetch_assoc();
    $child_states = $first->query("SELECT DISTINCT status FROM `{$child}` WHERE payment_id=1")->fetch_all(MYSQLI_ASSOC);
    $invoice_state = $first->query("SELECT status FROM `{$invoice}` WHERE id=21")->fetch_assoc();
    if ('1' !== (string) $parent_state['status'] || 1 !== count($child_states) || '1' !== (string) $child_states[0]['status'] || 'paid' !== $invoice_state['status']) {
        qa_mysql_fail('settlement-first interleaving produced a partial final state');
    }

    // If close commits first, the guarded snapshot must observe -1 and abort
    // before any payment update or success hook is attempted.
    $first->query("UPDATE `{$parent}` SET status=0,pay_num=NULL WHERE id=1");
    $first->query("UPDATE `{$child}` SET status=0 WHERE payment_id=1");
    $first->query("UPDATE `{$invoice}` SET status='processing',txid='tx-two' WHERE id=21");
    $second->query("UPDATE `{$parent}` SET status=-1 WHERE id=1 AND status=0");
    $second->query("UPDATE `{$child}` SET status=-1 WHERE payment_id=1 AND status=0");

    $first->begin_transaction();
    $locked_parent = $first->query("SELECT status FROM `{$parent}` WHERE id=1 FOR UPDATE")->fetch_assoc();
    $locked_children = $first->query("SELECT status FROM `{$child}` WHERE payment_id=1 ORDER BY id FOR UPDATE")->fetch_all(MYSQLI_ASSOC);
    $first->query("SELECT status FROM `{$invoice}` WHERE id=21 FOR UPDATE")->fetch_assoc();
    if ('-1' !== (string) $locked_parent['status']) {
        qa_mysql_fail('settlement did not observe the already-closed parent');
    }
    foreach ($locked_children as $locked_child) {
        if ('-1' !== (string) $locked_child['status']) {
            qa_mysql_fail('settlement did not observe every already-closed child');
        }
    }
    $first->rollback();

    fwrite(STDOUT, "PASS: real InnoDB close/settle interleavings are serialized without partial state\n");
} finally {
    try { $first->rollback(); } catch (Throwable $ignored) {}
    foreach (array($invoice, $child, $parent) as $table) {
        try { $first->query("DROP TABLE IF EXISTS `{$table}`"); } catch (Throwable $ignored) {}
    }
    $first->close();
    $second->close();
}
