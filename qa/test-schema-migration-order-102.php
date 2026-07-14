<?php

// Static release contract: dbDelta can fail silently, so install() must verify
// the live schema and return on verification failure before persisting the new
// database version.

function qa_schema_contract_fail_102($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

$path = __DIR__ . '/../jiuliu-usdt-payment/includes/class-jiuliu-usdt-db.php';
$source = file_get_contents($path);
if (false === $source) {
    qa_schema_contract_fail_102('could not read database implementation');
}

$install = strpos($source, 'public function install()');
$next_method = false === $install ? false : strpos($source, 'public function plugin_schema_is_ready', $install);
$validation = false === $install ? false : strpos($source, '$this->plugin_schema_is_ready(true)', $install);
$version_write = false === $install ? false : strpos($source, "update_option('jiuliu_usdt_db_version'", $install);

if (
    false === $install
    || false === $next_method
    || false === $validation
    || false === $version_write
    || $validation >= $next_method
    || $version_write >= $next_method
) {
    qa_schema_contract_fail_102('install() does not contain both schema validation and DB-version persistence');
}
if ($validation >= $version_write) {
    qa_schema_contract_fail_102('DB version is persisted before live schema validation');
}

$guard = substr($source, $validation, $version_write - $validation);
if (false === strpos($guard, 'is_wp_error($schema_status)') || false === strpos($guard, 'return $schema_status')) {
    qa_schema_contract_fail_102('schema validation failure does not return before the DB-version write');
}

fwrite(STDOUT, "PASS: schema validation precedes database version persistence\n");
