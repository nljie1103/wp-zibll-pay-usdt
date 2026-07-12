<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Trongrid
{
    const API_BASE = 'https://api.trongrid.io';

    private $settings;

    public function __construct(JIULIU_USDT_Settings $settings)
    {
        $this->settings = $settings;
    }

    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15)
    {
        if (!JIULIU_USDT_Util::is_valid_tron_address($address)) {
            return new WP_Error('invalid_tron_address', __('TRON 收款地址无效。', 'jiuliu-usdt-payment'));
        }

        $min_timestamp = max(0, (int) $min_timestamp);
        $max_timestamp = max($min_timestamp, (int) $max_timestamp);
        $max_pages = max(1, min(10, absint($max_pages)));
        $fingerprint = '';
        $transfers = array();

        for ($page = 1; $page <= $max_pages; $page++) {
            $query = array(
                'only_confirmed'   => 'true',
                'only_to'          => 'true',
                'limit'            => 200,
                'order_by'         => 'block_timestamp,desc',
                'min_timestamp'    => $min_timestamp,
                'max_timestamp'    => $max_timestamp,
                'contract_address' => JIULIU_USDT_Settings::USDT_CONTRACT,
            );
            if ($fingerprint) {
                $query['fingerprint'] = $fingerprint;
            }

            $url = self::API_BASE . '/v1/accounts/' . rawurlencode($address) . '/transactions/trc20';
            $url = add_query_arg($query, $url);
            $response = $this->request($url, $timeout);
            if (is_wp_error($response)) {
                return $response;
            }

            if (empty($response['success']) || !isset($response['data']) || !is_array($response['data'])) {
                return new WP_Error('trongrid_invalid_response', __('TronGrid 返回的数据格式无效。', 'jiuliu-usdt-payment'));
            }

            foreach ($response['data'] as $transfer) {
                $normalized = $this->normalize_transfer($transfer, $address);
                if ($normalized) {
                    $transfers[] = $normalized;
                }
            }

            $fingerprint = isset($response['meta']['fingerprint']) ? sanitize_text_field($response['meta']['fingerprint']) : '';
            if (!$fingerprint || count($response['data']) < 200) {
                break;
            }
        }

        return $transfers;
    }

    public function find_txid($address, $txid, $min_timestamp, $max_timestamp)
    {
        $txid = strtolower(trim((string) $txid));
        if (!JIULIU_USDT_Util::is_valid_txid($txid)) {
            return new WP_Error('invalid_txid', __('交易哈希格式无效。', 'jiuliu-usdt-payment'));
        }

        $transfers = $this->get_transfers($address, $min_timestamp, $max_timestamp, 8);
        if (is_wp_error($transfers)) {
            return $transfers;
        }

        foreach ($transfers as $transfer) {
            if ($txid === $transfer['transaction_id']) {
                return $transfer;
            }
        }

        return new WP_Error(
            'txid_not_found',
            __('尚未在该收款地址的已确认 USDT-TRC20 入账记录中找到此交易。', 'jiuliu-usdt-payment')
        );
    }

    public function test_connection()
    {
        $address = $this->settings->get('receive_address');
        if (!JIULIU_USDT_Util::is_valid_tron_address($address)) {
            return new WP_Error('invalid_tron_address', __('请先保存有效的 TRON 收款地址。', 'jiuliu-usdt-payment'));
        }

        $now = round(microtime(true) * 1000);
        $result = $this->get_transfers($address, $now - DAY_IN_SECONDS * 1000, $now, 1);
        if (is_wp_error($result)) {
            return $result;
        }

        return array(
            'ok'             => true,
            'transfer_count' => count($result),
        );
    }

    private function request($url, $timeout = 15)
    {
        $headers = array(
            'Accept'     => 'application/json',
            'User-Agent' => 'Jiuliu-USDT-Payment/' . JIULIU_USDT_VERSION . '; ' . home_url('/'),
        );
        $api_key = trim((string) $this->settings->get('trongrid_api_key'));
        if ($api_key) {
            $headers['TRON-PRO-API-KEY'] = $api_key;
        }

        $args = array(
            'timeout'     => max(3, min(20, absint($timeout))),
            'redirection' => 2,
            'headers'     => $headers,
        );
        $args = apply_filters('jiuliu_usdt_trongrid_request_args', $args, $url);

        $response = wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('trongrid_network_error', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (429 === $code) {
            return new WP_Error('trongrid_rate_limited', __('TronGrid 查询频率受限，请稍后重试或配置 API Key。', 'jiuliu-usdt-payment'));
        }
        if (200 !== $code) {
            return new WP_Error(
                'trongrid_http_error',
                sprintf(__('TronGrid 返回 HTTP %d。', 'jiuliu-usdt-payment'), $code)
            );
        }

        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 8 * 1024 * 1024) {
            return new WP_Error('trongrid_response_too_large', __('TronGrid 响应异常过大。', 'jiuliu-usdt-payment'));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return new WP_Error('trongrid_json_error', __('TronGrid 返回了无法解析的数据。', 'jiuliu-usdt-payment'));
        }

        return $decoded;
    }

    private function normalize_transfer($transfer, $address)
    {
        if (!is_array($transfer)) {
            return false;
        }

        $txid = isset($transfer['transaction_id']) ? strtolower((string) $transfer['transaction_id']) : '';
        $to = isset($transfer['to']) ? (string) $transfer['to'] : '';
        $contract = isset($transfer['token_info']['address']) ? (string) $transfer['token_info']['address'] : '';
        $decimals = isset($transfer['token_info']['decimals']) ? (int) $transfer['token_info']['decimals'] : -1;
        $type = isset($transfer['type']) ? strtolower((string) $transfer['type']) : '';
        $raw_value = isset($transfer['value']) ? (string) $transfer['value'] : '';
        $value = preg_match('/^[0-9]+$/D', $raw_value) ? JIULIU_USDT_Util::normalize_raw($raw_value) : '';
        $block_timestamp = isset($transfer['block_timestamp']) ? (int) $transfer['block_timestamp'] : 0;

        if (!JIULIU_USDT_Util::is_valid_txid($txid)) {
            return false;
        }
        if ($to !== $address || $contract !== JIULIU_USDT_Settings::USDT_CONTRACT || 6 !== $decimals) {
            return false;
        }
        if ('transfer' !== $type) {
            return false;
        }
        if ('' === $value || $block_timestamp <= 0 || $block_timestamp > (int) round((microtime(true) + 120) * 1000)) {
            return false;
        }

        return array(
            'transaction_id' => $txid,
            'from'            => isset($transfer['from']) ? (string) $transfer['from'] : '',
            'to'              => $to,
            'value'           => $value,
            'block_timestamp' => $block_timestamp,
            'contract'        => $contract,
            'decimals'        => $decimals,
            'type'            => $type,
        );
    }
}
