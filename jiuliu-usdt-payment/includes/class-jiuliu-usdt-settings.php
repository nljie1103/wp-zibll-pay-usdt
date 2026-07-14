<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Settings
{
    const OPTION_NAME = 'jiuliu_usdt_settings';
    const USDT_CONTRACT = 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t';

    private $cache;

    public function defaults()
    {
        return array(
            'enabled'                   => 0,
            'pause_monitoring'          => 0,
            'receive_address'           => '',
            'trongrid_api_key'          => '',
            'trongrid_max_pages'        => 10,
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
            'cron_token'                => JIULIU_USDT_Util::random_token(32),
            'cron_ip_allowlist'         => '',
        );
    }

    public function install_defaults()
    {
        $existing = get_option(self::OPTION_NAME, array());
        if (!is_array($existing)) {
            $existing = array();
        }

        update_option(self::OPTION_NAME, wp_parse_args($existing, $this->defaults()), false);
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

        if ('receive_address' === $key && defined('JIULIU_USDT_RECEIVE_ADDRESS')) {
            return trim((string) JIULIU_USDT_RECEIVE_ADDRESS);
        }

        if ('trongrid_api_key' === $key && defined('JIULIU_USDT_TRONGRID_API_KEY')) {
            return trim((string) JIULIU_USDT_TRONGRID_API_KEY);
        }

        if ('coingecko_api_key' === $key && defined('JIULIU_USDT_COINGECKO_API_KEY')) {
            return trim((string) JIULIU_USDT_COINGECKO_API_KEY);
        }

        if ('cron_token' === $key && defined('JIULIU_USDT_CRON_TOKEN')) {
            return trim((string) JIULIU_USDT_CRON_TOKEN);
        }

        return array_key_exists($key, $settings) ? $settings[$key] : $default;
    }

    public function is_enabled()
    {
        return PHP_INT_SIZE >= 8
            && (bool) $this->get('enabled')
            && !(bool) $this->get('pause_monitoring')
            && JIULIU_USDT_Util::is_valid_tron_address($this->get('receive_address'));
    }

    public function update($input)
    {
        $old = $this->all();
        $new = $old;

        $new['enabled']                   = empty($input['enabled']) ? 0 : 1;
        $new['pause_monitoring']          = empty($input['pause_monitoring']) ? 0 : 1;
        $new['rate_mode']                 = (!empty($input['rate_mode']) && 'auto' === $input['rate_mode']) ? 'auto' : 'fixed';
        $new['frontend_manual_txid']      = empty($input['frontend_manual_txid']) ? 0 : 1;
        $new['monitor_closed_orders']     = empty($input['monitor_closed_orders']) ? 0 : 1;
        $new['admin_email_notifications'] = empty($input['admin_email_notifications']) ? 0 : 1;
        $new['user_email_notifications']  = empty($input['user_email_notifications']) ? 0 : 1;

        $address = isset($input['receive_address']) ? trim(sanitize_text_field(wp_unslash($input['receive_address']))) : '';
        if ($address && !JIULIU_USDT_Util::is_valid_tron_address($address)) {
            return new WP_Error('invalid_address', __('TRON 收款地址未通过 Base58Check 校验，请检查后重试。', 'jiuliu-usdt-payment'));
        }
        $new['receive_address'] = $address;

        if (isset($input['trongrid_api_key']) && '' !== trim((string) $input['trongrid_api_key'])) {
            $new['trongrid_api_key'] = substr(sanitize_text_field(wp_unslash($input['trongrid_api_key'])), 0, 255);
        }

        if (!empty($input['clear_trongrid_api_key'])) {
            $new['trongrid_api_key'] = '';
        }

        if (isset($input['coingecko_api_key']) && '' !== trim((string) $input['coingecko_api_key'])) {
            $new['coingecko_api_key'] = substr(sanitize_text_field(wp_unslash($input['coingecko_api_key'])), 0, 255);
        }

        if (!empty($input['clear_coingecko_api_key'])) {
            $new['coingecko_api_key'] = '';
        }

        $fixed_rate = isset($input['fixed_rate']) ? (float) $input['fixed_rate'] : 0;
        if (!is_finite($fixed_rate) || $fixed_rate < 1 || $fixed_rate > 20) {
            return new WP_Error('invalid_rate', __('人民币备用固定汇率必须在 1 至 20 CNY/USDT 之间。', 'jiuliu-usdt-payment'));
        }
        $new['fixed_rate'] = number_format($fixed_rate, 8, '.', '');

        $markup = isset($input['rate_markup']) ? (float) $input['rate_markup'] : 0;
        if (!is_finite($markup)) {
            return new WP_Error('invalid_markup', __('汇率加成必须是有效数字。', 'jiuliu-usdt-payment'));
        }
        $markup = max(-50, min(100, $markup));
        $new['rate_markup'] = number_format($markup, 4, '.', '');

        $max_deviation = isset($input['auto_rate_max_deviation']) ? (float) $input['auto_rate_max_deviation'] : 10;
        if (!is_finite($max_deviation)) {
            return new WP_Error('invalid_deviation', __('自动汇率偏差阈值必须是有效数字。', 'jiuliu-usdt-payment'));
        }
        $max_deviation = max(1, min(30, $max_deviation));
        $new['auto_rate_max_deviation'] = number_format($max_deviation, 2, '.', '');

        $new['invoice_timeout']    = max(5, min(180, isset($input['invoice_timeout']) ? absint($input['invoice_timeout']) : 15));
        $new['late_grace_hours']   = max(1, min(168, isset($input['late_grace_hours']) ? absint($input['late_grace_hours']) : 24));
        $new['log_retention_days'] = max(7, min(365, isset($input['log_retention_days']) ? absint($input['log_retention_days']) : 90));
        $new['trongrid_max_pages'] = max(1, min(10, isset($input['trongrid_max_pages']) ? absint($input['trongrid_max_pages']) : 10));

        $minimum = isset($input['minimum_local_amount']) ? (float) $input['minimum_local_amount'] : 1;
        $maximum = isset($input['maximum_local_amount']) ? (float) $input['maximum_local_amount'] : 100000;
        if (!is_finite($minimum) || !is_finite($maximum) || $minimum < 0.01 || $maximum < $minimum || $maximum > 1000000000) {
            return new WP_Error('invalid_limits', __('金额范围无效：最高金额必须大于或等于最低金额。', 'jiuliu-usdt-payment'));
        }
        $new['minimum_local_amount'] = number_format($minimum, 8, '.', '');
        $new['maximum_local_amount'] = number_format($maximum, 8, '.', '');

        $cron_token = isset($input['cron_token']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', wp_unslash($input['cron_token'])) : '';
        if ($cron_token) {
            if (strlen($cron_token) < 32) {
                return new WP_Error('invalid_cron_token', __('外部 Cron 密钥至少需要 32 个字符。', 'jiuliu-usdt-payment'));
            }
            $new['cron_token'] = substr($cron_token, 0, 128);
        } elseif (empty($new['cron_token'])) {
            $new['cron_token'] = JIULIU_USDT_Util::random_token(32);
        }

        $allowlist = isset($input['cron_ip_allowlist']) ? trim((string) wp_unslash($input['cron_ip_allowlist'])) : '';
        if (strlen($allowlist) > 8192) {
            return new WP_Error('invalid_cron_ip_allowlist', __('Cron IP 白名单内容过长。', 'jiuliu-usdt-payment'));
        }
        $ips = array();
        $invalid_ips = array();
        foreach ((array) preg_split('/[\s,]+/', $allowlist) as $ip) {
            if (!$ip) {
                continue;
            }
            if (!filter_var($ip, FILTER_VALIDATE_IP)) {
                $invalid_ips[] = $ip;
                continue;
            }
            $ips[] = $ip;
        }
        if ($invalid_ips) {
            return new WP_Error(
                'invalid_cron_ip_allowlist',
                sprintf(
                    __('Cron IP 白名单包含无效地址（不支持 CIDR）：%s。设置未保存，原白名单保持不变。', 'jiuliu-usdt-payment'),
                    implode(', ', array_slice($invalid_ips, 0, 5))
                )
            );
        }
        if (count($ips) > 100) {
            return new WP_Error('invalid_cron_ip_allowlist', __('Cron IP 白名单最多允许 100 个地址。', 'jiuliu-usdt-payment'));
        }
        $new['cron_ip_allowlist'] = implode("\n", array_unique($ips));

        update_option(self::OPTION_NAME, $new, false);
        $this->cache = null;
        delete_transient('jiuliu_usdt_auto_rate');

        return $new;
    }

    /**
     * Rotate the stored external-Cron credential. A wp-config.php constant is
     * intentionally immutable from WordPress so an administrator cannot get a
     * false success message while the effective credential remains unchanged.
     */
    public function rotate_cron_token()
    {
        if (defined('JIULIU_USDT_CRON_TOKEN')) {
            return new WP_Error(
                'cron_token_managed_by_constant',
                __('Cron 密钥由 wp-config.php 中的 JIULIU_USDT_CRON_TOKEN 管理，请在服务器配置中更换。', 'jiuliu-usdt-payment')
            );
        }

        $settings = $this->all();
        $settings['cron_token'] = JIULIU_USDT_Util::random_token(32);
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

        $length = strlen($value);
        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return substr($value, 0, 4) . str_repeat('•', min(20, $length - 8)) . substr($value, -4);
    }
}
