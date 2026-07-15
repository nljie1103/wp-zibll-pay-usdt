<?php

// 2.1.1 wp-admin route-manager contract. This intentionally renders the real
// private settings screen with lightweight WordPress stubs, then inspects the
// resulting form. It catches presentation regressions that static grep alone
// cannot see (missing disabled routes, duplicate names, leaked credentials,
// shortened values accidentally submitted, or JavaScript-only configuration).

define('ABSPATH', __DIR__ . '/');
define('JIULIU_CRYPTO_URL', 'https://example.test/wp-content/plugins/jiuliu-crypto-payment/');
define('JIULIU_CRYPTO_VERSION', '2.1.1');
define('MINUTE_IN_SECONDS', 60);

$GLOBALS['qa_211_options'] = array();
$GLOBALS['qa_211_enqueued_styles'] = array();
$GLOBALS['qa_211_enqueued_scripts'] = array();

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
function esc_html__($value, $domain = null) { return $value; }
function esc_attr__($value, $domain = null) { return $value; }
function esc_html($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_url($value) { return esc_attr($value); }
function esc_textarea($value) { return esc_html($value); }
function sanitize_text_field($value) { return trim(strip_tags((string) $value)); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_html_class($value, $fallback = '')
{
    $value = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $value);
    return '' === $value ? (string) $fallback : $value;
}
function absint($value) { return abs((int) $value); }
function wp_unslash($value) { return $value; }
function wp_parse_args($args, $defaults = array()) { return array_merge($defaults, is_array($args) ? $args : array()); }
function wp_json_encode($value, $flags = 0) { return json_encode($value, $flags); }
function wp_generate_password($length = 12) { return str_repeat('x', (int) $length); }
function home_url() { return 'https://example.test/'; }
function apply_filters($tag, $value) { return $value; }
function current_time() { return '2026-07-15 12:00:00'; }
function checked($checked, $current = true, $echo = true)
{
    $result = ((string) $checked === (string) $current) ? ' checked="checked"' : '';
    if ($echo) { echo $result; }
    return $result;
}
function selected($selected, $current = true, $echo = true)
{
    $result = ((string) $selected === (string) $current) ? ' selected="selected"' : '';
    if ($echo) { echo $result; }
    return $result;
}
function admin_url($path = '') { return 'https://example.test/wp-admin/' . ltrim((string) $path, '/'); }
function add_action($hook, $callback, $priority = 10, $accepted_args = 1) { return true; }
function add_menu_page() { return true; }
function wp_nonce_field($action) { echo '<input type="hidden" name="_wpnonce" value="qa">'; }
function submit_button($text, $type = 'primary', $name = 'submit', $wrap = true)
{
    $button = '<button type="submit"' . ('' !== (string) $name ? ' name="' . esc_attr($name) . '"' : '') . '>' . esc_html($text) . '</button>';
    echo $wrap ? '<p class="submit">' . $button . '</p>' : $button;
}
function wp_enqueue_style($handle, $src = '', $deps = array(), $version = false)
{
    $GLOBALS['qa_211_enqueued_styles'][$handle] = array($src, $deps, $version);
}
function wp_enqueue_script($handle, $src = '', $deps = array(), $version = false, $in_footer = false)
{
    $GLOBALS['qa_211_enqueued_scripts'][$handle] = array($src, $deps, $version, $in_footer);
}
function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['qa_211_options']) ? $GLOBALS['qa_211_options'][$key] : $default;
}
function update_option($key, $value, $autoload = null)
{
    $GLOBALS['qa_211_options'][$key] = $value;
    return true;
}
function delete_transient($key) { return true; }

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-util.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-routes.php';
require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-settings.php';

class JIULIU_CRYPTO_DB {}
class JIULIU_CRYPTO_Rate {}
class JIULIU_CRYPTO_Trongrid {}
class JIULIU_CRYPTO_Invoices {}

require_once __DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-admin.php';

function qa_211_fail($message)
{
    fwrite(STDERR, 'FAIL: ' . $message . "\n");
    exit(1);
}

function qa_211_assert($condition, $message)
{
    if (!$condition) {
        qa_211_fail($message);
    }
}

function qa_211_route_map($routes)
{
    $map = array();
    foreach ((array) $routes as $route) {
        if (isset($route['id'])) {
            $map[(string) $route['id']] = $route;
        }
    }
    return $map;
}

function qa_211_xpath_literal($value)
{
    $value = (string) $value;
    qa_211_assert(false === strpos($value, "'"), 'single quote is unsupported in this QA XPath literal');
    return "'" . $value . "'";
}

function qa_211_named_nodes(DOMXPath $xpath, $name)
{
    return $xpath->query('//*[@name=' . qa_211_xpath_literal($name) . ']');
}

$settings = new JIULIU_CRYPTO_Settings();
$seed = $settings->defaults();
$preset_registry = new JIULIU_CRYPTO_Routes(array_values(JIULIU_CRYPTO_Routes::presets()));
$routes = $preset_registry->all(false);
qa_211_assert(!is_wp_error($preset_registry->get_error()), 'built-in route catalog is invalid');
qa_211_assert(count(JIULIU_CRYPTO_Routes::presets()) === count($routes), 'all(false) did not retain the complete route catalog');

$base_address = '0x1111111111111111111111111111111111111111';
$tron_address = JIULIU_CRYPTO_Settings::USDT_CONTRACT;
$evm_secret = 'qa-private-rpc-header-must-never-render';
$tron_secret = 'qa-private-trongrid-key-must-never-render';

$routes['usdc_base']['enabled'] = 1;
$routes['usdc_base']['receive_address'] = $base_address;
$routes['usdc_base']['rpc_url'] = 'https://base.example.test/rpc';
$routes['usdc_base']['rpc_headers'] = array('Authorization' => 'Bearer ' . $evm_secret);
$routes['usdc_base']['rate_cny'] = '7.21000000';
$routes['usdt_trc20']['enabled'] = 1;
$routes['usdt_trc20']['receive_address'] = $tron_address;
$routes['usdt_trc20']['api_key'] = $tron_secret;
$routes['usdt_trc20']['rate_cny'] = '7.20000000';
$seed['payment_routes'] = array_values($routes);
$seed['coingecko_api_key'] = 'qa-private-coingecko-key-must-never-render';
$GLOBALS['qa_211_options'][JIULIU_CRYPTO_Settings::OPTION_NAME] = $seed;

$admin = new JIULIU_CRYPTO_Admin(
    $settings,
    new JIULIU_CRYPTO_DB(),
    new JIULIU_CRYPTO_Rate(),
    new JIULIU_CRYPTO_Trongrid(),
    new JIULIU_CRYPTO_Invoices(),
    new JIULIU_CRYPTO_Routes($seed['payment_routes'])
);

// Asset loading must be impossible outside this plugin's top-level screen.
$admin->enqueue_assets('plugins.php');
qa_211_assert(empty($GLOBALS['qa_211_enqueued_scripts']) && empty($GLOBALS['qa_211_enqueued_styles']), 'admin assets leaked onto another wp-admin screen');
$admin->enqueue_assets('toplevel_page_jiuliu-crypto');
qa_211_assert(isset($GLOBALS['qa_211_enqueued_styles']['jiuliu-crypto-admin']), 'admin stylesheet was not loaded on the plugin screen');
qa_211_assert(isset($GLOBALS['qa_211_enqueued_scripts']['jiuliu-crypto-admin']), 'admin route-manager script was not loaded on the plugin screen');
qa_211_assert(false !== strpos($GLOBALS['qa_211_enqueued_scripts']['jiuliu-crypto-admin'][0], 'assets/js/admin.js'), 'wrong admin script was enqueued');

$method = new ReflectionMethod('JIULIU_CRYPTO_Admin', 'render_settings');
$method->setAccessible(true);
ob_start();
$method->invoke($admin);
$html = ob_get_clean();

foreach (array($evm_secret, $tron_secret, 'qa-private-coingecko-key-must-never-render') as $secret) {
    qa_211_assert(false === strpos($html, $secret), 'saved credential was emitted into settings HTML');
}

$dom = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$loaded = $dom->loadHTML('<?xml encoding="UTF-8"><!doctype html><html><body>' . $html . '</body></html>');
libxml_clear_errors();
libxml_use_internal_errors($previous);
qa_211_assert($loaded, 'rendered settings HTML could not be parsed');
$xpath = new DOMXPath($dom);

$config_details = $xpath->query('//details[@data-route-config]');
qa_211_assert(count($routes) === $config_details->length, 'not every all(false) route has one real configuration panel');
qa_211_assert(0 === $xpath->query('//details[@data-route-config and @open]')->length, 'a route configuration panel is open by default');
qa_211_assert(count($routes) === $xpath->query('//*[@data-route-summary]')->length, 'route summaries do not cover the complete catalog exactly once');
qa_211_assert(0 < $xpath->query('//button[@data-route-set-enabled]')->length, 'route summaries lack enable/disable actions');

// Native details/summary must remain usable if JavaScript is blocked.
foreach ($config_details as $details) {
    qa_211_assert(!$details->hasAttribute('hidden'), 'route configuration is hidden without JavaScript');
    $summaries = $xpath->query('./summary', $details);
    qa_211_assert(1 === $summaries->length, 'route configuration lacks a native direct-child summary');
}
qa_211_assert(count($routes) === $xpath->query('//details[contains(concat(" ", normalize-space(@class), " "), " jiuliu-route-advanced ")]')->length, 'advanced/security metadata is not isolated once per route');

$immutable = array(
    'id', 'asset_id', 'asset_symbol', 'asset_name', 'asset_decimals',
    'display_decimals', 'rate_provider_id', 'issuer_label', 'asset_type',
    'adapter', 'network_label', 'chain_key', 'chain_id', 'contract_address',
    'fee_symbol', 'scan_block_chunk', 'scan_max_blocks', 'scan_max_results',
    'rpc_timeout',
);
$editable_common = array('enabled', 'receive_address', 'required_confirmations', 'rate_cny');

// Every route, including disabled routes, must submit the original field names
// exactly once. A display-only summary must never create a second form value.
$all_names = array();
foreach ($xpath->query('//*[@name]') as $node) {
    $name = html_entity_decode($node->getAttribute('name'), ENT_QUOTES, 'UTF-8');
    if (isset($all_names[$name])) {
        qa_211_fail('duplicate form control name: ' . $name);
    }
    $all_names[$name] = true;
}

foreach ($routes as $route_id => $route) {
    $base = 'settings[payment_routes][' . $route_id . ']';
    foreach (array_merge($immutable, $editable_common) as $field) {
        $name = $base . '[' . $field . ']';
        qa_211_assert(isset($all_names[$name]), 'missing route field: ' . $name);
    }
    if ('evm' === $route['adapter']) {
        foreach (array('rpc_url', 'rpc_headers_json', 'clear_rpc_headers', 'api_key') as $field) {
            qa_211_assert(isset($all_names[$base . '[' . $field . ']']), 'missing EVM field: ' . $route_id . '/' . $field);
        }
    } else {
        foreach (array('rpc_url', 'api_key', 'clear_api_key') as $field) {
            qa_211_assert(isset($all_names[$base . '[' . $field . ']']), 'missing Tron field: ' . $route_id . '/' . $field);
        }
    }
}

// Pick a disabled route explicitly: it must still exist in the same form.
$disabled_id = 'usdc_arbitrum';
qa_211_assert(empty($routes[$disabled_id]['enabled']), 'disabled-route fixture unexpectedly enabled');
qa_211_assert(isset($all_names['settings[payment_routes][' . $disabled_id . '][receive_address]']), 'disabled route configuration was dropped from the form');

// The configured address is submitted in full, while its summary is compact.
$address_nodes = qa_211_named_nodes($xpath, 'settings[payment_routes][usdc_base][receive_address]');
qa_211_assert(1 === $address_nodes->length, 'configured receiver input is missing or duplicated');
qa_211_assert($base_address === $address_nodes->item(0)->getAttribute('value'), 'shortened address replaced the true submitted value');
$base_summary = $xpath->query('//*[@data-route-summary and @data-route-id="usdc_base"]');
qa_211_assert(1 === $base_summary->length, 'configured route summary is missing');
$summary_text = trim($base_summary->item(0)->textContent);
qa_211_assert(false === strpos($summary_text, $base_address), 'summary displays an unshortened receiver address');
qa_211_assert(false !== strpos($summary_text, '…') || false !== strpos($summary_text, '...'), 'summary does not visibly shorten the receiver address');

// Password inputs must always render blank; placeholder/masked state is enough.
foreach ($xpath->query('//input[@type="password"]') as $password) {
    qa_211_assert('' === $password->getAttribute('value'), 'password-style credential rendered a plaintext value');
}

// Exercise the real settings normalizer with all routes present. Disabling a
// configured route and enabling another must retain both routes' non-secret and
// secret configuration; a compact manager is presentation, not data deletion.
$submitted = $seed;
$submitted['enabled'] = 0;
$submitted_routes = qa_211_route_map($seed['payment_routes']);
$submitted_routes['usdc_base']['enabled'] = 0;
$submitted_routes['usdc_base']['rpc_headers_json'] = '';
$submitted_routes['usdc_arbitrum']['enabled'] = 1;
$submitted_routes['usdc_arbitrum']['receive_address'] = '0x2222222222222222222222222222222222222222';
$submitted_routes['usdc_arbitrum']['rpc_url'] = 'https://arbitrum.example.test/rpc';
$submitted_routes['usdc_arbitrum']['rpc_headers_json'] = '';
$submitted['payment_routes'] = $submitted_routes;
$saved = $settings->update($submitted);
qa_211_assert(!is_wp_error($saved), 'full route-manager settings submission was rejected');
$saved_routes = qa_211_route_map($saved['payment_routes']);
qa_211_assert(count($routes) === count($saved_routes), 'settings save dropped enabled or disabled routes');
qa_211_assert(empty($saved_routes['usdc_base']['enabled']), 'configured route was not disabled');
qa_211_assert($base_address === $saved_routes['usdc_base']['receive_address'], 'disabled route lost its receiver configuration');
qa_211_assert('https://base.example.test/rpc' === $saved_routes['usdc_base']['rpc_url'], 'disabled route lost its RPC configuration');
qa_211_assert(
    isset($saved_routes['usdc_base']['rpc_headers']['Authorization'])
        && 'Bearer ' . $evm_secret === $saved_routes['usdc_base']['rpc_headers']['Authorization'],
    'blank secret editor did not preserve the disabled route credential'
);
qa_211_assert(!empty($saved_routes['usdc_arbitrum']['enabled']), 'configured route was not enabled');
qa_211_assert('0x2222222222222222222222222222222222222222' === $saved_routes['usdc_arbitrum']['receive_address'], 'newly enabled route lost its receiver configuration');
qa_211_assert('https://arbitrum.example.test/rpc' === $saved_routes['usdc_arbitrum']['rpc_url'], 'newly enabled route lost its RPC configuration');

$admin_source = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/includes/class-jiuliu-crypto-admin.php');
$admin_js = file_get_contents(__DIR__ . '/../jiuliu-crypto-payment/assets/js/admin.js');
qa_211_assert(false !== strpos($admin_source, '->all(false)'), 'admin stopped loading disabled routes via all(false)');
qa_211_assert(false !== strpos($admin_js, 'data-route-open'), 'native admin script cannot open a selected route');
qa_211_assert(false !== strpos($admin_js, 'data-route-search'), 'native admin script lacks route search handling');
qa_211_assert(
    false !== strpos($admin_js, 'data-route-status') && false !== strpos($admin_js, 'data-route-asset'),
    'native admin script lacks status/asset filter handling'
);
qa_211_assert(false !== strpos($admin_js, 'data-route-toggle-available'), 'empty-state add-route button has no native script handler');
qa_211_assert(
    false !== strpos($admin_js, "closest('[data-route-set-enabled]')"),
    'summary enable/disable actions have no native script handler'
);
qa_211_assert(false === stripos($admin_js, 'jquery'), 'admin route manager unexpectedly depends on jQuery');

fwrite(STDOUT, "OK: 2.1.1 compact admin route-manager and persistence contracts passed\n");
