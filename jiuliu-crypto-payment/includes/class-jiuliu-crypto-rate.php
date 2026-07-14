<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_CRYPTO_Rate
{
    private $settings;

    public function __construct(JIULIU_CRYPTO_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get_rate($force = false, $asset_symbol = 'USDT', $route = array())
    {
        $asset_symbol = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $asset_symbol));
        if (!in_array($asset_symbol, array('USDT', 'USDC'), true)) {
            return array(
                'rate'       => 0,
                'source'     => 'unsupported_asset',
                'fetched_at' => current_time('mysql'),
                'fallback'   => true,
                'error'      => __('自动报价目前只支持 USDT 与 USDC。', 'jiuliu-crypto-payment'),
            );
        }
        $fixed = isset($route['rate_cny']) && '' !== (string) $route['rate_cny']
            ? (float) $route['rate_cny']
            : (float) $this->settings->get('fixed_rate', 7.2);
        if (!is_finite($fixed) || $fixed < 1 || $fixed > 20) {
            // The automatic-rate deviation guard is only meaningful when its
            // trusted CNY anchor is plausible. Fail closed instead of letting a
            // typo such as 72 CNY/USDT turn into a tenfold undercharge.
            return array(
                'rate'       => 0,
                'source'     => 'invalid_fixed_rate',
                'fetched_at' => current_time('mysql'),
                'fallback'   => true,
                'error'      => sprintf(__('备用固定汇率超出安全范围（1 至 20 CNY/%s）。', 'jiuliu-crypto-payment'), $asset_symbol),
            );
        }

        $rate_mode = isset($route['rate_mode']) ? (string) $route['rate_mode'] : (string) $this->settings->get('rate_mode');
        if ('auto' !== $rate_mode) {
            return array(
                'rate'       => $fixed,
                'source'     => 'fixed',
                'fetched_at' => current_time('mysql'),
                'fallback'   => false,
            );
        }

        if (!$force) {
            $cached_fallback = get_transient($this->fallback_cache_key($asset_symbol, $fixed, $route));
            if (is_array($cached_fallback) && !empty($cached_fallback['fallback'])) {
                return $cached_fallback;
            }
        }

        $result = null;
        if (!$force) {
            $cached = get_transient('jiuliu_crypto_auto_rate_' . strtolower($asset_symbol));
            if (is_array($cached) && !empty($cached['rate']) && 'coingecko' === (isset($cached['source']) ? $cached['source'] : '')) {
                $cached_updated_at = !empty($cached['last_updated_at']) ? absint($cached['last_updated_at']) : 0;
                if (
                    $cached_updated_at
                    && $cached_updated_at >= (time() - 10 * MINUTE_IN_SECONDS)
                    && $cached_updated_at <= (time() + 2 * MINUTE_IN_SECONDS)
                ) {
                    $result = $cached;
                } else {
                    delete_transient('jiuliu_crypto_auto_rate_' . strtolower($asset_symbol));
                }
            }
        }

        if (null === $result) {
            $result = $this->fetch_coingecko($asset_symbol);
            if (is_wp_error($result)) {
                return $this->fixed_fallback($fixed, $result->get_error_message(), $asset_symbol, $route);
            }
            $fresh_for = max(
                1,
                min(10 * MINUTE_IN_SECONDS, ($result['last_updated_at'] + 10 * MINUTE_IN_SECONDS) - time())
            );
            // Cache only the upstream market observation. Every route still
            // applies its own filters, trusted anchor and deviation guard.
            set_transient('jiuliu_crypto_auto_rate_' . strtolower($asset_symbol), $result, $fresh_for);
        }

        $result['asset_symbol'] = $asset_symbol;
        $result['rate'] = (float) apply_filters('jiuliu_crypto_exchange_rate', $result['rate'], $result, $asset_symbol, $route);
        if ($result['rate'] <= 0 || !is_finite($result['rate'])) {
            return $this->fixed_fallback($fixed, __('自动汇率过滤结果无效。', 'jiuliu-crypto-payment'), $asset_symbol, $route);
        }

        $max_deviation = (float) $this->settings->get('auto_rate_max_deviation', 10);
        $deviation = $fixed > 0 ? abs($result['rate'] - $fixed) / $fixed * 100 : 100;
        if ($deviation > $max_deviation) {
            return $this->fixed_fallback(
                $fixed,
                sprintf(
                    __('自动汇率偏离备用固定汇率 %1$.2f%%，超过 %2$.2f%% 安全阈值。', 'jiuliu-crypto-payment'),
                    $deviation,
                    $max_deviation
                ),
                $asset_symbol,
                $route
            );
        }
        return $result;
    }

    private function fixed_fallback($fixed, $error, $asset_symbol = 'USDT', $route = array())
    {
        $result = array(
            'rate'       => (float) $fixed,
            'source'     => 'fixed_fallback',
            'fetched_at' => current_time('mysql'),
            'fallback'   => true,
            'error'      => sanitize_text_field($error),
        );

        // During an upstream outage, an uncached fallback makes every checkout
        // repeat the same 12-second request. A short cache contains the failure
        // without keeping stale market data for long.
        $result['asset_symbol'] = $asset_symbol;
        set_transient($this->fallback_cache_key($asset_symbol, $fixed, $route), $result, 2 * MINUTE_IN_SECONDS);
        return $result;
    }

    private function fallback_cache_key($asset_symbol, $fixed, $route)
    {
        $route_id = !empty($route['id']) ? sanitize_key($route['id']) : 'default';
        return 'jiuliu_crypto_rate_fallback_' . strtolower($asset_symbol) . '_'
            . substr(hash('sha256', $route_id . '|' . number_format((float) $fixed, 8, '.', '')), 0, 16);
    }

    private function fetch_coingecko($asset_symbol = 'USDT')
    {
        $coin_id = 'USDC' === strtoupper((string) $asset_symbol) ? 'usd-coin' : 'tether';
        $url = 'https://api.coingecko.com/api/v3/simple/price?ids=' . rawurlencode($coin_id) . '&vs_currencies=cny&include_last_updated_at=true';
        $headers = array(
            'Accept'     => 'application/json',
            'User-Agent' => 'Jiuliu-Crypto-Payment/' . JIULIU_CRYPTO_VERSION . '; ' . home_url('/'),
        );

        $api_key = trim((string) $this->settings->get('coingecko_api_key'));
        if ($api_key) {
            $headers['x-cg-demo-api-key'] = $api_key;
        }

        $response = wp_remote_get($url, array(
            'timeout'     => 12,
            // Do not forward an optional CoinGecko key across redirects.
            'redirection' => 0,
            'headers'     => $headers,
        ));

        if (is_wp_error($response)) {
            return new WP_Error('rate_network_error', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (200 !== $code) {
            return new WP_Error(
                'rate_http_error',
                sprintf(__('自动汇率接口返回 HTTP %d。', 'jiuliu-crypto-payment'), $code)
            );
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $rate = isset($body[$coin_id]['cny']) ? (float) $body[$coin_id]['cny'] : 0;
        $last_updated_at = isset($body[$coin_id]['last_updated_at']) ? absint($body[$coin_id]['last_updated_at']) : 0;
        if ($rate <= 0 || $rate > 100 || !is_finite($rate)) {
            return new WP_Error('rate_invalid_response', __('自动汇率接口返回了无效数据。', 'jiuliu-crypto-payment'));
        }
        if (
            !$last_updated_at
            || $last_updated_at < (time() - 10 * MINUTE_IN_SECONDS)
            || $last_updated_at > (time() + 2 * MINUTE_IN_SECONDS)
        ) {
            return new WP_Error('rate_stale_response', __('自动汇率接口返回的数据时间已过期。', 'jiuliu-crypto-payment'));
        }

        return array(
            'rate'            => $rate,
            'source'          => 'coingecko',
            'fetched_at'      => current_time('mysql'),
            'last_updated_at' => $last_updated_at,
            'fallback'        => false,
        );
    }
}
