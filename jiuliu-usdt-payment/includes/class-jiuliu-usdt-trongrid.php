<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Trongrid
{
    const API_BASE = 'https://api.trongrid.io';
    const BACKOFF_TRANSIENT = 'jiuliu_usdt_trongrid_backoff';
    const FAILURE_TRANSIENT = 'jiuliu_usdt_trongrid_failures';

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
        $configured_max_pages = max(1, min(10, absint($this->settings->get('trongrid_max_pages', 10))));
        $max_pages = max(1, min($configured_max_pages, absint($max_pages)));
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
                self::register_failure('trongrid_invalid_response', 30);
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

        // Reset shared backoff only after the entire pagination request has
        // completed. Clearing it after an intermediate page defeats the
        // exponential counter when a later page fails.
        self::clear_failure_backoff();

        return $transfers;
    }

    public function find_txid($address, $txid, $min_timestamp, $max_timestamp)
    {
        if (!JIULIU_USDT_Util::is_valid_tron_address($address)) {
            return new WP_Error('invalid_tron_address', __('TRON 收款地址无效。', 'jiuliu-usdt-payment'));
        }

        $txid = strtolower(trim((string) $txid));
        if (!JIULIU_USDT_Util::is_valid_txid($txid)) {
            return new WP_Error('invalid_txid', __('交易哈希格式无效。', 'jiuliu-usdt-payment'));
        }

        $min_timestamp = max(0, (int) $min_timestamp);
        $max_timestamp = max($min_timestamp, (int) $max_timestamp);

        // walletsolidity only exposes transactions included in a solidified
        // block, so a merely broadcast transaction can never pass this check.
        $receipt = $this->request(
            self::API_BASE . '/walletsolidity/gettransactioninfobyid',
            15,
            'POST',
            array('value' => $txid)
        );
        if (is_wp_error($receipt)) {
            return $receipt;
        }

        if (empty($receipt)) {
            self::clear_failure_backoff();
            return new WP_Error('txid_not_confirmed', __('该交易尚未确认或不存在。', 'jiuliu-usdt-payment'));
        }

        $receipt_txid = isset($receipt['id']) ? strtolower(trim((string) $receipt['id'])) : '';
        if (!JIULIU_USDT_Util::is_valid_txid($receipt_txid) || $txid !== $receipt_txid) {
            self::register_failure('trongrid_txid_mismatch', 60);
            return new WP_Error('trongrid_txid_mismatch', __('TronGrid 返回的交易哈希不匹配。', 'jiuliu-usdt-payment'));
        }

        $receipt_result = isset($receipt['receipt']['result']) ? strtoupper((string) $receipt['receipt']['result']) : '';
        if ('SUCCESS' !== $receipt_result) {
            self::clear_failure_backoff();
            return new WP_Error('txid_not_successful', __('该交易的链上执行结果不是 SUCCESS。', 'jiuliu-usdt-payment'));
        }

        $receipt_timestamp = isset($receipt['blockTimeStamp']) ? (int) $receipt['blockTimeStamp'] : 0;
        if (!$this->timestamp_in_window($receipt_timestamp, $min_timestamp, $max_timestamp)) {
            self::clear_failure_backoff();
            return new WP_Error('txid_outside_window', __('该交易不在订单允许的付款时间范围内。', 'jiuliu-usdt-payment'));
        }

        $matches = array();
        $fingerprint = '';
        $seen_fingerprints = array();
        $events_complete = false;
        $max_pages = max(1, min(10, absint($this->settings->get('trongrid_max_pages', 10))));
        for ($page = 1; $page <= $max_pages; $page++) {
            $query = array('only_confirmed' => 'true', 'limit' => 200);
            if ($fingerprint) {
                $query['fingerprint'] = $fingerprint;
            }
            $events_url = self::API_BASE . '/v1/transactions/' . rawurlencode($txid) . '/events';
            $events_url = add_query_arg($query, $events_url);
            $events = $this->request($events_url, 15, 'GET');
            if (is_wp_error($events)) {
                return $events;
            }

            if (empty($events['success']) || !isset($events['data']) || !is_array($events['data'])) {
                self::register_failure('trongrid_invalid_event_response', 30);
                return new WP_Error('trongrid_invalid_event_response', __('TronGrid 返回的交易事件格式无效。', 'jiuliu-usdt-payment'));
            }

            foreach ($events['data'] as $event) {
                $normalized = $this->normalize_direct_event(
                    $event,
                    $address,
                    $txid,
                    $receipt_timestamp,
                    $min_timestamp,
                    $max_timestamp
                );
                if ($normalized) {
                    $matches[] = $normalized;
                }
            }

            $next = isset($events['meta']['fingerprint'])
                ? sanitize_text_field((string) $events['meta']['fingerprint'])
                : '';
            if (!$next || count($events['data']) < 200) {
                $events_complete = true;
                break;
            }
            if (isset($seen_fingerprints[$next])) {
                self::register_failure('trongrid_repeated_fingerprint', 30);
                return new WP_Error('trongrid_repeated_fingerprint', __('TronGrid 返回了重复的事件分页游标。', 'jiuliu-usdt-payment'));
            }
            $seen_fingerprints[$next] = true;
            $fingerprint = $next;
        }

        if (!$events_complete) {
            return new WP_Error(
                'txid_event_page_limit',
                __('该交易的事件数量超过核验上限，无法确认唯一入账，请管理员人工处理。', 'jiuliu-usdt-payment')
            );
        }

        // Provider health recovered even when the confirmed transaction is
        // unrelated, unsuccessful for this invoice, or contains ambiguity.
        self::clear_failure_backoff();

        if (count($matches) > 1) {
            return new WP_Error(
                'txid_ambiguous_transfer',
                __('该交易包含多笔匹配的 USDT 入账，无法唯一确定付款金额。', 'jiuliu-usdt-payment')
            );
        }

        if (1 === count($matches)) {
            return $matches[0];
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

    private function request($url, $timeout = 15, $method = 'GET', $body = null)
    {
        $backoff_remaining = self::backoff_remaining();
        if ($backoff_remaining > 0) {
            return new WP_Error(
                'trongrid_backoff',
                sprintf(__('TronGrid 暂停查询以避免连续失败，请在 %d 秒后重试。', 'jiuliu-usdt-payment'), $backoff_remaining)
            );
        }

        $headers = array(
            'Accept'     => 'application/json',
            'User-Agent' => 'Jiuliu-USDT-Payment/' . JIULIU_USDT_VERSION . '; ' . home_url('/'),
        );
        $api_key = trim((string) $this->settings->get('trongrid_api_key'));
        if ($api_key) {
            $headers['TRON-PRO-API-KEY'] = $api_key;
        }

        $method = strtoupper((string) $method);
        if ('GET' !== $method && 'POST' !== $method) {
            return new WP_Error('trongrid_invalid_method', __('TronGrid 请求方法无效。', 'jiuliu-usdt-payment'));
        }

        $args = array(
            'timeout'     => max(3, min(20, absint($timeout))),
            'redirection' => 2,
            'headers'     => $headers,
        );
        if ('POST' === $method) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode((array) $body);
            $args['data_format'] = 'body';
        }
        $args = apply_filters('jiuliu_usdt_trongrid_request_args', $args, $url, $method);

        $response = 'POST' === $method ? wp_remote_post($url, $args) : wp_remote_get($url, $args);
        if (is_wp_error($response)) {
            self::register_failure('trongrid_network_error', 20);
            return new WP_Error('trongrid_network_error', $response->get_error_message());
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (429 === $code) {
            $retry_after = $this->parse_retry_after(wp_remote_retrieve_header($response, 'retry-after'));
            self::register_failure('trongrid_rate_limited', $retry_after ? $retry_after : 60);
            return new WP_Error('trongrid_rate_limited', __('TronGrid 查询频率受限，请稍后重试或配置 API Key。', 'jiuliu-usdt-payment'));
        }
        if (200 !== $code) {
            self::register_failure('trongrid_http_error', $code >= 500 ? 30 : 60);
            return new WP_Error(
                'trongrid_http_error',
                sprintf(__('TronGrid 返回 HTTP %d。', 'jiuliu-usdt-payment'), $code)
            );
        }

        $body = wp_remote_retrieve_body($response);
        if (strlen($body) > 8 * 1024 * 1024) {
            self::register_failure('trongrid_response_too_large', 60);
            return new WP_Error('trongrid_response_too_large', __('TronGrid 响应异常过大。', 'jiuliu-usdt-payment'));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            self::register_failure('trongrid_json_error', 30);
            return new WP_Error('trongrid_json_error', __('TronGrid 返回了无法解析的数据。', 'jiuliu-usdt-payment'));
        }

        return $decoded;
    }

    /**
     * Parse both forms allowed by Retry-After: delay-seconds and HTTP-date.
     */
    private function parse_retry_after($value)
    {
        $value = trim((string) $value);
        if ('' === $value) {
            return 0;
        }

        if (preg_match('/^[0-9]+$/D', $value)) {
            return absint($value);
        }

        $timestamp = strtotime($value);
        if (false === $timestamp || $timestamp <= time()) {
            return 0;
        }

        return (int) ($timestamp - time());
    }

    private function timestamp_in_window($timestamp, $min_timestamp, $max_timestamp)
    {
        $timestamp = (int) $timestamp;
        return $timestamp > 0
            && $timestamp >= (int) $min_timestamp
            && $timestamp <= (int) $max_timestamp
            && $timestamp <= (int) round((microtime(true) + 120) * 1000);
    }

    /**
     * Normalize one confirmed Transfer event from the transaction endpoint.
     * Malformed and unrelated events are never partially trusted.
     */
    private function normalize_direct_event($event, $address, $txid, $receipt_timestamp, $min_timestamp, $max_timestamp)
    {
        if (!is_array($event)) {
            return false;
        }

        $event_txid = isset($event['transaction_id']) ? strtolower(trim((string) $event['transaction_id'])) : '';
        $event_name = isset($event['event_name']) ? (string) $event['event_name'] : '';
        $contract = isset($event['contract_address']) ? trim((string) $event['contract_address']) : '';
        $event_timestamp = isset($event['block_timestamp']) ? (int) $event['block_timestamp'] : 0;
        $result = isset($event['result']) && is_array($event['result']) ? $event['result'] : array();
        $to = isset($result['to']) ? (string) $result['to'] : (isset($result[1]) ? (string) $result[1] : '');
        $from = isset($result['from']) ? (string) $result['from'] : (isset($result[0]) ? (string) $result[0] : '');
        $raw_value = isset($result['value']) ? (string) $result['value'] : (isset($result[2]) ? (string) $result[2] : '');

        if ($txid !== $event_txid || 'Transfer' !== $event_name) {
            return false;
        }
        if (!$this->tron_addresses_equal($contract, JIULIU_USDT_Settings::USDT_CONTRACT)) {
            return false;
        }
        if (!$this->tron_addresses_equal($to, $address)) {
            return false;
        }
        if ($event_timestamp !== (int) $receipt_timestamp || !$this->timestamp_in_window($event_timestamp, $min_timestamp, $max_timestamp)) {
            return false;
        }
        if (!preg_match('/^[0-9]+$/D', $raw_value)) {
            return false;
        }

        return array(
            'transaction_id' => $event_txid,
            'from'            => $from,
            'to'              => $address,
            'value'           => JIULIU_USDT_Util::normalize_raw($raw_value),
            'block_timestamp' => $event_timestamp,
            'contract'        => JIULIU_USDT_Settings::USDT_CONTRACT,
            'decimals'        => 6,
            'type'            => 'transfer',
        );
    }

    /**
     * TronGrid events usually encode addresses as 0x-prefixed EVM addresses,
     * while plugin settings use TRON Base58Check addresses.
     */
    private function tron_addresses_equal($left, $right)
    {
        $left_hex = $this->tron_address_to_evm_hex($left);
        $right_hex = $this->tron_address_to_evm_hex($right);

        return '' !== $left_hex && '' !== $right_hex && hash_equals($left_hex, $right_hex);
    }

    private function tron_address_to_evm_hex($address)
    {
        $address = trim((string) $address);
        if (JIULIU_USDT_Util::is_valid_tron_address($address)) {
            $decoded = JIULIU_USDT_Util::base58_decode($address);
            if (false === $decoded || 25 !== strlen($decoded)) {
                return '';
            }

            return strtolower(bin2hex(substr($decoded, 1, 20)));
        }

        $hex = strtolower($address);
        if (0 === strpos($hex, '0x')) {
            $hex = substr($hex, 2);
        }
        // Some TRON APIs use the 0x41 network prefix. Strip it only when the
        // length proves that it is a prefix, never from a 20-byte address that
        // happens to begin with 41.
        if (42 === strlen($hex) && '41' === substr($hex, 0, 2)) {
            $hex = substr($hex, 2);
        }

        return preg_match('/^[a-f0-9]{40}$/D', $hex) ? $hex : '';
    }

    /**
     * Return the remaining shared backoff window in seconds.
     */
    public static function backoff_remaining()
    {
        $state = get_transient(self::BACKOFF_TRANSIENT);
        if (!is_array($state) || empty($state['until'])) {
            return 0;
        }

        $remaining = (int) $state['until'] - time();
        if ($remaining <= 0) {
            delete_transient(self::BACKOFF_TRANSIENT);
            return 0;
        }

        return $remaining;
    }

    private static function register_failure($reason, $base_seconds)
    {
        $failures = min(8, max(0, absint(get_transient(self::FAILURE_TRANSIENT))) + 1);
        set_transient(self::FAILURE_TRANSIENT, $failures, HOUR_IN_SECONDS);

        $base_seconds = max(5, min(900, absint($base_seconds)));
        $delay = min(900, $base_seconds * pow(2, $failures - 1));
        $delay = (int) apply_filters('jiuliu_usdt_trongrid_backoff_seconds', $delay, $failures, $reason);
        $delay = max(5, min(3600, $delay));

        set_transient(
            self::BACKOFF_TRANSIENT,
            array(
                'until'    => time() + $delay,
                'failures' => $failures,
                'reason'   => sanitize_key($reason),
            ),
            $delay
        );
    }

    private static function clear_failure_backoff()
    {
        delete_transient(self::BACKOFF_TRANSIENT);
        delete_transient(self::FAILURE_TRANSIENT);
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
