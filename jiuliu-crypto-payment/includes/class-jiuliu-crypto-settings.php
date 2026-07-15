<?php

if (!defined('ABSPATH')) {
    exit;
}

/** Multi-chain gateway settings. */
class JIULIU_CRYPTO_Settings
{
    const OPTION_NAME = 'jiuliu_crypto_settings';
    const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';
    const MIN_FIXED_RATE = 1;
    const MAX_FIXED_RATE = 30;

    private $cache;

    public function defaults()
    {
        return array(
            'enabled'                   => 0,
            'pause_monitoring'          => 0,
            'payment_routes'            => array(),
            'rate_mode'                 => 'fixed',
            'fixed_rate'                => '7.20',
            'coingecko_api_key'         => '',
            'auto_rate_max_deviation'   => '10',
            'rate_markup'               => '0',
            'invoice_timeout'           => 15,
            'minimum_local_amount'      => '1',
            'maximum_local_amount'      => '100000',
            'frontend_manual_txid'      => 1,
            'monitor_closed_orders'     => 1,
            'admin_email_notifications' => 1,
            'user_email_notifications'  => 1,
            'late_grace_hours'          => 24,
            'log_retention_days'        => 90,
            'cron_token'                => JIULIU_CRYPTO_Util::random_token(32),
            'cron_ip_allowlist'         => '',
        );
    }

    public function install_defaults()
    {
        $existing = get_option(self::OPTION_NAME, array());
        if (!is_array($existing)) {
            $existing = array();
        }
        $settings = wp_parse_args($existing, $this->defaults());
        if (empty($settings['payment_routes']) && class_exists('JIULIU_CRYPTO_Routes')) {
            $registry = new JIULIU_CRYPTO_Routes();
            foreach ($registry->presets() as $preset_id => $preset) {
                $preset['enabled'] = 0;
                $route = $registry->from_preset($preset_id, $preset);
                if (!is_wp_error($route)) {
                    $settings['payment_routes'][] = $route;
                }
            }
        }
        update_option(self::OPTION_NAME, $settings, false);
        $this->cache = null;
    }

    public function all()
    {
        if (null === $this->cache) {
            $value = get_option(self::OPTION_NAME, array());
            $this->cache = wp_parse_args(is_array($value) ? $value : array(), $this->defaults());
        }
        return $this->cache;
    }

    public function get($key, $default = null)
    {
        $settings = $this->all();
        if ('coingecko_api_key' === $key && defined('JIULIU_CRYPTO_COINGECKO_API_KEY')) {
            return trim((string) JIULIU_CRYPTO_COINGECKO_API_KEY);
        }
        if ('cron_token' === $key && defined('JIULIU_CRYPTO_CRON_TOKEN')) {
            return trim((string) JIULIU_CRYPTO_CRON_TOKEN);
        }
        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function is_enabled()
    {
        if (PHP_INT_SIZE < 8 || !(bool) $this->get('enabled') || (bool) $this->get('pause_monitoring')) {
            return false;
        }
        if (!class_exists('JIULIU_CRYPTO_Routes')) {
            return false;
        }
        $routes = new JIULIU_CRYPTO_Routes((array) $this->get('payment_routes', array()));
        return (bool) $routes->enabled();
    }

    public function update($input)
    {
        $old = $this->all();
        $new = $old;
        $input = is_array($input) ? $input : array();

        $new['enabled']                   = empty($input['enabled']) ? 0 : 1;
        $new['pause_monitoring']          = empty($input['pause_monitoring']) ? 0 : 1;
        $new['rate_mode']                 = !empty($input['rate_mode']) && 'auto' === $input['rate_mode'] ? 'auto' : 'fixed';
        $new['frontend_manual_txid']      = empty($input['frontend_manual_txid']) ? 0 : 1;
        $new['monitor_closed_orders']     = empty($input['monitor_closed_orders']) ? 0 : 1;
        $new['admin_email_notifications'] = empty($input['admin_email_notifications']) ? 0 : 1;
        $new['user_email_notifications']  = empty($input['user_email_notifications']) ? 0 : 1;

        if (!class_exists('JIULIU_CRYPTO_Routes')) {
            return new WP_Error('route_registry_missing', __('支付路线组件未加载。', 'jiuliu-crypto-payment'));
        }
        $route_input = isset($input['payment_routes']) && is_array($input['payment_routes'])
            ? wp_unslash($input['payment_routes'])
            : array();

        // Provider credentials are edited with explicit replace/clear fields.
        // A normal settings save must never silently erase a working API key
        // or Authorization header merely because password fields render blank.
        $old_registry = new JIULIU_CRYPTO_Routes((array) $old['payment_routes']);
        $old_routes = $old_registry->all(false);
        foreach ($route_input as $route_key => &$candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $route_id = !empty($candidate['id']) ? (string) $candidate['id'] : (string) $route_key;
            $prior = isset($old_routes[$route_id]) ? $old_routes[$route_id] : array();
            $adapter = !empty($candidate['adapter']) ? strtolower((string) $candidate['adapter']) : '';

            if ('evm' === $adapter) {
                $headers_json = isset($candidate['rpc_headers_json']) ? trim((string) $candidate['rpc_headers_json']) : '';
                if (!empty($candidate['clear_rpc_headers'])) {
                    $candidate['rpc_headers'] = array();
                } elseif ('' !== $headers_json) {
                    $decoded_headers = json_decode($headers_json, true);
                    if (!is_array($decoded_headers) || JSON_ERROR_NONE !== json_last_error()) {
                        unset($candidate);
                        return new WP_Error('invalid_rpc_headers_json', __('RPC 请求头必须是 JSON 对象。', 'jiuliu-crypto-payment'));
                    }
                    $candidate['rpc_headers'] = $decoded_headers;
                } else {
                    $candidate['rpc_headers'] = isset($prior['rpc_headers']) && is_array($prior['rpc_headers'])
                        ? $prior['rpc_headers']
                        : array();
                }
                $candidate['api_key'] = '';
            } else {
                $new_key = isset($candidate['api_key']) ? trim((string) $candidate['api_key']) : '';
                if (!empty($candidate['clear_api_key'])) {
                    $candidate['api_key'] = '';
                } elseif ('' === $new_key) {
                    $candidate['api_key'] = isset($prior['api_key']) ? (string) $prior['api_key'] : '';
                }
                $candidate['rpc_headers'] = array();
            }
            unset($candidate['rpc_headers_json'], $candidate['clear_rpc_headers'], $candidate['clear_api_key']);
        }
        unset($candidate);

        $registry = new JIULIU_CRYPTO_Routes();
        $normalized_routes = $registry->normalize($route_input);
        if (is_wp_error($normalized_routes)) {
            return $normalized_routes;
        }
        $new['payment_routes'] = $normalized_routes;

        if ($new['enabled']) {
            $enabled_registry = new JIULIU_CRYPTO_Routes($new['payment_routes']);
            if (!$enabled_registry->enabled()) {
                return new WP_Error('no_enabled_routes', __('启用网关前至少要完整配置并启用一条支付路线。', 'jiuliu-crypto-payment'));
            }
        }

        if (isset($input['coingecko_api_key']) && '' !== trim((string) $input['coingecko_api_key'])) {
            $new['coingecko_api_key'] = substr(sanitize_text_field(wp_unslash($input['coingecko_api_key'])), 0, 255);
        }
        if (!empty($input['clear_coingecko_api_key'])) {
            $new['coingecko_api_key'] = '';
        }

        $fixed_rate = isset($input['fixed_rate']) ? (float) $input['fixed_rate'] : 0;
        if (!is_finite($fixed_rate) || $fixed_rate < self::MIN_FIXED_RATE || $fixed_rate > self::MAX_FIXED_RATE) {
            return new WP_Error(
                'invalid_rate',
                __('全局人民币备用汇率必须在 1 至 30 之间。', 'jiuliu-crypto-payment')
            );
        }
        $new['fixed_rate'] = number_format($fixed_rate, 8, '.', '');

        $markup = isset($input['rate_markup']) ? (float) $input['rate_markup'] : 0;
        if (!is_finite($markup)) {
            return new WP_Error('invalid_markup', __('汇率加成必须是有效数字。', 'jiuliu-crypto-payment'));
        }
        $new['rate_markup'] = number_format(max(-50, min(100, $markup)), 4, '.', '');

        $deviation = isset($input['auto_rate_max_deviation']) ? (float) $input['auto_rate_max_deviation'] : 10;
        if (!is_finite($deviation)) {
            return new WP_Error('invalid_deviation', __('自动汇率偏差阈值必须是有效数字。', 'jiuliu-crypto-payment'));
        }
        $new['auto_rate_max_deviation'] = number_format(max(1, min(30, $deviation)), 2, '.', '');

        $new['invoice_timeout'] = max(5, min(180, isset($input['invoice_timeout']) ? absint($input['invoice_timeout']) : 15));
        $new['late_grace_hours'] = max(1, min(168, isset($input['late_grace_hours']) ? absint($input['late_grace_hours']) : 24));
        $new['log_retention_days'] = max(7, min(365, isset($input['log_retention_days']) ? absint($input['log_retention_days']) : 90));

        $minimum = isset($input['minimum_local_amount']) ? (float) $input['minimum_local_amount'] : 1;
        $maximum = isset($input['maximum_local_amount']) ? (float) $input['maximum_local_amount'] : 100000;
        if (!is_finite($minimum) || !is_finite($maximum) || $minimum < 0.01 || $maximum < $minimum || $maximum > 1000000000) {
            return new WP_Error('invalid_limits', __('订单金额范围无效。', 'jiuliu-crypto-payment'));
        }
        $new['minimum_local_amount'] = number_format($minimum, 8, '.', '');
        $new['maximum_local_amount'] = number_format($maximum, 8, '.', '');

        $cron_token = isset($input['cron_token']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', wp_unslash($input['cron_token'])) : '';
        if ($cron_token && strlen($cron_token) < 32) {
            return new WP_Error('invalid_cron_token', __('外部 Cron 密钥至少需要 32 个字符。', 'jiuliu-crypto-payment'));
        }
        if ($cron_token) {
            $new['cron_token'] = substr($cron_token, 0, 128);
        } elseif (empty($new['cron_token'])) {
            $new['cron_token'] = JIULIU_CRYPTO_Util::random_token(32);
        }

        $allowlist = isset($input['cron_ip_allowlist']) ? trim((string) wp_unslash($input['cron_ip_allowlist'])) : '';
        $ips = array();
        foreach ((array) preg_split('/[\s,]+/', $allowlist) as $ip) {
            if (!$ip) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                return new WP_Error('invalid_cron_ip_allowlist', sprintf(__('Cron IP 白名单包含无效地址：%s。', 'jiuliu-crypto-payment'), $ip));
            }
            $ips[] = $ip;
        }
        $new['cron_ip_allowlist'] = implode("\n", array_unique(array_slice($ips, 0, 100)));

        update_option(self::OPTION_NAME, $new, false);
        $this->cache = null;
        foreach (array('usdt', 'usdc', 'fdusd', 'eurc', 'pyusd') as $asset_id) {
            delete_transient('jiuliu_crypto_auto_rate_' . $asset_id);
        }
        return $new;
    }

    public function rotate_cron_token()
    {
        if (defined('JIULIU_CRYPTO_CRON_TOKEN')) {
            return new WP_Error('cron_token_managed_by_constant', __('Cron 密钥由 wp-config.php 常量管理，请在服务器配置中更换。', 'jiuliu-crypto-payment'));
        }
        $settings = $this->all();
        $settings['cron_token'] = JIULIU_CRYPTO_Util::random_token(32);
        update_option(self::OPTION_NAME, $settings, false);
        $this->cache = null;
        return $settings['cron_token'];
    }

    public function masked_api_key($key)
    {
        $value = (string) $this->get($key);
        if (!$value) {
            return '';
        }
        return strlen($value) <= 8 ? str_repeat('•', strlen($value)) : substr($value, 0, 4) . '••••••••' . substr($value, -4);
    }
}
