<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_CRYPTO_Rate
{
    const MIN_FIXED_RATE = 1;
    const MAX_FIXED_RATE = 30;

    private $settings;

    public function __construct(JIULIU_CRYPTO_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get_rate($force = false, $asset_symbol = 'USDT', $route = array())
    {
        $asset = $this->resolve_asset($asset_symbol, $route);
        if (is_wp_error($asset)) {
            return array(
                'rate'       => 0,
                'source'     => $asset->get_error_code(),
                'fetched_at' => current_time('mysql'),
                'fallback'   => true,
                'error'      => $asset->get_error_message(),
            );
        }
        $asset_symbol = $asset['symbol'];
        $fixed = isset($route['rate_cny']) && '' !== (string) $route['rate_cny']
            ? (float) $route['rate_cny']
            : (float) $this->settings->get('fixed_rate', 7.2);
        if (!is_finite($fixed) || $fixed < $asset['minimum_rate_cny'] || $fixed > $asset['maximum_rate_cny']) {
            return array(
                'rate'       => 0,
                'source'     => 'invalid_fixed_rate',
                'fetched_at' => current_time('mysql'),
                'fallback'   => true,
                'error'      => sprintf(
                    __('备用固定汇率超出该币种的安全范围（%1$s 至 %2$s CNY/%3$s）。', 'jiuliu-crypto-payment'),
                    $asset['minimum_rate_cny'],
                    $asset['maximum_rate_cny'],
                    $asset_symbol
                ),
            );
        }

        $rate_mode = isset($route['rate_mode']) ? (string) $route['rate_mode'] : (string) $this->settings->get('rate_mode');
        if ('auto' !== $rate_mode) {
            return array(
                'rate'             => $fixed,
                'source'           => 'fixed',
                'fetched_at'       => current_time('mysql'),
                'fallback'         => false,
                'asset_symbol'     => $asset_symbol,
                'asset_id'         => $asset['asset_id'],
                'rate_provider_id' => $asset['rate_provider_id'],
            );
        }

        if (!$force) {
            $cached_fallback = get_transient($this->fallback_cache_key($asset, $fixed, $route));
            if (is_array($cached_fallback) && !empty($cached_fallback['fallback'])) {
                return $cached_fallback;
            }
        }

        $result = null;
        if (!$force) {
            $cached = get_transient($this->market_cache_key($asset));
            if (is_array($cached) && !empty($cached['rate']) && 'coingecko' === (isset($cached['source']) ? $cached['source'] : '')) {
                $cached_updated_at = !empty($cached['last_updated_at']) ? absint($cached['last_updated_at']) : 0;
                if (
                    $cached_updated_at
                    && $cached_updated_at >= (time() - 10 * MINUTE_IN_SECONDS)
                    && $cached_updated_at <= (time() + 2 * MINUTE_IN_SECONDS)
                ) {
                    $result = $cached;
                } else {
                    delete_transient($this->market_cache_key($asset));
                }
            }
        }

        if (null === $result) {
            $result = $this->fetch_coingecko($asset);
            if (is_wp_error($result)) {
                return $this->fixed_fallback($fixed, $result->get_error_message(), $asset, $route);
            }
            $fresh_for = max(
                1,
                min(10 * MINUTE_IN_SECONDS, ($result['last_updated_at'] + 10 * MINUTE_IN_SECONDS) - time())
            );
            // Cache only the upstream market observation. Every route still
            // applies its own filters, trusted anchor and deviation guard.
            set_transient($this->market_cache_key($asset), $result, $fresh_for);
        }

        $result['asset_symbol'] = $asset_symbol;
        $result['asset_id'] = $asset['asset_id'];
        $result['rate_provider_id'] = $asset['rate_provider_id'];
        $result['rate'] = (float) apply_filters('jiuliu_crypto_exchange_rate', $result['rate'], $result, $asset_symbol, $route);
        if ($result['rate'] <= 0 || !is_finite($result['rate'])) {
            return $this->fixed_fallback($fixed, __('自动汇率过滤结果无效。', 'jiuliu-crypto-payment'), $asset, $route);
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
                $asset,
                $route
            );
        }
        return $result;
    }

    private function fixed_fallback($fixed, $error, $asset, $route = array())
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
        $result['asset_symbol'] = $asset['symbol'];
        $result['asset_id'] = $asset['asset_id'];
        $result['rate_provider_id'] = $asset['rate_provider_id'];
        set_transient($this->fallback_cache_key($asset, $fixed, $route), $result, 2 * MINUTE_IN_SECONDS);
        return $result;
    }

    private function fallback_cache_key($asset, $fixed, $route)
    {
        $route_id = !empty($route['id']) ? sanitize_key($route['id']) : 'default';
        return 'jiuliu_crypto_rate_fallback_' . sanitize_key($asset['asset_id']) . '_'
            . substr(hash('sha256', $route_id . '|' . $asset['rate_provider_id'] . '|' . number_format((float) $fixed, 8, '.', '')), 0, 16);
    }

    private function market_cache_key($asset)
    {
        return 'jiuliu_crypto_auto_rate_' . sanitize_key($asset['asset_id']);
    }

    private function fetch_coingecko($asset)
    {
        $coin_id = $asset['rate_provider_id'];
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
            'rate_provider_id' => $coin_id,
            'fetched_at'      => current_time('mysql'),
            'last_updated_at' => $last_updated_at,
            'fallback'        => false,
        );
    }

    /**
     * Explicit pricing allowlist. Symbols are display data; asset_id and
     * rate_provider_id are the immutable server-side identity pair.
     */
    public static function asset_catalog()
    {
        return array(
            'USDT' => array(
                'symbol'           => 'USDT',
                'asset_id'         => 'usdt',
                'rate_provider_id' => 'tether',
                'minimum_rate_cny' => 1,
                'maximum_rate_cny' => 20,
            ),
            'USDC' => array(
                'symbol'           => 'USDC',
                'asset_id'         => 'usdc',
                'rate_provider_id' => 'usd-coin',
                'minimum_rate_cny' => 1,
                'maximum_rate_cny' => 20,
            ),
            'FDUSD' => array(
                'symbol'           => 'FDUSD',
                'asset_id'         => 'fdusd',
                'rate_provider_id' => 'first-digital-usd',
                'minimum_rate_cny' => 1,
                'maximum_rate_cny' => 20,
            ),
            'EURC' => array(
                'symbol'           => 'EURC',
                'asset_id'         => 'eurc',
                'rate_provider_id' => 'euro-coin',
                'minimum_rate_cny' => 1,
                'maximum_rate_cny' => 30,
            ),
            'PYUSD' => array(
                'symbol'           => 'PYUSD',
                'asset_id'         => 'pyusd',
                'rate_provider_id' => 'paypal-usd',
                'minimum_rate_cny' => 1,
                'maximum_rate_cny' => 20,
            ),
        );
    }

    private function resolve_asset($asset_symbol, $route)
    {
        $symbol = strtoupper(trim((string) $asset_symbol));
        $catalog = self::asset_catalog();
        if (!isset($catalog[$symbol])) {
            return new WP_Error(
                'unsupported_asset',
                __('该币种不在报价白名单中。', 'jiuliu-crypto-payment')
            );
        }

        if (!is_array($route) || empty($route)) {
            return new WP_Error(
                'untrusted_asset_identity',
                __('缺少已核验支付路线，不能只根据币种符号获取报价。', 'jiuliu-crypto-payment')
            );
        }

        $asset = $catalog[$symbol];
        $route_symbol = isset($route['asset_symbol']) ? strtoupper(trim((string) $route['asset_symbol'])) : '';
        $route_asset_id = isset($route['asset_id']) ? strtolower(trim((string) $route['asset_id'])) : '';
        $route_provider_id = isset($route['rate_provider_id']) ? strtolower(trim((string) $route['rate_provider_id'])) : '';
        if (
            $route_symbol !== $asset['symbol']
            || $route_asset_id !== $asset['asset_id']
            || $route_provider_id !== $asset['rate_provider_id']
        ) {
            return new WP_Error(
                'asset_identity_mismatch',
                __('支付路线的币种身份与报价白名单不一致。', 'jiuliu-crypto-payment')
            );
        }

        return $asset;
    }
}
