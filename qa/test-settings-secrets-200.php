<?php

// 2.0.0 provider-secret persistence contract. Password-style fields render
// blank in wp-admin, so an ordinary save must preserve existing credentials;
// replacement and deletion require explicit administrator intent.

define('ABSPATH', __DIR__ . '/');

$GLOBALS['qa_secret_options'] = array();

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
function wp_unslash($value) { return $value; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, is_array($args) ? $args : array()); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_generate_password($length = 12) { return str_repeat('x', (int) $length); }
function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['qa_secret_options']) ? $GLOBALS['qa_secret_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null)
{
    $GLOBALS['qa_secret_options'][$key] = $value;
    return true;
}
function delete_transient($key) { return true; }

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-routes.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-settings.php';

function qa_secret_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_secret_assert($condition, $message)
{
    if (!$condition) {
        qa_secret_fail($message);
    }
}

function qa_secret_routes_by_id($settings)
{
    $routes = array();
    foreach ((array) $settings['payment_routes'] as $route) {
        $routes[$route['id']] = $route;
    }
    return $routes;
}

function qa_secret_seed()
{
    $evm = JIULIU_CRYPTO_Routes::from_preset('usdc_base', array(
        'enabled' => 1,
        'receive_address' => '0x1111111111111111111111111111111111111111',
        'rpc_url' => 'https://base-rpc.example.test/v1',
        'rpc_headers' => array(
            'Authorization' => 'Bearer existing-secret',
            'X-Project' => 'project-a',
        ),
        'rate_cny' => '7.20',
    ));
    $tron = JIULIU_CRYPTO_Routes::from_preset('usdt_trc20', array(
        'enabled' => 1,
        // The official USDT contract is also a valid Base58Check address and is
        // sufficient as a deterministic non-production receiver in this test.
        'receive_address' => JIULIU_CRYPTO_Settings::USDT_CONTRACT,
        'api_key' => 'existing-trongrid-secret',
        'rate_cny' => '7.20',
    ));
    qa_secret_assert(!is_wp_error($evm) && !is_wp_error($tron), 'route fixtures are invalid');

    $seed = (new JIULIU_CRYPTO_Settings())->defaults();
    $seed['payment_routes'] = array($evm, $tron);
    $seed['cron_token'] = str_repeat('c', 40);
    $GLOBALS['qa_secret_options'][JIULIU_CRYPTO_Settings::OPTION_NAME] = $seed;
    return $seed;
}

function qa_secret_input($seed)
{
    return array(
        'enabled' => 1,
        'payment_routes' => $seed['payment_routes'],
        'rate_mode' => 'fixed',
        'fixed_rate' => '7.20',
        'auto_rate_max_deviation' => '10',
        'rate_markup' => '0',
        'invoice_timeout' => 15,
        'minimum_local_amount' => '1',
        'maximum_local_amount' => '100000',
        'late_grace_hours' => 24,
        'log_retention_days' => 90,
        'cron_token' => str_repeat('c', 40),
        'cron_ip_allowlist' => '',
    );
}

// Blank password fields preserve the already configured values.
$seed = qa_secret_seed();
$input = qa_secret_input($seed);
$input['payment_routes'][0]['rpc_headers_json'] = '';
$input['payment_routes'][0]['rpc_headers'] = array();
$input['payment_routes'][1]['api_key'] = '';
$saved = (new JIULIU_CRYPTO_Settings())->update($input);
qa_secret_assert(!is_wp_error($saved), 'ordinary settings save failed');
$routes = qa_secret_routes_by_id($saved);
qa_secret_assert(
    isset($routes['usdc_base']['rpc_headers']['Authorization'])
        && 'Bearer existing-secret' === $routes['usdc_base']['rpc_headers']['Authorization'],
    'blank EVM credential field erased an existing Authorization header'
);
qa_secret_assert(
    'existing-trongrid-secret' === $routes['usdt_trc20']['api_key'],
    'blank TronGrid API key field erased an existing key'
);

// Non-blank administrator input replaces the corresponding credential only.
$seed = qa_secret_seed();
$input = qa_secret_input($seed);
$input['payment_routes'][0]['rpc_headers_json'] = json_encode(array('Authorization' => 'Bearer replacement'));
$input['payment_routes'][1]['api_key'] = 'replacement-trongrid-key';
$saved = (new JIULIU_CRYPTO_Settings())->update($input);
qa_secret_assert(!is_wp_error($saved), 'explicit credential replacement failed');
$routes = qa_secret_routes_by_id($saved);
qa_secret_assert(
    array('Authorization' => 'Bearer replacement') === $routes['usdc_base']['rpc_headers'],
    'explicit EVM header replacement was not persisted exactly'
);
qa_secret_assert(
    'replacement-trongrid-key' === $routes['usdt_trc20']['api_key'],
    'explicit TronGrid API key replacement was not persisted'
);

// Explicit clear checkboxes delete credentials, even if stale browser values
// are submitted in the password inputs at the same time.
$seed = qa_secret_seed();
$input = qa_secret_input($seed);
$input['payment_routes'][0]['rpc_headers_json'] = json_encode(array('Authorization' => 'Bearer stale-browser-value'));
$input['payment_routes'][0]['clear_rpc_headers'] = 1;
$input['payment_routes'][1]['api_key'] = 'stale-browser-value';
$input['payment_routes'][1]['clear_api_key'] = 1;
$saved = (new JIULIU_CRYPTO_Settings())->update($input);
qa_secret_assert(!is_wp_error($saved), 'explicit credential clear failed');
$routes = qa_secret_routes_by_id($saved);
qa_secret_assert(array() === $routes['usdc_base']['rpc_headers'], 'explicit EVM header clear did not delete the secret');
qa_secret_assert('' === $routes['usdt_trc20']['api_key'], 'explicit TronGrid API key clear did not delete the secret');

// Malformed replacement input must reject the entire save and retain the old
// option atomically rather than partially persisting route credentials.
$seed = qa_secret_seed();
$before = $GLOBALS['qa_secret_options'][JIULIU_CRYPTO_Settings::OPTION_NAME];
$input = qa_secret_input($seed);
$input['payment_routes'][0]['rpc_headers_json'] = '{not-json';
$failed = (new JIULIU_CRYPTO_Settings())->update($input);
qa_secret_assert(is_wp_error($failed) && 'invalid_rpc_headers_json' === $failed->get_error_code(), 'malformed RPC header JSON was accepted');
qa_secret_assert(
    $before === $GLOBALS['qa_secret_options'][JIULIU_CRYPTO_Settings::OPTION_NAME],
    'a rejected credential save partially changed stored settings'
);

fwrite(STDOUT, "OK: 2.0.0 provider-secret preserve, replace and clear contracts passed\n");
