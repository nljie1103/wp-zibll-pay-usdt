<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Normalizes the administrator-owned multi-chain payment-route allowlist.
 *
 * Routes are self-contained. No receiver address, credential or RPC endpoint
 * is supplied by a preset.
 */
class JIULIU_CRYPTO_Routes
{
    const METHOD_PREFIX = 'ju_crypto_';
    const MAX_ROUTES = 32;

    private $routes = array();
    private $error;

    public function __construct($routes = array())
    {
        $normalized = self::normalize($routes);
        if (is_wp_error($normalized)) {
            $this->error = $normalized;
            return;
        }

        foreach ($normalized as $route) {
            $this->routes[$route['id']] = $route;
        }
    }

    /** @return array<string,array<string,mixed>> */
    public function all($enabled_only = false)
    {
        if (!$enabled_only) {
            return $this->routes;
        }

        return array_filter($this->routes, array(__CLASS__, 'is_enabled_route'));
    }

    public function enabled()
    {
        return $this->all(true);
    }

    public function get_error()
    {
        return $this->error;
    }

    public function get_by_id($id, $enabled_only = false)
    {
        $id = (string) $id;
        if (!isset($this->routes[$id])) {
            return null;
        }

        $route = $this->routes[$id];
        return $enabled_only && empty($route['enabled']) ? null : $route;
    }

    public function get_by_method($method, $enabled_only = false)
    {
        $method = (string) $method;
        if (0 !== strpos($method, self::METHOD_PREFIX)) {
            return null;
        }

        return $this->get_by_id(substr($method, strlen(self::METHOD_PREFIX)), $enabled_only);
    }

    public function method_for_route($route_or_id)
    {
        $id = is_array($route_or_id) && isset($route_or_id['id'])
            ? (string) $route_or_id['id']
            : (string) $route_or_id;

        return self::valid_id($id) ? self::METHOD_PREFIX . $id : '';
    }

    public static function is_enabled_route($route)
    {
        return is_array($route) && !empty($route['enabled']);
    }

    /**
     * Return conservative contract presets verified against issuer listings.
     * All presets are disabled and intentionally omit the receiver and RPC.
     */
    public static function presets()
    {
        return array(
            'usdt_trc20' => self::preset(
                'usdt_trc20', 'USDT', 'Tether USD', 6, 'tron',
                'TRON (TRC20)', 'tron', 'tron-mainnet',
                'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'TRX', 1
            ),
            'usdt_ethereum' => self::preset(
                'usdt_ethereum', 'USDT', 'Tether USD', 6, 'evm',
                'Ethereum (ERC20)', 'ethereum', '1',
                '0xdac17f958d2ee523a2206206994597c13d831ec7', 'ETH', 12
            ),
            'usdc_ethereum' => self::preset(
                'usdc_ethereum', 'USDC', 'USD Coin', 6, 'evm',
                'Ethereum (ERC20)', 'ethereum', '1',
                '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 'ETH', 12
            ),
            'usdc_base' => self::preset(
                'usdc_base', 'USDC', 'USD Coin', 6, 'evm',
                'Base (ERC20)', 'base', '8453',
                '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'ETH', 20
            ),
            'usdc_arbitrum' => self::preset(
                'usdc_arbitrum', 'USDC', 'USD Coin', 6, 'evm',
                'Arbitrum One (ERC20)', 'arbitrum', '42161',
                '0xaf88d065e77c8cc2239327c5edb3a432268e5831', 'ETH', 20
            ),
            'usdc_polygon' => self::preset(
                'usdc_polygon', 'USDC', 'USD Coin', 6, 'evm',
                'Polygon PoS (ERC20)', 'polygon', '137',
                '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 'POL', 64
            ),
            'usdc_avalanche' => self::preset(
                'usdc_avalanche', 'USDC', 'USD Coin', 6, 'evm',
                'Avalanche C-Chain (ERC20)', 'avalanche', '43114',
                '0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e', 'AVAX', 12
            ),
        );
    }

    public static function from_preset($id, $overrides = array())
    {
        $presets = self::presets();
        if (!isset($presets[$id])) {
            return new WP_Error('unknown_route_preset', __('未知的支付路线预设。', 'jiuliu-crypto-payment'));
        }

        $route = array_merge($presets[$id], is_array($overrides) ? $overrides : array());
        $normalized = self::normalize(array($route));
        return is_wp_error($normalized) ? $normalized : reset($normalized);
    }

    /**
     * Accept a JSON list/object or PHP array and return a canonical route list.
     * An invalid route rejects the whole allowlist so a typo can never silently
     * redirect or weaken payment verification.
     */
    public static function normalize($input)
    {
        if (is_string($input)) {
            if (strlen($input) > 262144) {
                return self::error('routes_too_large', '支付路线配置过大。');
            }
            $input = json_decode($input, true);
            if (JSON_ERROR_NONE !== json_last_error()) {
                return self::error('invalid_routes_json', '支付路线 JSON 无法解析。');
            }
        }

        if (null === $input || '' === $input) {
            $input = array();
        }
        if (!is_array($input)) {
            return self::error('invalid_routes', '支付路线必须是 JSON 或数组。');
        }
        if (count($input) > self::MAX_ROUTES) {
            return self::error('too_many_routes', '支付路线最多允许 32 条。');
        }

        $normalized = array();
        $ids = array();
        $chain_ids = array();
        $chain_keys = array();
        foreach ($input as $fallback_id => $route) {
            if (!is_array($route)) {
                return self::error('invalid_route', '每条支付路线都必须是对象。');
            }
            if (!isset($route['id']) && !is_int($fallback_id)) {
                $route['id'] = $fallback_id;
            }

            $item = self::normalize_route($route);
            if (is_wp_error($item)) {
                return $item;
            }
            if (isset($ids[$item['id']])) {
                return self::error('duplicate_route_id', '支付路线 ID 不得重复。');
            }

            $chain_identity = $item['adapter'] . ':' . $item['chain_id'];
            if (isset($chain_ids[$chain_identity]) && $chain_ids[$chain_identity] !== $item['chain_key']) {
                return self::error('chain_identity_conflict', '同一链 ID 必须使用相同 chain_key。');
            }
            if (isset($chain_keys[$item['chain_key']]) && $chain_keys[$item['chain_key']] !== $chain_identity) {
                return self::error('chain_key_conflict', '同一 chain_key 必须对应同一链 ID。');
            }

            $ids[$item['id']] = true;
            $chain_ids[$chain_identity] = $item['chain_key'];
            $chain_keys[$item['chain_key']] = $chain_identity;
            $normalized[] = $item;
        }

        return $normalized;
    }

    public static function encode($input)
    {
        $routes = self::normalize($input);
        if (is_wp_error($routes)) {
            return $routes;
        }

        return wp_json_encode($routes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function normalize_route($route)
    {
        $id = isset($route['id']) ? trim((string) $route['id']) : '';
        if (!self::valid_id($id)) {
            return self::error('invalid_route_id', '路线 ID 只能包含小写字母、数字、下划线或短横线，长度为 3 至 40。');
        }

        $adapter = isset($route['adapter']) ? strtolower(trim((string) $route['adapter'])) : '';
        if (!in_array($adapter, array('tron', 'evm'), true)) {
            return self::error('invalid_route_adapter', '路线 adapter 只能是 tron 或 evm。');
        }

        $symbol = isset($route['asset_symbol']) ? strtoupper(trim((string) $route['asset_symbol'])) : '';
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{1,15}$/', $symbol)) {
            return self::error('invalid_asset_symbol', '币种符号格式无效。');
        }

        $name = self::clean_text(isset($route['asset_name']) ? $route['asset_name'] : $symbol, 64);
        // The immutable invoice schema stores network labels in varchar(64).
        $network = self::clean_text(isset($route['network_label']) ? $route['network_label'] : '', 64);
        if ('' === $name || '' === $network) {
            return self::error('invalid_route_label', '币种名称和网络名称不能为空。');
        }

        $decimals = isset($route['asset_decimals']) ? $route['asset_decimals'] : (isset($route['decimals']) ? $route['decimals'] : 6);
        if (!self::integer_in_range($decimals, 0, 18)) {
            return self::error('invalid_asset_decimals', '代币精度必须是 0 至 18 的整数。');
        }
        $decimals = (int) $decimals;

        $chain_key = isset($route['chain_key']) ? strtolower(trim((string) $route['chain_key'])) : '';
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/', $chain_key)) {
            return self::error('invalid_chain_key', 'chain_key 格式无效。');
        }
        $chain_id = isset($route['chain_id']) ? strtolower(trim((string) $route['chain_id'])) : '';
        if ('evm' === $adapter) {
            if (!preg_match('/^[1-9][0-9]{0,19}$/', $chain_id)) {
                return self::error('invalid_chain_id', 'EVM chain_id 必须是正十进制整数。');
            }
        } elseif ('tron-mainnet' !== $chain_id || 'tron' !== $chain_key) {
            return self::error('invalid_tron_chain', 'TRON 路线仅允许经 TronGrid 核验的 tron-mainnet 主网身份。');
        }

        $contract = isset($route['contract_address']) ? trim((string) $route['contract_address']) : (isset($route['contract']) ? trim((string) $route['contract']) : '');
        $receiver = isset($route['receive_address']) ? trim((string) $route['receive_address']) : (isset($route['receiver']) ? trim((string) $route['receiver']) : '');
        if ('evm' === $adapter) {
            if (!self::valid_evm_address($contract) || ('' !== $receiver && !self::valid_evm_address($receiver))) {
                return self::error('invalid_evm_address', 'EVM 合约或收款地址格式无效。');
            }
            $contract = strtolower($contract);
            $receiver = strtolower($receiver);
        } else {
            if (!self::valid_tron_address($contract) || ('' !== $receiver && !self::valid_tron_address($receiver))) {
                return self::error('invalid_tron_address', 'TRON 合约或收款地址未通过 Base58Check 校验。');
            }
        }

        $enabled = empty($route['enabled']) ? 0 : 1;
        $rpc_url = isset($route['rpc_url']) ? trim((string) $route['rpc_url']) : '';
        if ('' !== $rpc_url && !self::valid_rpc_url($rpc_url)) {
            return self::error('invalid_rpc_url', 'RPC URL 必须是无账号密码的 HTTPS 地址。');
        }
        if ($enabled && '' === $receiver) {
            return self::error('missing_receiver', '启用路线前必须填写收款地址。');
        }
        if ($enabled && 'evm' === $adapter && '' === $rpc_url) {
            return self::error('missing_rpc_url', '启用 EVM 路线前必须填写 RPC URL。');
        }

        $confirmations = isset($route['required_confirmations']) ? $route['required_confirmations'] : (isset($route['confirmations']) ? $route['confirmations'] : 1);
        if (!self::integer_in_range($confirmations, 1, 1000)) {
            return self::error('invalid_confirmations', '确认数必须是 1 至 1000 的整数。');
        }
        // TronGrid's walletsolidity/only_confirmed surface exposes solidified
        // mainnet data, not an arbitrary shallow confirmation depth. Keep the
        // route value fixed so the administrator UI cannot imply otherwise.
        if ('tron' === $adapter) {
            $confirmations = 1;
        }

        $fee_symbol = isset($route['fee_symbol']) ? strtoupper(trim((string) $route['fee_symbol'])) : '';
        if (!preg_match('/^[A-Z0-9][A-Z0-9._-]{0,15}$/', $fee_symbol)) {
            return self::error('invalid_fee_symbol', '手续费币种符号格式无效。');
        }

        $rate = isset($route['rate_cny']) ? (float) $route['rate_cny'] : 0;
        if (!is_finite($rate) || $rate < 0.00000001 || $rate > 1000000000) {
            return self::error('invalid_route_rate', 'CNY 汇率必须是有效正数。');
        }

        $headers = self::normalize_headers(isset($route['rpc_headers']) ? $route['rpc_headers'] : array());
        if (is_wp_error($headers)) {
            return $headers;
        }

        $chunk = isset($route['scan_block_chunk']) ? $route['scan_block_chunk'] : 500;
        $blocks = isset($route['scan_max_blocks']) ? $route['scan_max_blocks'] : 5000;
        $results = isset($route['scan_max_results']) ? $route['scan_max_results'] : 200;
        $timeout = isset($route['rpc_timeout']) ? $route['rpc_timeout'] : 15;
        if (!self::integer_in_range($chunk, 1, 5000) || !self::integer_in_range($blocks, 1, 1000000)
            || !self::integer_in_range($results, 1, 1000) || !self::integer_in_range($timeout, 3, 60)) {
            return self::error('invalid_scan_limits', 'RPC 扫描范围或超时参数无效。');
        }

        $api_key = isset($route['api_key']) ? trim((string) $route['api_key']) : '';
        if (strlen($api_key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $api_key)) {
            return self::error('invalid_api_key', 'API Key 格式无效。');
        }

        return array(
            'id' => $id,
            'method' => self::METHOD_PREFIX . $id,
            'enabled' => $enabled,
            'asset_symbol' => $symbol,
            'asset_name' => $name,
            'asset_decimals' => $decimals,
            'decimals' => $decimals,
            'adapter' => $adapter,
            'network_label' => $network,
            'network' => $network,
            'chain_key' => $chain_key,
            'chain_id' => $chain_id,
            'contract' => $contract,
            'contract_address' => $contract,
            'receiver' => $receiver,
            'receive_address' => $receiver,
            'rpc_url' => $rpc_url,
            'rpc_headers' => $headers,
            'api_key' => $api_key,
            'confirmations' => (int) $confirmations,
            'required_confirmations' => (int) $confirmations,
            'fee_symbol' => $fee_symbol,
            'rate_cny' => number_format($rate, 8, '.', ''),
            'scan_block_chunk' => (int) $chunk,
            'scan_max_blocks' => (int) $blocks,
            'scan_max_results' => (int) $results,
            'rpc_timeout' => (int) $timeout,
        );
    }

    private static function preset($id, $symbol, $name, $decimals, $adapter, $network, $chain_key, $chain_id, $contract, $fee_symbol, $confirmations)
    {
        return array(
            'id' => $id,
            'enabled' => 0,
            'asset_symbol' => $symbol,
            'asset_name' => $name,
            'asset_decimals' => $decimals,
            'adapter' => $adapter,
            'network_label' => $network,
            'chain_key' => $chain_key,
            'chain_id' => $chain_id,
            'contract_address' => $contract,
            'receive_address' => '',
            'rpc_url' => '',
            'rpc_headers' => array(),
            'api_key' => '',
            'required_confirmations' => $confirmations,
            'fee_symbol' => $fee_symbol,
            'rate_cny' => '7.20000000',
            'scan_block_chunk' => 500,
            'scan_max_blocks' => 5000,
            'scan_max_results' => 200,
            'rpc_timeout' => 15,
        );
    }

    private static function normalize_headers($headers)
    {
        if (!is_array($headers) || count($headers) > 20) {
            return self::error('invalid_rpc_headers', 'RPC 请求头格式无效。');
        }
        $normalized = array();
        foreach ($headers as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) $value);
            if (!preg_match('/^[A-Za-z0-9-]{1,64}$/', $name) || strlen($value) > 1024 || preg_match('/[\r\n\x00]/', $value)) {
                return self::error('invalid_rpc_headers', 'RPC 请求头包含无效名称或值。');
            }
            $normalized[$name] = $value;
        }
        return $normalized;
    }

    private static function valid_id($id)
    {
        // Zibll's pay_type column is varchar(50). Keep the prefixed method at
        // or below that hard storage limit so it can never be truncated.
        return is_string($id) && (bool) preg_match('/^[a-z0-9][a-z0-9_-]{2,39}$/', $id);
    }

    private static function valid_evm_address($address)
    {
        return (bool) preg_match('/^0x[a-fA-F0-9]{40}$/', (string) $address)
            && '0x0000000000000000000000000000000000000000' !== strtolower((string) $address);
    }

    private static function valid_tron_address($address)
    {
        return class_exists('JIULIU_CRYPTO_Util')
            && JIULIU_CRYPTO_Util::is_valid_tron_address((string) $address);
    }

    private static function valid_rpc_url($url)
    {
        if (strlen($url) > 2048 || false === filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && 'https' === strtolower($parts['scheme']) && empty($parts['user']) && empty($parts['pass']);
    }

    private static function integer_in_range($value, $min, $max)
    {
        return (is_int($value) || (is_string($value) && preg_match('/^[0-9]+$/', $value)))
            && (int) $value >= $min && (int) $value <= $max;
    }

    private static function clean_text($value, $max_length)
    {
        $value = trim(strip_tags((string) $value));
        $value = preg_replace('/[\x00-\x1F\x7F]/', '', $value);
        return function_exists('mb_substr') ? mb_substr($value, 0, $max_length, 'UTF-8') : substr($value, 0, $max_length);
    }

    private static function error($code, $message)
    {
        return new WP_Error($code, __($message, 'jiuliu-crypto-payment'));
    }
}
