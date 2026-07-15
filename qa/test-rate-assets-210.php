<?php

define('ABSPATH', __DIR__ . '/');
define('JIULIU_CRYPTO_VERSION', '2.1.0');
define('MINUTE_IN_SECONDS', 60);

class WP_Error
{
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error($value) { return $value instanceof WP_Error; }
function __($message) { return $message; }
function current_time() { return '2026-07-15 12:00:00'; }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }
function home_url() { return 'https://example.test/'; }
function apply_filters($tag, $value) { return $value; }

$GLOBALS['qa_rate_transients'] = array();
$GLOBALS['qa_rate_last_url'] = '';
function get_transient($key) { return isset($GLOBALS['qa_rate_transients'][$key]) ? $GLOBALS['qa_rate_transients'][$key] : false; }
function set_transient($key, $value) { $GLOBALS['qa_rate_transients'][$key] = $value; return true; }
function delete_transient($key) { unset($GLOBALS['qa_rate_transients'][$key]); return true; }
function wp_remote_get($url)
{
    $GLOBALS['qa_rate_last_url'] = $url;
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    $id = isset($query['ids']) ? (string) $query['ids'] : '';
    $prices = array(
        'tether' => 7.2,
        'usd-coin' => 7.21,
        'first-digital-usd' => 7.19,
        'euro-coin' => 8.4,
        'paypal-usd' => 7.18,
    );
    return array(
        'code' => isset($prices[$id]) ? 200 : 404,
        'body' => json_encode(array($id => array('cny' => isset($prices[$id]) ? $prices[$id] : 0, 'last_updated_at' => time()))),
    );
}
function wp_remote_retrieve_response_code($response) { return (int) $response['code']; }
function wp_remote_retrieve_body($response) { return (string) $response['body']; }

class JIULIU_CRYPTO_Util
{
    public static function random_token() { return str_repeat('x', 64); }
}

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-settings.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-rate.php';

class QA_Rate_Settings extends JIULIU_CRYPTO_Settings
{
    public function get($key, $default = null)
    {
        $values = array(
            'fixed_rate' => '7.20',
            'rate_mode' => 'auto',
            'auto_rate_max_deviation' => '30',
            'coingecko_api_key' => '',
        );
        return array_key_exists($key, $values) ? $values[$key] : $default;
    }
}

function qa_rate_fail($message)
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

function qa_rate_assert($condition, $message)
{
    if (!$condition) {
        qa_rate_fail($message);
    }
}

function qa_rate_route($symbol, $asset_id, $provider, $rate, $mode)
{
    return array(
        'id' => strtolower($symbol) . '_qa',
        'asset_symbol' => $symbol,
        'asset_id' => $asset_id,
        'rate_provider_id' => $provider,
        'rate_cny' => (string) $rate,
        'rate_mode' => $mode,
    );
}

$rate = new JIULIU_CRYPTO_Rate(new QA_Rate_Settings());
$catalog = JIULIU_CRYPTO_Rate::asset_catalog();
$expected = array(
    'USDT' => array('usdt', 'tether', 7.2),
    'USDC' => array('usdc', 'usd-coin', 7.21),
    'FDUSD' => array('fdusd', 'first-digital-usd', 7.19),
    'EURC' => array('eurc', 'euro-coin', 8.4),
    'PYUSD' => array('pyusd', 'paypal-usd', 7.18),
);

foreach ($expected as $symbol => $identity) {
    qa_rate_assert(isset($catalog[$symbol]), $symbol . ' missing from explicit rate catalog');
    $route = qa_rate_route($symbol, $identity[0], $identity[1], 'EURC' === $symbol ? 8.5 : 7.2, 'auto');
    $result = $rate->get_rate(true, $symbol, $route);
    qa_rate_assert(empty($result['fallback']), $symbol . ' unexpectedly fell back');
    qa_rate_assert($identity[0] === $result['asset_id'], $symbol . ' asset_id was not retained');
    qa_rate_assert($identity[1] === $result['rate_provider_id'], $symbol . ' provider identity was not retained');
    qa_rate_assert(false !== strpos($GLOBALS['qa_rate_last_url'], rawurlencode($identity[1])), $symbol . ' requested the wrong CoinGecko ID');
}

$missing_route = $rate->get_rate(false, 'USDT', array());
qa_rate_assert(0 === (int) $missing_route['rate'] && 'untrusted_asset_identity' === $missing_route['source'], 'symbol-only quote was trusted');

$spoofed = qa_rate_route('USDT', 'usdt', 'usd-coin', 7.2, 'fixed');
$spoofed_result = $rate->get_rate(false, 'USDT', $spoofed);
qa_rate_assert(0 === (int) $spoofed_result['rate'] && 'asset_identity_mismatch' === $spoofed_result['source'], 'mismatched rate provider was accepted');

$usd_high = $rate->get_rate(false, 'USDT', qa_rate_route('USDT', 'usdt', 'tether', 20.01, 'fixed'));
qa_rate_assert('invalid_fixed_rate' === $usd_high['source'], 'USD stablecoin accepted an unsafe fixed anchor');
$eur_ok = $rate->get_rate(false, 'EURC', qa_rate_route('EURC', 'eurc', 'euro-coin', 30, 'fixed'));
qa_rate_assert('fixed' === $eur_ok['source'], 'EURC safe upper bound was rejected');
$eur_high = $rate->get_rate(false, 'EURC', qa_rate_route('EURC', 'eurc', 'euro-coin', 30.01, 'fixed'));
qa_rate_assert('invalid_fixed_rate' === $eur_high['source'], 'EURC accepted an unsafe fixed anchor');

$admin = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-admin.php');
$frontend = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/assets/js/frontend.js');
$css = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/assets/css/frontend.css');
$zibll = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-zibll.php');
$invoices = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-invoices.php');
qa_rate_assert(false !== strpos($admin, '<details class="jiuliu-crypto-route-card"'), 'admin route cards are not collapsible');
qa_rate_assert(false !== strpos($admin, '网站必须完整收到收银台显示的精确金额'), 'admin exact-receipt warning missing');
qa_rate_assert(false !== strpos($frontend, 'jiuliu-crypto-asset-select') && false !== strpos($frontend, 'jiuliu-crypto-network-select'), 'two-level frontend selector missing');
qa_rate_assert(false !== strpos($frontend, 'data-jiuliu-method') && false !== strpos($frontend, '$target.trigger(\'click\')'), 'selector does not drive real Zibll method elements');
qa_rate_assert(false === strpos($frontend, "data.push({ name: 'payment_method'"), 'selector rewrites AJAX payment_method');
qa_rate_assert(false !== strpos($frontend, 'if (!response.jiuliu_crypto && !responseMethod)'), 'plugin response without payment_method is not handled');
qa_rate_assert(false !== strpos($frontend, '$host.removeData(\'jiuliuCryptoRouteGroup\')'), 'stale grouped selector cannot be cleaned before reinsertion');
qa_rate_assert(false !== strpos($frontend, '网站必须完整收到支付单显示的精确金额') && false !== strpos($frontend, '不得从显示金额中扣除'), 'frontend exact amount/fee warning missing');
qa_rate_assert(false !== strpos($css, '.jiuliu-crypto-source-method') && false !== strpos($css, '.jiuliu-crypto-route-selector'), 'selector CSS missing');
qa_rate_assert(false !== strpos($zibll, "{1,18}"), '18-decimal settlement proof is rejected by the Zibll receipt renderer');
qa_rate_assert(false !== strpos($invoices, "'EURC' === \$asset_symbol ? '€'"), 'EURC cashier mark is not rendered as euro');
qa_rate_assert(false !== strpos($invoices, '托管锚定资产提示'), 'custodial-peg warning is missing from the final payment details');

echo "Multi-asset rate and grouped picker QA passed\n";
