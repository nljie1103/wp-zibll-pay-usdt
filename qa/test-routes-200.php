<?php

define('ABSPATH', __DIR__ . '/');

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code = '', $message = '') { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($text) { return $text; }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_generate_password($length) { return str_repeat('x', $length); }

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-routes.php';

$tests = 0;
$failures = array();

function qa_routes_assert($condition, $message)
{
    global $tests, $failures;
    $tests++;
    if (!$condition) {
        $failures[] = $message;
    }
}

function qa_routes_same($expected, $actual, $message)
{
    qa_routes_assert($expected === $actual, $message . ' (expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . ')');
}

function qa_routes_error($code, $value, $message)
{
    qa_routes_assert(is_wp_error($value) && $code === $value->get_error_code(), $message);
}

$presets = JIULIU_CRYPTO_Routes::presets();
qa_routes_same(7, count($presets), 'Only the seven issuer-verified conservative presets should ship');
qa_routes_same('TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', $presets['usdt_trc20']['contract_address'], 'TRON USDT contract must match the issuer listing');
qa_routes_same('0xdac17f958d2ee523a2206206994597c13d831ec7', $presets['usdt_ethereum']['contract_address'], 'Ethereum USDT contract must match the issuer listing');
qa_routes_same('0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', $presets['usdc_base']['contract_address'], 'Base USDC contract must match the issuer listing');
foreach ($presets as $preset) {
    qa_routes_assert(empty($preset['enabled']), 'Every preset must default disabled');
    qa_routes_same('', $preset['receive_address'], 'A preset must never contain a receiver');
    qa_routes_same('', $preset['rpc_url'], 'A preset must never hard-code an RPC provider');
    qa_routes_same('', $preset['api_key'], 'A preset must never contain an API credential');
}

$tron = JIULIU_CRYPTO_Routes::from_preset('usdt_trc20', array(
    'enabled' => 1,
    'receive_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
));
qa_routes_assert(!is_wp_error($tron), 'A complete TRON preset must normalize');
qa_routes_same('TRX', $tron['fee_symbol'], 'TRON fee currency must be explicit');
qa_routes_same($tron['contract'], $tron['contract_address'], 'Contract aliases must remain identical');
qa_routes_same($tron['receiver'], $tron['receive_address'], 'Receiver aliases must remain identical');

$evm = JIULIU_CRYPTO_Routes::from_preset('usdc_base', array(
    'enabled' => 1,
    'receive_address' => '0x1111111111111111111111111111111111111111',
    'rpc_url' => 'https://base.example-rpc.test/v1/project',
    'rpc_headers' => array('Authorization' => 'Bearer secret'),
));
qa_routes_assert(!is_wp_error($evm), 'A complete EVM preset must normalize');
qa_routes_same('8453', $evm['chain_id'], 'EVM chain ID must stay a decimal string');
qa_routes_same('ju_crypto_usdc_base', $evm['method'], 'Normalized route must expose its payment method alias');
qa_routes_same('Base (ERC20)', $evm['network'], 'Normalized route must expose its network alias');
qa_routes_same(6, $evm['decimals'], 'Adapter decimals alias must be present');
qa_routes_same(20, $evm['confirmations'], 'Adapter confirmations alias must be present');

$registry = new JIULIU_CRYPTO_Routes(array($tron, $evm));
qa_routes_same(null, $registry->get_error(), 'Valid registry must not contain a configuration error');
qa_routes_same(2, count($registry->all()), 'Registry must retain every route');
qa_routes_same(2, count($registry->enabled()), 'Enabled helper must return enabled routes');
qa_routes_same('ju_crypto_usdt_trc20', $registry->method_for_route($tron), 'TRON must use the v2 dynamic method namespace');
qa_routes_same('ju_crypto_usdc_base', $registry->method_for_route('usdc_base'), 'EVM must use the v2 dynamic method namespace');
qa_routes_same('usdc_base', $registry->get_by_method('ju_crypto_usdc_base', true)['id'], 'Method lookup must resolve an enabled route');
qa_routes_same(null, $registry->get_by_method('usdt_trc20'), 'An unprefixed route ID must not resolve as a payment method');
qa_routes_same(50, strlen($registry->method_for_route(str_repeat('a', 40))), 'Maximum route ID must fit Zibll pay_type varchar(50)');
qa_routes_same('', $registry->method_for_route(str_repeat('a', 41)), 'A route ID that would overflow Zibll pay_type must be rejected');

$disabled = $presets['usdc_ethereum'];
$mixed_registry = new JIULIU_CRYPTO_Routes(array($evm, $disabled));
qa_routes_same(2, count($mixed_registry->all()), 'Disabled routes must be retained for historical invoice monitoring');
qa_routes_same(1, count($mixed_registry->all(true)), 'Enabled-only lookup must filter disabled routes');
qa_routes_same(null, $mixed_registry->get_by_id('usdc_ethereum', true), 'Enabled-only ID lookup must reject a disabled route');
qa_routes_same('usdc_ethereum', $mixed_registry->get_by_id('usdc_ethereum')['id'], 'Normal ID lookup must retain a disabled route');

$json_map = json_encode(array('usdc_base' => array_merge($presets['usdc_base'], array(
    'enabled' => 1,
    'receive_address' => '0x2222222222222222222222222222222222222222',
    'rpc_url' => 'https://rpc.example.test',
))));
$decoded = JIULIU_CRYPTO_Routes::normalize($json_map);
qa_routes_same('usdc_base', $decoded[0]['id'], 'A JSON object keyed by route ID must normalize');
$encoded = JIULIU_CRYPTO_Routes::encode($decoded);
qa_routes_assert(is_string($encoded) && false !== strpos($encoded, '"rpc_url"'), 'Canonical routes must encode for WordPress option storage');

qa_routes_error('unknown_route_preset', JIULIU_CRYPTO_Routes::from_preset('usdt_bsc'), 'Unverified presets must not exist');
qa_routes_error('invalid_routes_json', JIULIU_CRYPTO_Routes::normalize('{broken'), 'Malformed JSON must fail closed');

$bad = $presets['usdc_base'];
$bad['id'] = 'USDC Base';
qa_routes_error('invalid_route_id', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'Unsafe route IDs must be rejected instead of silently sanitized');

$duplicate = JIULIU_CRYPTO_Routes::normalize(array($presets['usdc_base'], $presets['usdc_base']));
qa_routes_error('duplicate_route_id', $duplicate, 'Duplicate route IDs must be rejected');

$bad = $presets['usdc_base'];
$bad['contract_address'] = '0x0000000000000000000000000000000000000000';
qa_routes_error('invalid_evm_address', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'The EVM zero address must be rejected');

$bad = $presets['usdc_base'];
$bad['enabled'] = 1;
qa_routes_error('missing_receiver', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'An enabled route must require a receiver');

$bad = $presets['usdc_base'];
$bad['enabled'] = 1;
$bad['receive_address'] = '0x3333333333333333333333333333333333333333';
qa_routes_error('missing_rpc_url', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'An enabled EVM route must require an RPC URL');

$bad['rpc_url'] = 'http://rpc.example.test';
qa_routes_error('invalid_rpc_url', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'Plain HTTP RPC endpoints must be rejected');

$bad = $presets['usdt_trc20'];
$bad['receive_address'] = 'T000000000000000000000000000000000';
qa_routes_error('invalid_tron_address', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'Invalid TRON Base58Check receivers must be rejected');

$bad = $presets['usdc_base'];
$bad['rpc_headers'] = array('Authorization' => "safe\r\nX-Injected: yes");
qa_routes_error('invalid_rpc_headers', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'Header injection must be rejected');

$chain_conflict = $presets['usdc_base'];
$chain_conflict['id'] = 'usdc_fakebase';
$chain_conflict['chain_key'] = 'not_base';
qa_routes_error(
    'chain_identity_conflict',
    JIULIU_CRYPTO_Routes::normalize(array($presets['usdc_base'], $chain_conflict)),
    'One chain ID must not be assigned multiple chain keys'
);

$bad_registry = new JIULIU_CRYPTO_Routes('{bad');
qa_routes_assert(is_wp_error($bad_registry->get_error()), 'Constructor must expose normalization errors');
qa_routes_same(0, count($bad_registry->all()), 'An invalid allowlist must fail closed to zero routes');

if ($failures) {
    fwrite(STDERR, "FAIL: " . count($failures) . " / " . $tests . " routes assertions failed\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, " - " . $failure . "\n");
    }
    exit(1);
}

fwrite(STDOUT, "OK: " . $tests . " routes assertions passed\n");
