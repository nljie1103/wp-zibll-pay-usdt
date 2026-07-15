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
$expected_presets = array(
    'usdt_trc20'       => array('tron-mainnet', 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 6, 'usdt', 'tether', 'Tether', 'issuer_native', 'TRX'),
    'usdt_ethereum'    => array('1', '0xdac17f958d2ee523a2206206994597c13d831ec7', 6, 'usdt', 'tether', 'Tether', 'issuer_native', 'ETH'),
    'usdc_ethereum'    => array('1', '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_base'        => array('8453', '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_arbitrum'    => array('42161', '0xaf88d065e77c8cc2239327c5edb3a432268e5831', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_polygon'     => array('137', '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'POL'),
    'usdc_avalanche'   => array('43114', '0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'AVAX'),
    'usdt_bsc'         => array('56', '0x55d398326f99059ff775485246999027b3197955', 18, 'usdt', 'tether', 'Binance-Peg', 'custodial_peg', 'BNB'),
    'usdc_bsc'         => array('56', '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d', 18, 'usdc', 'usd-coin', 'Binance-Peg', 'custodial_peg', 'BNB'),
    'fdusd_bsc'        => array('56', '0xc5f0f7b66764f6ec8c8dff7ba683102295e16409', 18, 'fdusd', 'first-digital-usd', 'First Digital / FD121', 'issuer_native', 'BNB'),
    'usdc_optimism'    => array('10', '0x0b2c639c533813f4aa9d7837caf62653d097ff85', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdt_celo'        => array('42220', '0x48065fbbe25f71c9282ddf5e1cd6d6a887483d5e', 6, 'usdt', 'tether', 'Tether', 'issuer_native', 'CELO'),
    'usdc_celo'        => array('42220', '0xceba9300f2b948710d2653dd7b07f33a8b32118c', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'CELO'),
    'usdt_avalanche'   => array('43114', '0x9702230a8ea53601f5cd2dc00fdbc13d4df4a8c7', 6, 'usdt', 'tether', 'Tether', 'issuer_native', 'AVAX'),
    'usdc_linea'       => array('59144', '0x176211869ca2b568f2a7d4ee941e073a821ee1ff', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_zksync'      => array('324', '0x1d17cbcf0d6d143135ae902365d2e5e2a16538d4', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_unichain'    => array('130', '0x078d782b760474a361dda0af3839290b0ef57ad6', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_world_chain' => array('480', '0x79a02482a880bce3f13e09da970dc34db4cd24d1', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_ink'         => array('57073', '0x2d270e6886d130d724215a266106e6832161eaed', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_sonic'       => array('146', '0x29219dd400f2bf60e5a23d13be72b486d4038894', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'S'),
    'usdc_cronos'      => array('25', '0x3d7f2c478aafdb65542bcb44bceec05849999d2d', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'CRO'),
    'usdc_hyperevm'    => array('999', '0xb88339cb7199b77e23db6e890353e22632ba630f', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'HYPE'),
    'usdc_morph'       => array('2818', '0xcfb1186f4e93d60e60a8bdd997427d1f33bc372b', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'ETH'),
    'usdc_monad'       => array('143', '0x754704bc059f8c67012fed69bc8a327a5aafb603', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'MON'),
    'usdc_sei'         => array('1329', '0xe15fc38f6d8c56af07bbcbe3baf5708a2bf42392', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'SEI'),
    'usdc_xdc'         => array('50', '0xfa2958cb79b0491cc627c1557f441ef849ca8eb1', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'XDC'),
    'usdc_plume'       => array('98866', '0x222365ef19f7947e5484218551b56bb3965aa7af', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'PLUME'),
    'usdc_injective'   => array('1776', '0xa00c59ff5a080d2b954d0c75e46e22a0c371235a', 6, 'usdc', 'usd-coin', 'Circle', 'issuer_native', 'INJ'),
    'usdt_kava'        => array('2222', '0x919c1c267bc06a7039e03fcc2ef738525769109c', 6, 'usdt', 'tether', 'Tether', 'issuer_native', 'KAVA'),
    'usdt_kaia'        => array('8217', '0xd077a400968890eacc75cdc901f0356c943e4fdb', 6, 'usdt', 'tether', 'Tether', 'issuer_native', 'KAIA'),
    'pyusd_ethereum'   => array('1', '0x6c3ea9036406852006290770bedfcaba0e23a0e8', 6, 'pyusd', 'paypal-usd', 'Paxos / PayPal', 'issuer_native', 'ETH'),
    'pyusd_arbitrum'   => array('42161', '0x46850ad61c2b7d64d08c9c754f45254596696984', 6, 'pyusd', 'paypal-usd', 'Paxos / PayPal', 'issuer_native', 'ETH'),
    'eurc_ethereum'    => array('1', '0x1abaea1f7c830bd89acc67ec4af516284b1bc33c', 6, 'eurc', 'euro-coin', 'Circle', 'issuer_native', 'ETH'),
    'eurc_avalanche'   => array('43114', '0xc891eb4cbdeff6e073e859e987815ed1505c2acd', 6, 'eurc', 'euro-coin', 'Circle', 'issuer_native', 'AVAX'),
    'eurc_base'        => array('8453', '0x60a3e35cc302bfa44cb288bc5a4f316fdb1adb42', 6, 'eurc', 'euro-coin', 'Circle', 'issuer_native', 'ETH'),
    'eurc_cronos'      => array('25', '0xa6de01a2d62c6b5f3525d768f34d276652c554c8', 6, 'eurc', 'euro-coin', 'Circle', 'issuer_native', 'CRO'),
);
qa_routes_same(count($expected_presets), count($presets), 'Every reviewed mainnet preset must ship exactly once');
qa_routes_same(array_keys($expected_presets), array_keys($presets), 'Preset registry order or membership changed unexpectedly');
foreach ($expected_presets as $id => $expected) {
    qa_routes_same($expected[0], $presets[$id]['chain_id'], $id . ' chain ID changed');
    qa_routes_same($expected[1], $presets[$id]['contract_address'], $id . ' contract changed');
    qa_routes_same($expected[2], $presets[$id]['asset_decimals'], $id . ' token decimals changed');
    qa_routes_same($expected[3], $presets[$id]['asset_id'], $id . ' asset ID changed');
    qa_routes_same($expected[4], $presets[$id]['rate_provider_id'], $id . ' rate provider ID changed');
    qa_routes_same($expected[5], $presets[$id]['issuer_label'], $id . ' issuer label changed');
    qa_routes_same($expected[6], $presets[$id]['asset_type'], $id . ' asset type changed');
    qa_routes_same($expected[7], $presets[$id]['fee_symbol'], $id . ' gas symbol changed');
    qa_routes_same(6, $presets[$id]['display_decimals'], $id . ' stablecoin display precision must be six');
    $normalized_preset = JIULIU_CRYPTO_Routes::from_preset($id);
    qa_routes_assert(!is_wp_error($normalized_preset), $id . ' preset must normalize independently');
}
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
qa_routes_same(6, $evm['display_decimals'], 'Quote display precision must be explicit');
qa_routes_same('issuer_native', $evm['asset_type'], 'Issuer-native classification must survive normalization');
qa_routes_same(
    hash('sha256', 'evm|8453|0x833589fcd6edb6e08f4c7c32d4f71b54bda02913|6'),
    $evm['asset_identity'],
    'Financial asset identity must include adapter, chain, contract and decimals'
);
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

qa_routes_same(18, JIULIU_CRYPTO_Routes::from_preset('usdt_bsc')['asset_decimals'], 'BSC USDT must retain its unusual 18-decimal metadata');
qa_routes_same('custodial_peg', JIULIU_CRYPTO_Routes::from_preset('usdt_bsc')['asset_type'], 'BSC USDT must not be presented as Tether-native');
qa_routes_error('unknown_route_preset', JIULIU_CRYPTO_Routes::from_preset('unreviewed_token'), 'Unknown presets must not exist');
qa_routes_error('invalid_routes_json', JIULIU_CRYPTO_Routes::normalize('{broken'), 'Malformed JSON must fail closed');

$bad = $presets['usdc_base'];
$bad['id'] = 'USDC Base';
qa_routes_error('invalid_route_id', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'Unsafe route IDs must be rejected instead of silently sanitized');

$duplicate = JIULIU_CRYPTO_Routes::normalize(array($presets['usdc_base'], $presets['usdc_base']));
qa_routes_error('duplicate_route_id', $duplicate, 'Duplicate route IDs must be rejected');

$bad = $presets['usdc_base'];
$bad['contract_address'] = '0x0000000000000000000000000000000000000000';
qa_routes_error('preset_identity_mismatch', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'Known preset contracts must be immutable');

$bad = $presets['usdc_base'];
$bad['asset_type'] = 'custodial_peg';
qa_routes_error('preset_identity_mismatch', JIULIU_CRYPTO_Routes::normalize(array($bad)), 'A native preset must not be relabeled as a custodial peg');

$custom = $presets['usdc_base'];
$custom['id'] = 'custom_usdc_base';
qa_routes_error('unknown_route_preset', JIULIU_CRYPTO_Routes::normalize(array($custom)), 'Unknown route IDs must not bypass the built-in financial identity allowlist');

qa_routes_error(
    'too_many_routes',
    JIULIU_CRYPTO_Routes::normalize(array_fill(0, JIULIU_CRYPTO_Routes::MAX_ROUTES + 1, $presets['usdc_base'])),
    'Route registry hard limit must be enforced before parsing entries'
);

$bad_rate = $presets['usdc_base'];
$bad_rate['rate_cny'] = '72';
qa_routes_error('invalid_route_rate', JIULIU_CRYPTO_Routes::normalize(array($bad_rate)), 'USD stablecoin rate 72 must not cause a tenfold undercharge');
$bad_eurc_rate = $presets['eurc_base'];
$bad_eurc_rate['rate_cny'] = '31';
qa_routes_error('invalid_route_rate', JIULIU_CRYPTO_Routes::normalize(array($bad_eurc_rate)), 'EURC rate must remain within its bounded CNY range');
$valid_eurc_rate = $presets['eurc_base'];
$valid_eurc_rate['rate_cny'] = '25';
qa_routes_assert(!is_wp_error(JIULIU_CRYPTO_Routes::normalize(array($valid_eurc_rate))), 'EURC must use its explicit 1-30 CNY range');

$unsupported_asset = $presets['usdc_base'];
$unsupported_asset['asset_symbol'] = 'BTC';
$unsupported_asset['asset_id'] = 'btc';
$unsupported_asset['rate_provider_id'] = 'bitcoin';
qa_routes_error('preset_identity_mismatch', JIULIU_CRYPTO_Routes::normalize(array($unsupported_asset)), 'A reviewed route cannot be relabeled as an unreviewed volatile asset');

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
    'unknown_route_preset',
    JIULIU_CRYPTO_Routes::normalize(array($presets['usdc_base'], $chain_conflict)),
    'A second unreviewed route cannot assign a reviewed chain identity to another key'
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
