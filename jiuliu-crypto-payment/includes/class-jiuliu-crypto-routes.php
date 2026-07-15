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
    const MAX_ROUTES = 64;

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
                'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t', 'TRX', 1,
                'usdt', 'tether', 'Tether', 'issuer_native', 6
            ),
            'usdt_ethereum' => self::preset(
                'usdt_ethereum', 'USDT', 'Tether USD', 6, 'evm',
                'Ethereum (ERC20)', 'ethereum', '1',
                '0xdac17f958d2ee523a2206206994597c13d831ec7', 'ETH', 12,
                'usdt', 'tether', 'Tether', 'issuer_native', 6
            ),
            'usdc_ethereum' => self::preset(
                'usdc_ethereum', 'USDC', 'USD Coin', 6, 'evm',
                'Ethereum (ERC20)', 'ethereum', '1',
                '0xa0b86991c6218b36c1d19d4a2e9eb0ce3606eb48', 'ETH', 12,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_base' => self::preset(
                'usdc_base', 'USDC', 'USD Coin', 6, 'evm',
                'Base (ERC20)', 'base', '8453',
                '0x833589fcd6edb6e08f4c7c32d4f71b54bda02913', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_arbitrum' => self::preset(
                'usdc_arbitrum', 'USDC', 'USD Coin', 6, 'evm',
                'Arbitrum One (ERC20)', 'arbitrum', '42161',
                '0xaf88d065e77c8cc2239327c5edb3a432268e5831', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_polygon' => self::preset(
                'usdc_polygon', 'USDC', 'USD Coin', 6, 'evm',
                'Polygon PoS (ERC20)', 'polygon', '137',
                '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359', 'POL', 64,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_avalanche' => self::preset(
                'usdc_avalanche', 'USDC', 'USD Coin', 6, 'evm',
                'Avalanche C-Chain (ERC20)', 'avalanche', '43114',
                '0xb97ef9ef8734c71904d8002f8b6bc66dd9c48a6e', 'AVAX', 12,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdt_bsc' => self::preset(
                'usdt_bsc', 'USDT', 'Binance-Peg BSC-USD', 18, 'evm',
                'BNB Smart Chain (BEP20, Binance-Peg)', 'bsc', '56',
                '0x55d398326f99059ff775485246999027b3197955', 'BNB', 15,
                'usdt', 'tether', 'Binance-Peg', 'custodial_peg', 6
            ),
            'usdc_bsc' => self::preset(
                'usdc_bsc', 'USDC', 'Binance-Peg USD Coin', 18, 'evm',
                'BNB Smart Chain (BEP20, Binance-Peg)', 'bsc', '56',
                '0x8ac76a51cc950d9822d68b83fe1ad97b32cd580d', 'BNB', 15,
                'usdc', 'usd-coin', 'Binance-Peg', 'custodial_peg', 6
            ),
            'fdusd_bsc' => self::preset(
                'fdusd_bsc', 'FDUSD', 'First Digital USD', 18, 'evm',
                'BNB Smart Chain (BEP20)', 'bsc', '56',
                '0xc5f0f7b66764f6ec8c8dff7ba683102295e16409', 'BNB', 15,
                'fdusd', 'first-digital-usd', 'First Digital / FD121', 'issuer_native', 6
            ),
            'usdc_optimism' => self::preset(
                'usdc_optimism', 'USDC', 'USD Coin', 6, 'evm',
                'OP Mainnet (ERC20)', 'optimism', '10',
                '0x0b2c639c533813f4aa9d7837caf62653d097ff85', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdt_celo' => self::preset(
                'usdt_celo', 'USDT', 'Tether USD', 6, 'evm',
                'Celo (ERC20)', 'celo', '42220',
                '0x48065fbbe25f71c9282ddf5e1cd6d6a887483d5e', 'CELO', 12,
                'usdt', 'tether', 'Tether', 'issuer_native', 6
            ),
            'usdc_celo' => self::preset(
                'usdc_celo', 'USDC', 'USD Coin', 6, 'evm',
                'Celo (ERC20)', 'celo', '42220',
                '0xceba9300f2b948710d2653dd7b07f33a8b32118c', 'CELO', 12,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdt_avalanche' => self::preset(
                'usdt_avalanche', 'USDT', 'Tether USD', 6, 'evm',
                'Avalanche C-Chain (ERC20)', 'avalanche', '43114',
                '0x9702230a8ea53601f5cd2dc00fdbc13d4df4a8c7', 'AVAX', 12,
                'usdt', 'tether', 'Tether', 'issuer_native', 6
            ),
            'usdc_linea' => self::preset(
                'usdc_linea', 'USDC', 'USD Coin', 6, 'evm',
                'Linea (ERC20)', 'linea', '59144',
                '0x176211869ca2b568f2a7d4ee941e073a821ee1ff', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_zksync' => self::preset(
                'usdc_zksync', 'USDC', 'USD Coin', 6, 'evm',
                'ZKsync Era (ERC20)', 'zksync', '324',
                '0x1d17cbcf0d6d143135ae902365d2e5e2a16538d4', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_unichain' => self::preset(
                'usdc_unichain', 'USDC', 'USD Coin', 6, 'evm',
                'Unichain (ERC20)', 'unichain', '130',
                '0x078d782b760474a361dda0af3839290b0ef57ad6', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_world_chain' => self::preset(
                'usdc_world_chain', 'USDC', 'USD Coin', 6, 'evm',
                'World Chain (ERC20)', 'world_chain', '480',
                '0x79a02482a880bce3f13e09da970dc34db4cd24d1', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_ink' => self::preset(
                'usdc_ink', 'USDC', 'USD Coin', 6, 'evm',
                'Ink (ERC20)', 'ink', '57073',
                '0x2d270e6886d130d724215a266106e6832161eaed', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_sonic' => self::preset(
                'usdc_sonic', 'USDC', 'USD Coin', 6, 'evm',
                'Sonic (ERC20)', 'sonic', '146',
                '0x29219dd400f2bf60e5a23d13be72b486d4038894', 'S', 12,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_cronos' => self::preset(
                'usdc_cronos', 'USDC', 'USD Coin', 6, 'evm',
                'Cronos (CRC20)', 'cronos', '25',
                '0x3d7f2c478aafdb65542bcb44bceec05849999d2d', 'CRO', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_hyperevm' => self::preset(
                'usdc_hyperevm', 'USDC', 'USD Coin', 6, 'evm',
                'HyperEVM (ERC20)', 'hyperevm', '999',
                '0xb88339cb7199b77e23db6e890353e22632ba630f', 'HYPE', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_morph' => self::preset(
                'usdc_morph', 'USDC', 'USD Coin', 6, 'evm',
                'Morph (ERC20)', 'morph', '2818',
                '0xcfb1186f4e93d60e60a8bdd997427d1f33bc372b', 'ETH', 20,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_monad' => self::preset(
                'usdc_monad', 'USDC', 'USD Coin', 6, 'evm',
                'Monad (ERC20)', 'monad', '143',
                '0x754704bc059f8c67012fed69bc8a327a5aafb603', 'MON', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_sei' => self::preset(
                'usdc_sei', 'USDC', 'USD Coin', 6, 'evm',
                'Sei EVM (ERC20)', 'sei', '1329',
                '0xe15fc38f6d8c56af07bbcbe3baf5708a2bf42392', 'SEI', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_xdc' => self::preset(
                'usdc_xdc', 'USDC', 'USD Coin', 6, 'evm',
                'XDC Network (XRC20)', 'xdc', '50',
                '0xfa2958cb79b0491cc627c1557f441ef849ca8eb1', 'XDC', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_plume' => self::preset(
                'usdc_plume', 'USDC', 'USD Coin', 6, 'evm',
                'Plume (ERC20)', 'plume', '98866',
                '0x222365ef19f7947e5484218551b56bb3965aa7af', 'PLUME', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdc_injective' => self::preset(
                'usdc_injective', 'USDC', 'USD Coin', 6, 'evm',
                'Injective EVM (ERC20)', 'injective', '1776',
                '0xa00c59ff5a080d2b954d0c75e46e22a0c371235a', 'INJ', 15,
                'usdc', 'usd-coin', 'Circle', 'issuer_native', 6
            ),
            'usdt_kava' => self::preset(
                'usdt_kava', 'USDT', 'Tether USD', 6, 'evm',
                'Kava EVM (ERC20)', 'kava', '2222',
                '0x919c1c267bc06a7039e03fcc2ef738525769109c', 'KAVA', 12,
                'usdt', 'tether', 'Tether', 'issuer_native', 6
            ),
            'usdt_kaia' => self::preset(
                'usdt_kaia', 'USDT', 'Tether USD', 6, 'evm',
                'Kaia (ERC20)', 'kaia', '8217',
                '0xd077a400968890eacc75cdc901f0356c943e4fdb', 'KAIA', 12,
                'usdt', 'tether', 'Tether', 'issuer_native', 6
            ),
            'pyusd_ethereum' => self::preset(
                'pyusd_ethereum', 'PYUSD', 'PayPal USD', 6, 'evm',
                'Ethereum (ERC20)', 'ethereum', '1',
                '0x6c3ea9036406852006290770bedfcaba0e23a0e8', 'ETH', 12,
                'pyusd', 'paypal-usd', 'Paxos / PayPal', 'issuer_native', 6
            ),
            'pyusd_arbitrum' => self::preset(
                'pyusd_arbitrum', 'PYUSD', 'PayPal USD', 6, 'evm',
                'Arbitrum One (ERC20)', 'arbitrum', '42161',
                '0x46850ad61c2b7d64d08c9c754f45254596696984', 'ETH', 20,
                'pyusd', 'paypal-usd', 'Paxos / PayPal', 'issuer_native', 6
            ),
            'eurc_ethereum' => self::preset(
                'eurc_ethereum', 'EURC', 'Euro Coin', 6, 'evm',
                'Ethereum (ERC20)', 'ethereum', '1',
                '0x1abaea1f7c830bd89acc67ec4af516284b1bc33c', 'ETH', 12,
                'eurc', 'euro-coin', 'Circle', 'issuer_native', 6, '8.50000000'
            ),
            'eurc_avalanche' => self::preset(
                'eurc_avalanche', 'EURC', 'Euro Coin', 6, 'evm',
                'Avalanche C-Chain (ERC20)', 'avalanche', '43114',
                '0xc891eb4cbdeff6e073e859e987815ed1505c2acd', 'AVAX', 12,
                'eurc', 'euro-coin', 'Circle', 'issuer_native', 6, '8.50000000'
            ),
            'eurc_base' => self::preset(
                'eurc_base', 'EURC', 'Euro Coin', 6, 'evm',
                'Base (ERC20)', 'base', '8453',
                '0x60a3e35cc302bfa44cb288bc5a4f316fdb1adb42', 'ETH', 20,
                'eurc', 'euro-coin', 'Circle', 'issuer_native', 6, '8.50000000'
            ),
            'eurc_cronos' => self::preset(
                'eurc_cronos', 'EURC', 'Euro Coin', 6, 'evm',
                'Cronos (CRC20)', 'cronos', '25',
                '0xa6de01a2d62c6b5f3525d768f34d276652c554c8', 'CRO', 15,
                'eurc', 'euro-coin', 'Circle', 'issuer_native', 6, '8.50000000'
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
            return self::error('too_many_routes', '支付路线最多允许 64 条。');
        }

        $normalized = array();
        $ids = array();
        $chain_ids = array();
        $chain_keys = array();
        $asset_contracts = array();
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

            // A contract on one chain has one financial identity. Refuse a
            // second route that re-labels its decimals, asset or custody type;
            // otherwise the same Transfer value could be quoted differently.
            $contract_identity = $chain_identity . ':' . strtolower((string) $item['contract_address']);
            $metadata = implode('|', array(
                (string) $item['asset_decimals'],
                (string) $item['asset_id'],
                (string) $item['asset_type'],
                (string) $item['issuer_label'],
            ));
            if (isset($asset_contracts[$contract_identity]) && !hash_equals($asset_contracts[$contract_identity], $metadata)) {
                return self::error('asset_metadata_conflict', '同一链上合约不能使用冲突的币种、精度或发行类型。');
            }

            $ids[$item['id']] = true;
            $chain_ids[$chain_identity] = $item['chain_key'];
            $chain_keys[$item['chain_key']] = $chain_identity;
            $asset_contracts[$contract_identity] = $metadata;
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

        $route = self::pin_preset_identity($route, $id);
        if (is_wp_error($route)) {
            return $route;
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

        $display_decimals = isset($route['display_decimals']) ? $route['display_decimals'] : min($decimals, 6);
        if (!self::integer_in_range($display_decimals, 0, min($decimals, 6))) {
            return self::error('invalid_display_decimals', '显示精度必须是 0 至代币精度（最多 6）之间的整数。');
        }
        $display_decimals = (int) $display_decimals;

        $asset_id = isset($route['asset_id']) ? strtolower(trim((string) $route['asset_id'])) : strtolower($symbol);
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,31}$/D', $asset_id)) {
            return self::error('invalid_asset_id', '币种内部 ID 格式无效。');
        }
        if (!in_array($asset_id, array('usdt', 'usdc', 'fdusd', 'pyusd', 'eurc'), true)) {
            return self::error('unsupported_asset_id', '当前版本只接受已核验的稳定币资产。');
        }
        $rate_provider_id = isset($route['rate_provider_id'])
            ? strtolower(trim((string) $route['rate_provider_id']))
            : $asset_id;
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,63}$/D', $rate_provider_id)) {
            return self::error('invalid_rate_provider_id', '行情资产 ID 格式无效。');
        }
        $issuer_label = self::clean_text(isset($route['issuer_label']) ? $route['issuer_label'] : '', 64);
        if ('' === $issuer_label) {
            return self::error('invalid_issuer_label', '发行方标签不能为空。');
        }
        $asset_type = isset($route['asset_type']) ? strtolower(trim((string) $route['asset_type'])) : '';
        if (!in_array($asset_type, array('issuer_native', 'custodial_peg'), true)) {
            return self::error('invalid_asset_type', '资产类型只能是发行方原生或托管锚定。');
        }

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
        $max_rate = 'eurc' === $asset_id ? 30 : 20;
        if (!is_finite($rate) || $rate < 1 || $rate > $max_rate) {
            return self::error('invalid_route_rate', '稳定币 CNY 汇率超出安全范围。');
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
            'asset_id' => $asset_id,
            'rate_provider_id' => $rate_provider_id,
            'issuer_label' => $issuer_label,
            'asset_type' => $asset_type,
            'asset_decimals' => $decimals,
            'decimals' => $decimals,
            'display_decimals' => $display_decimals,
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
            'asset_identity' => hash('sha256', $adapter . '|' . $chain_id . '|' . strtolower($contract) . '|' . $decimals),
        );
    }

    private static function preset(
        $id,
        $symbol,
        $name,
        $decimals,
        $adapter,
        $network,
        $chain_key,
        $chain_id,
        $contract,
        $fee_symbol,
        $confirmations,
        $asset_id,
        $rate_provider_id,
        $issuer_label,
        $asset_type,
        $display_decimals = 6,
        $rate_cny = '7.20000000'
    )
    {
        return array(
            'id' => $id,
            'enabled' => 0,
            'asset_symbol' => $symbol,
            'asset_name' => $name,
            'asset_id' => $asset_id,
            'rate_provider_id' => $rate_provider_id,
            'issuer_label' => $issuer_label,
            'asset_type' => $asset_type,
            'asset_decimals' => $decimals,
            'display_decimals' => $display_decimals,
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
            'rate_cny' => $rate_cny,
            'scan_block_chunk' => 500,
            'scan_max_blocks' => 5000,
            'scan_max_results' => 200,
            'rpc_timeout' => 15,
        );
    }

    /**
     * Known presets are a server-owned allowlist. Administrators may provide
     * receivers, RPC credentials and pricing policy, but may not turn a known
     * route ID into a different token or disguise a custodial peg as native.
     * Missing immutable fields are restored so ordinary admin form posts do
     * not have to echo new security metadata back to the browser.
     */
    private static function pin_preset_identity($route, $id)
    {
        $presets = self::presets();
        if (!isset($presets[$id])) {
            return self::error(
                'unknown_route_preset',
                '只允许使用插件内置并核验过的支付路线；未知路线 ID 已拒绝。'
            );
        }

        $preset = $presets[$id];
        $immutable = array(
            'asset_symbol', 'asset_id', 'rate_provider_id', 'issuer_label',
            'asset_type', 'asset_decimals', 'display_decimals', 'adapter',
            'chain_key', 'chain_id', 'contract_address', 'fee_symbol',
        );
        foreach ($immutable as $field) {
            $expected = $preset[$field];
            if (array_key_exists($field, $route)
                && !self::same_preset_value($field, $expected, $route[$field])) {
                return self::error('preset_identity_mismatch', '预设路线的链、合约、精度、币种或发行类型不可修改。');
            }
            $route[$field] = $expected;
        }

        return $route;
    }

    private static function same_preset_value($field, $expected, $actual)
    {
        if (in_array($field, array('asset_decimals', 'display_decimals'), true)) {
            return preg_match('/^[0-9]+$/D', (string) $actual)
                && (int) $expected === (int) $actual;
        }
        if ('issuer_label' === $field) {
            return trim((string) $expected) === trim((string) $actual);
        }
        return strtolower(trim((string) $expected)) === strtolower(trim((string) $actual));
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
