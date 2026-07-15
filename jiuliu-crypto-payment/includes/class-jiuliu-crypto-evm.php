<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Strict, read-only ERC-20 verifier for EVM-compatible JSON-RPC endpoints.
 *
 * The route array is intentionally independent from the settings class so one
 * plugin instance can operate any number of independently configured routes.
 * Required keys are rpc_url, chain_id, contract_address and receive_address.
 */
class JIULIU_CRYPTO_EVM
{
    const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    const MAX_RESPONSE_BYTES = 8388608;
    const MAX_LOG_REQUESTS_PER_RANGE = 64;

    private $route;
    private $request_id = 0;
    private $request_timeout = 15;
    private $block_cache = array();
    private $verified_chain_id = null;
    private $verified_token_decimals = null;

    public function __construct($route)
    {
        $this->route = is_array($route) ? $route : array();
        $this->request_timeout = max(3, min(30, $this->route_int('rpc_timeout', 15)));
    }

    /**
     * Verify one transaction receipt and return its only matching ERC-20
     * Transfer. When expected_raw is null, amount comparison is deliberately
     * left to the invoice service so under/over-payments can enter review.
     */
    public function find_txid($address, $txid, $min_timestamp, $max_timestamp, $expected_raw = null)
    {
        $valid = $this->validate_context($address, $min_timestamp, $max_timestamp);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $txid = $this->normalize_txid($txid);
        if ('' === $txid) {
            return new WP_Error('invalid_txid', __('EVM transaction hash format is invalid.', 'jiuliu-crypto-payment'));
        }

        if (null !== $expected_raw && !preg_match('/^[0-9]+$/D', (string) $expected_raw)) {
            return new WP_Error('invalid_expected_raw', __('Expected token amount must be an unsigned base-unit integer.', 'jiuliu-crypto-payment'));
        }
        if (null !== $expected_raw) {
            $expected_raw = $this->normalize_raw($expected_raw);
        }

        $this->block_cache = array();
        $chain = $this->assert_chain_id();
        if (is_wp_error($chain)) {
            return $chain;
        }

        $rpc_txid = '0x' . $txid;
        $receipt = $this->rpc('eth_getTransactionReceipt', array($rpc_txid));
        if (is_wp_error($receipt)) {
            return $receipt;
        }
        if (null === $receipt) {
            return new WP_Error('txid_not_confirmed', __('The transaction does not exist or has not been included in a block.', 'jiuliu-crypto-payment'));
        }
        if (!is_array($receipt)) {
            return new WP_Error('evm_invalid_receipt', __('The EVM RPC endpoint returned an invalid transaction receipt.', 'jiuliu-crypto-payment'));
        }

        $receipt_txid = isset($receipt['transactionHash']) ? $this->normalize_txid($receipt['transactionHash']) : '';
        if ('' === $receipt_txid || !hash_equals($txid, $receipt_txid)) {
            return new WP_Error('evm_txid_mismatch', __('The EVM RPC receipt transaction hash does not match the requested hash.', 'jiuliu-crypto-payment'));
        }
        if (!isset($receipt['status']) || '0x1' !== strtolower((string) $receipt['status'])) {
            return new WP_Error('txid_not_successful', __('The EVM transaction receipt is not successful.', 'jiuliu-crypto-payment'));
        }

        $block_number = isset($receipt['blockNumber']) ? $this->quantity_to_int($receipt['blockNumber']) : false;
        $block_hash = isset($receipt['blockHash']) ? $this->normalize_hash($receipt['blockHash'], 32) : '';
        if (false === $block_number || '' === $block_hash) {
            return new WP_Error('evm_invalid_receipt_block', __('The EVM receipt does not identify a valid confirmed block.', 'jiuliu-crypto-payment'));
        }

        $latest = $this->latest_block_number();
        if (is_wp_error($latest)) {
            return $latest;
        }
        if ($latest < $block_number) {
            return new WP_Error('evm_invalid_confirmation_height', __('The EVM receipt block is higher than the RPC endpoint latest block.', 'jiuliu-crypto-payment'));
        }

        $confirmations = $latest - $block_number + 1;
        $required_confirmations = $this->confirmations();
        if ($confirmations < $required_confirmations) {
            return new WP_Error(
                'txid_not_confirmed',
                sprintf(
                    __('The transaction has %1$d confirmation(s); this route requires %2$d.', 'jiuliu-crypto-payment'),
                    $confirmations,
                    $required_confirmations
                )
            );
        }

        $block = $this->block_info($block_number);
        if (is_wp_error($block)) {
            return $block;
        }
        if (!hash_equals($block_hash, $block['hash'])) {
            return new WP_Error('evm_receipt_block_mismatch', __('The EVM receipt block hash does not match the canonical block.', 'jiuliu-crypto-payment'));
        }

        $block_timestamp = $block['timestamp'] * 1000;
        if (!$this->timestamp_in_window($block_timestamp, $min_timestamp, $max_timestamp)) {
            return new WP_Error('txid_outside_window', __('The transaction is outside the invoice payment time window.', 'jiuliu-crypto-payment'));
        }

        if (!isset($receipt['logs']) || !is_array($receipt['logs'])) {
            return new WP_Error('evm_invalid_receipt_logs', __('The EVM receipt does not contain a valid logs array.', 'jiuliu-crypto-payment'));
        }

        $matches = array();
        foreach ($receipt['logs'] as $log) {
            $transfer = $this->normalize_log(
                $log,
                $address,
                $txid,
                $block_number,
                $block_hash,
                $block_timestamp,
                $confirmations
            );
            if ($transfer) {
                $matches[] = $transfer;
            }
        }

        if (count($matches) > 1) {
            return new WP_Error(
                'txid_ambiguous_transfer',
                __('The transaction contains multiple matching token transfers to the receiving address.', 'jiuliu-crypto-payment')
            );
        }
        if (1 !== count($matches)) {
            return new WP_Error('txid_not_found', __('No matching ERC-20 transfer was found in this transaction.', 'jiuliu-crypto-payment'));
        }
        if (null !== $expected_raw && !hash_equals($expected_raw, $matches[0]['value'])) {
            return new WP_Error('txid_amount_mismatch', __('The ERC-20 transfer amount does not exactly match the expected base-unit amount.', 'jiuliu-crypto-payment'));
        }

        return $matches[0];
    }

    /**
     * Scan a bounded timestamp window with topic-filtered eth_getLogs calls.
     * The method returns all strictly normalized transfers, newest first.
     */
    public function get_transfers($address, $min_timestamp, $max_timestamp, $max_pages = 3, $timeout = 15)
    {
        $valid = $this->validate_context($address, $min_timestamp, $max_timestamp);
        if (is_wp_error($valid)) {
            return $valid;
        }

        $this->request_timeout = max(3, min(30, abs((int) $timeout)));
        $this->block_cache = array();
        $chain = $this->assert_chain_id();
        if (is_wp_error($chain)) {
            return $chain;
        }

        $latest = $this->latest_block_number();
        if (is_wp_error($latest)) {
            return $latest;
        }
        $confirmed_latest = $latest - $this->confirmations() + 1;
        if ($confirmed_latest < 0) {
            return array();
        }

        $first = $this->first_block_at_or_after((int) floor(((int) $min_timestamp) / 1000), $confirmed_latest);
        if (is_wp_error($first)) {
            return $first;
        }
        $last = $this->last_block_at_or_before((int) floor(((int) $max_timestamp) / 1000), $confirmed_latest);
        if (is_wp_error($last)) {
            return $last;
        }
        if (null === $first || null === $last || $first > $last) {
            return array();
        }

        $chunk = max(1, min(10000, $this->route_int('scan_block_chunk', 1000)));
        $configured_max_blocks = max(1, min(1000000, $this->route_int('scan_max_blocks', 50000)));
        $max_pages = max(1, min(100, abs((int) $max_pages)));
        $allowed_blocks = min($configured_max_blocks, $chunk * $max_pages);
        $window_blocks = $last - $first + 1;
        if ($window_blocks > $allowed_blocks) {
            return new WP_Error(
                'evm_scan_window_too_large',
                sprintf(
                    __('The requested EVM scan covers %1$d blocks, above the configured limit of %2$d.', 'jiuliu-crypto-payment'),
                    $window_blocks,
                    $allowed_blocks
                )
            );
        }

        $contract = $this->contract_address();
        $recipient_topic = $this->address_topic($address);
        $max_results = max(1, min(5000, $this->route_int('scan_max_results', 1000)));
        $raw_logs = array();

        for ($from = $first; $from <= $last; $from += $chunk) {
            $to = min($last, $from + $chunk - 1);
            $logs = $this->rpc('eth_getLogs', array(array(
                'fromBlock' => $this->int_to_quantity($from),
                'toBlock'   => $this->int_to_quantity($to),
                'address'   => $contract,
                'topics'    => array(self::TRANSFER_TOPIC, null, $recipient_topic),
            )));
            if (is_wp_error($logs)) {
                return $logs;
            }
            if (!is_array($logs)) {
                return new WP_Error('evm_invalid_logs_response', __('The EVM RPC endpoint returned an invalid eth_getLogs result.', 'jiuliu-crypto-payment'));
            }
            foreach ($logs as $log) {
                $raw_logs[] = $log;
                if (count($raw_logs) > $max_results) {
                    return new WP_Error('evm_log_limit_exceeded', __('The EVM log result count exceeded the configured safety limit.', 'jiuliu-crypto-payment'));
                }
            }
        }

        return $this->normalize_scanned_logs(
            $raw_logs,
            $address,
            $first,
            $last,
            $latest,
            $min_timestamp,
            $max_timestamp
        );
    }

    /**
     * Return a chain-verified confirmed head for persistent cursor scans.
     * The returned head already excludes the route's confirmation depth.
     */
    public function get_confirmed_head($timeout = 15)
    {
        $valid = $this->validate_route();
        if (is_wp_error($valid)) {
            return $valid;
        }

        $this->request_timeout = max(3, min(30, abs((int) $timeout)));
        $this->block_cache = array();
        $chain = $this->assert_chain_id();
        if (is_wp_error($chain)) {
            return $chain;
        }

        $latest = $this->latest_block_number();
        if (is_wp_error($latest)) {
            return $latest;
        }
        $confirmed = $latest - $this->confirmations() + 1;
        if ($confirmed < 0) {
            return new WP_Error('evm_confirmed_head_unavailable', __('The EVM chain does not yet have a block at the configured confirmation depth.', 'jiuliu-crypto-payment'));
        }

        $block = $this->block_info($confirmed);
        if (is_wp_error($block)) {
            return $block;
        }

        return array(
            'chain_id'           => $chain,
            'latest_block'       => (int) $latest,
            'confirmed_block'    => (int) $confirmed,
            'confirmed_timestamp'=> (int) $block['timestamp'] * 1000,
            'confirmations'      => $this->confirmations(),
        );
    }

    /**
     * Locate a timestamp once when a persistent cursor is initialized or its
     * historical target expands. Subsequent cursor ticks use block numbers and
     * therefore do not repeat a genesis-to-head binary search.
     */
    public function locate_block_at_or_after($timestamp_ms, $confirmed_head, $timeout = 15)
    {
        $valid = $this->validate_route();
        if (is_wp_error($valid)) {
            return $valid;
        }

        $timestamp_ms = (int) $timestamp_ms;
        $confirmed_head = (int) $confirmed_head;
        if ($timestamp_ms < 0 || $confirmed_head < 0) {
            return new WP_Error('evm_invalid_cursor_target', __('The EVM cursor timestamp or confirmed head is invalid.', 'jiuliu-crypto-payment'));
        }

        $this->request_timeout = max(3, min(30, abs((int) $timeout)));
        $chain = $this->assert_chain_id();
        if (is_wp_error($chain)) {
            return $chain;
        }

        return $this->first_block_at_or_after((int) floor($timestamp_ms / 1000), $confirmed_head);
    }

    /**
     * Strictly scan one already-bounded confirmed block interval. Oversized
     * log sets are recursively split by block. If a single block alone exceeds
     * the safety limit it is isolated and reported, allowing later blocks on
     * the route to keep making progress while direct txid verification remains
     * available for the isolated block.
     */
    public function get_transfers_by_block_range($address, $from_block, $to_block, $head = array(), $timeout = 15)
    {
        $valid = $this->validate_context($address, 0, (int) round((microtime(true) + 120) * 1000));
        if (is_wp_error($valid)) {
            return $valid;
        }

        $from_block = (int) $from_block;
        $to_block = (int) $to_block;
        if ($from_block < 0 || $to_block < $from_block) {
            return new WP_Error('evm_invalid_block_range', __('The EVM cursor block range is invalid.', 'jiuliu-crypto-payment'));
        }

        $this->request_timeout = max(3, min(30, abs((int) $timeout)));
        $chain = $this->assert_chain_id();
        if (is_wp_error($chain)) {
            return $chain;
        }

        $latest = isset($head['latest_block']) ? (int) $head['latest_block'] : -1;
        $confirmed = isset($head['confirmed_block']) ? (int) $head['confirmed_block'] : -1;
        if ($latest < 0 || $confirmed < 0) {
            $resolved_head = $this->get_confirmed_head($timeout);
            if (is_wp_error($resolved_head)) {
                return $resolved_head;
            }
            $latest = (int) $resolved_head['latest_block'];
            $confirmed = (int) $resolved_head['confirmed_block'];
        }
        if ($to_block > $confirmed || $latest < $confirmed) {
            return new WP_Error('evm_cursor_range_not_confirmed', __('The EVM cursor range extends beyond the confirmed chain head.', 'jiuliu-crypto-payment'));
        }

        $configured_max_blocks = max(1, min(1000000, $this->route_int('scan_max_blocks', 50000)));
        if (($to_block - $from_block + 1) > $configured_max_blocks) {
            return new WP_Error('evm_scan_window_too_large', __('The EVM cursor block interval exceeds the configured scan block limit.', 'jiuliu-crypto-payment'));
        }

        $isolated = array();
        $log_requests = 0;
        $raw_logs = $this->fetch_logs_recursive(
            $address,
            $from_block,
            $to_block,
            max(1, min(5000, $this->route_int('scan_max_results', 1000))),
            $isolated,
            0,
            $log_requests
        );
        if (is_wp_error($raw_logs)) {
            return $raw_logs;
        }

        $transfers = $this->normalize_scanned_logs(
            $raw_logs,
            $address,
            $from_block,
            $to_block,
            $latest,
            null,
            null
        );
        if (is_wp_error($transfers)) {
            return $transfers;
        }

        return array(
            'transfers'       => $transfers,
            'isolated_blocks' => $isolated,
            'from_block'      => $from_block,
            'to_block'        => $to_block,
            'confirmed_block' => $confirmed,
            'log_requests'     => $log_requests,
        );
    }

    private function fetch_logs_recursive($address, $from, $to, $max_results, &$isolated, $depth, &$log_requests)
    {
        if ($depth > 32) {
            return new WP_Error('evm_log_split_depth_exceeded', __('The EVM log interval required too many recursive splits.', 'jiuliu-crypto-payment'));
        }
        if ($log_requests >= self::MAX_LOG_REQUESTS_PER_RANGE) {
            return new WP_Error(
                'evm_log_request_budget_exceeded',
                __('The EVM log interval exceeded the per-scan RPC request budget; the cursor was not advanced.', 'jiuliu-crypto-payment')
            );
        }

        $log_requests++;
        $logs = $this->rpc('eth_getLogs', array(array(
            'fromBlock' => $this->int_to_quantity($from),
            'toBlock'   => $this->int_to_quantity($to),
            'address'   => $this->contract_address(),
            'topics'    => array(self::TRANSFER_TOPIC, null, $this->address_topic($address)),
        )));

        if (is_wp_error($logs)) {
            if (!$this->is_splittable_logs_error($logs)) {
                return $logs;
            }
            if ($from === $to) {
                $isolated[] = array(
                    'block'      => (int) $from,
                    'reason'     => $logs->get_error_code(),
                    'diagnostic' => $this->safe_diagnostic($logs->get_error_message()),
                );
                return array();
            }
        } elseif (!is_array($logs)) {
            return new WP_Error('evm_invalid_logs_response', __('The EVM RPC endpoint returned an invalid eth_getLogs result.', 'jiuliu-crypto-payment'));
        } elseif (count($logs) <= $max_results) {
            return $logs;
        } elseif ($from === $to) {
            $isolated[] = array(
                'block'      => (int) $from,
                'reason'     => 'evm_single_block_log_limit_exceeded',
                'diagnostic' => sprintf(__('The block returned %d matching logs.', 'jiuliu-crypto-payment'), count($logs)),
            );
            return array();
        }

        $mid = $from + (int) floor(($to - $from) / 2);
        // Newer blocks first: a dense historical interval cannot delay a new
        // payment inside the same bounded cursor slice.
        $right = $this->fetch_logs_recursive($address, $mid + 1, $to, $max_results, $isolated, $depth + 1, $log_requests);
        if (is_wp_error($right)) {
            return $right;
        }
        $left = $this->fetch_logs_recursive($address, $from, $mid, $max_results, $isolated, $depth + 1, $log_requests);
        if (is_wp_error($left)) {
            return $left;
        }
        return array_merge($right, $left);
    }

    private function is_splittable_logs_error($error)
    {
        if (!is_wp_error($error)) {
            return false;
        }
        if ('evm_rpc_response_too_large' === $error->get_error_code()) {
            return true;
        }
        if ('evm_rpc_remote_error' !== $error->get_error_code()) {
            return false;
        }
        $message = strtolower((string) $error->get_error_message());
        foreach (array('too many', 'more than', 'response size', 'result limit', 'block range', 'query timeout') as $needle) {
            if (false !== strpos($message, $needle)) {
                return true;
            }
        }
        return false;
    }

    private function normalize_scanned_logs($raw_logs, $address, $first, $last, $latest, $min_timestamp, $max_timestamp)
    {
        $transfers = array();
        $seen_logs = array();
        $per_transaction = array();
        $ambiguous_transactions = array();
        foreach ($raw_logs as $log) {
            if (!is_array($log)) {
                return new WP_Error('evm_malformed_log', __('The EVM RPC endpoint returned a malformed log entry.', 'jiuliu-crypto-payment'));
            }

            $txid = isset($log['transactionHash']) ? $this->normalize_txid($log['transactionHash']) : '';
            $block_number = isset($log['blockNumber']) ? $this->quantity_to_int($log['blockNumber']) : false;
            $block_hash = isset($log['blockHash']) ? $this->normalize_hash($log['blockHash'], 32) : '';
            if ('' === $txid || false === $block_number || '' === $block_hash || $block_number < $first || $block_number > $last) {
                return new WP_Error('evm_malformed_log', __('The EVM RPC endpoint returned a log with invalid transaction or block fields.', 'jiuliu-crypto-payment'));
            }

            $block = $this->block_info($block_number);
            if (is_wp_error($block)) {
                return $block;
            }
            if (!hash_equals($block_hash, $block['hash'])) {
                return new WP_Error('evm_log_block_mismatch', __('An EVM log block hash does not match the canonical block.', 'jiuliu-crypto-payment'));
            }

            $confirmations = $latest - $block_number + 1;
            if ($confirmations < $this->confirmations()) {
                return new WP_Error('evm_log_not_confirmed', __('An EVM log did not meet the configured confirmation requirement.', 'jiuliu-crypto-payment'));
            }

            $timestamp = $block['timestamp'] * 1000;
            $transfer = $this->normalize_log(
                $log,
                $address,
                $txid,
                $block_number,
                $block_hash,
                $timestamp,
                $confirmations
            );
            $in_window = null === $min_timestamp || null === $max_timestamp
                ? ($timestamp > 0 && $timestamp <= (int) round((microtime(true) + 120) * 1000))
                : $this->timestamp_in_window($timestamp, $min_timestamp, $max_timestamp);
            if (!$transfer || !$in_window) {
                return new WP_Error('evm_malformed_matching_log', __('A topic-filtered EVM log failed strict ERC-20 validation.', 'jiuliu-crypto-payment'));
            }

            $log_index = isset($log['logIndex']) ? $this->quantity_to_int($log['logIndex']) : false;
            if (false === $log_index) {
                return new WP_Error('evm_malformed_log', __('An EVM log is missing a valid log index.', 'jiuliu-crypto-payment'));
            }
            $key = $txid . ':' . $log_index;
            if (isset($seen_logs[$key])) {
                return new WP_Error('evm_duplicate_log', __('The EVM RPC endpoint returned the same log more than once.', 'jiuliu-crypto-payment'));
            }
            $seen_logs[$key] = true;
            $transfer['log_index'] = $log_index;
            $transfers[] = $transfer;

            if (!isset($per_transaction[$txid])) {
                $per_transaction[$txid] = 0;
            }
            $per_transaction[$txid]++;
            if ($per_transaction[$txid] > 1) {
                // A valid batch transaction (or an attacker) can emit two
                // matching transfers. Exclude that transaction without
                // starving every unrelated invoice on this route. Direct
                // transaction-hash verification remains fail-closed.
                $ambiguous_transactions[$txid] = true;
            }
        }

        if ($ambiguous_transactions) {
            $transfers = array_values(array_filter($transfers, function ($transfer) use ($ambiguous_transactions) {
                return empty($ambiguous_transactions[$transfer['transaction_id']]);
            }));
        }

        usort($transfers, array($this, 'sort_transfers_newest_first'));
        return $transfers;
    }

    /**
     * Verify chain identity, on-chain ERC-20 precision, latest-block access
     * and eth_getLogs support. A syntactically valid contract address is not
     * enough: quoting with the wrong decimals changes the financial amount.
     */
    public function test_connection()
    {
        $valid = $this->validate_route();
        if (is_wp_error($valid)) {
            return $valid;
        }

        $this->block_cache = array();
        $chain = $this->assert_chain_id();
        if (is_wp_error($chain)) {
            return $chain;
        }
        $token_decimals = $this->assert_token_decimals();
        if (is_wp_error($token_decimals)) {
            return $token_decimals;
        }
        $latest = $this->latest_block_number();
        if (is_wp_error($latest)) {
            return $latest;
        }

        $logs = $this->rpc('eth_getLogs', array(array(
            'fromBlock' => $this->int_to_quantity($latest),
            'toBlock'   => $this->int_to_quantity($latest),
            'address'   => $this->contract_address(),
            'topics'    => array(self::TRANSFER_TOPIC, null, $this->address_topic($this->receive_address())),
        )));
        if (is_wp_error($logs)) {
            return $logs;
        }
        if (!is_array($logs)) {
            return new WP_Error('evm_invalid_logs_response', __('The endpoint does not return a valid eth_getLogs result.', 'jiuliu-crypto-payment'));
        }

        return array(
            'ok'              => true,
            'chain_id'        => $chain,
            'token_decimals'  => $token_decimals,
            'eth_call'        => true,
            'latest_block'    => $latest,
            'eth_getlogs'     => true,
            'logs_in_latest'  => count($logs),
        );
    }

    /**
     * Read decimals() using the canonical ERC-20 selector and require one
     * ABI-encoded uint256 word. This rejects EOAs, wrong token contracts,
     * truncated RPC results and route metadata that does not match the chain.
     */
    private function assert_token_decimals()
    {
        if (null !== $this->verified_token_decimals) {
            return $this->verified_token_decimals;
        }

        $result = $this->rpc('eth_call', array(array(
            'to'   => $this->contract_address(),
            'data' => '0x313ce567',
        ), 'latest'));
        if (is_wp_error($result)) {
            return $result;
        }

        $result = strtolower(trim((string) $result));
        if (!preg_match('/^0x[a-f0-9]{64}$/D', $result)) {
            return new WP_Error(
                'evm_invalid_token_decimals_response',
                __('The ERC-20 decimals() call did not return one ABI uint256 word.', 'jiuliu-crypto-payment')
            );
        }

        $actual = $this->hex_to_decimal(substr($result, 2));
        if ('' === $actual || !preg_match('/^[0-9]+$/D', $actual)) {
            return new WP_Error(
                'evm_invalid_token_decimals_response',
                __('The ERC-20 decimals() result is invalid.', 'jiuliu-crypto-payment')
            );
        }

        $expected = (string) $this->token_decimals();
        if (!hash_equals($expected, $actual)) {
            return new WP_Error(
                'evm_token_decimals_mismatch',
                sprintf(
                    __('The token contract reports %1$s decimals, but this route requires %2$s.', 'jiuliu-crypto-payment'),
                    $actual,
                    $expected
                )
            );
        }

        $this->verified_token_decimals = (int) $actual;
        return $this->verified_token_decimals;
    }

    public function sort_transfers_newest_first($left, $right)
    {
        $left_time = isset($left['block_timestamp']) ? (int) $left['block_timestamp'] : 0;
        $right_time = isset($right['block_timestamp']) ? (int) $right['block_timestamp'] : 0;
        if ($left_time !== $right_time) {
            return $left_time > $right_time ? -1 : 1;
        }

        $left_index = isset($left['log_index']) ? (int) $left['log_index'] : 0;
        $right_index = isset($right['log_index']) ? (int) $right['log_index'] : 0;
        if ($left_index === $right_index) {
            return 0;
        }
        return $left_index > $right_index ? -1 : 1;
    }

    private function validate_context($address, $min_timestamp, $max_timestamp)
    {
        $valid = $this->validate_route();
        if (is_wp_error($valid)) {
            return $valid;
        }

        $address = $this->normalize_address($address);
        if ('' === $address || !hash_equals($this->receive_address(), $address)) {
            return new WP_Error('evm_receive_address_mismatch', __('The requested receiving address does not match the configured EVM route.', 'jiuliu-crypto-payment'));
        }

        $min_timestamp = (int) $min_timestamp;
        $max_timestamp = (int) $max_timestamp;
        if ($min_timestamp < 0 || $max_timestamp < $min_timestamp) {
            return new WP_Error('invalid_payment_window', __('The EVM payment time window is invalid.', 'jiuliu-crypto-payment'));
        }

        return true;
    }

    private function validate_route()
    {
        $url = isset($this->route['rpc_url']) ? trim((string) $this->route['rpc_url']) : '';
        if ('' === $url || strlen($url) > 2048 || !filter_var($url, FILTER_VALIDATE_URL)) {
            return new WP_Error('invalid_evm_rpc_url', __('The EVM JSON-RPC URL is invalid.', 'jiuliu-crypto-payment'));
        }
        $parts = parse_url($url);
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        if (('http' !== $scheme && 'https' !== $scheme) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            return new WP_Error('invalid_evm_rpc_url', __('The EVM JSON-RPC URL must be an HTTP(S) URL without credentials or a fragment.', 'jiuliu-crypto-payment'));
        }
        if ('' === $this->configured_chain_id()) {
            return new WP_Error('invalid_evm_chain_id', __('The configured EVM chain ID is invalid.', 'jiuliu-crypto-payment'));
        }
        if ('' === $this->contract_address()) {
            return new WP_Error('invalid_evm_contract', __('The configured ERC-20 contract address is invalid.', 'jiuliu-crypto-payment'));
        }
        if ('' === $this->receive_address()) {
            return new WP_Error('invalid_evm_receive_address', __('The configured EVM receiving address is invalid.', 'jiuliu-crypto-payment'));
        }
        $decimals_key = array_key_exists('decimals', $this->route) ? 'decimals' : 'asset_decimals';
        if (isset($this->route[$decimals_key]) && !preg_match('/^[0-9]+$/D', (string) $this->route[$decimals_key])) {
            return new WP_Error('invalid_evm_decimals', __('The configured token decimals value is invalid.', 'jiuliu-crypto-payment'));
        }
        $decimals = $this->token_decimals();
        if ($decimals < 0 || $decimals > 36) {
            return new WP_Error('invalid_evm_decimals', __('The configured token decimals value is invalid.', 'jiuliu-crypto-payment'));
        }
        $confirmations_key = array_key_exists('confirmations', $this->route) ? 'confirmations' : 'required_confirmations';
        if (isset($this->route[$confirmations_key])
            && (!preg_match('/^[0-9]+$/D', (string) $this->route[$confirmations_key])
                || (int) $this->route[$confirmations_key] < 1
                || (int) $this->route[$confirmations_key] > 1000)) {
            return new WP_Error('invalid_evm_confirmations', __('The configured EVM confirmation count is invalid.', 'jiuliu-crypto-payment'));
        }
        return true;
    }

    private function assert_chain_id()
    {
        if (null !== $this->verified_chain_id) {
            return $this->verified_chain_id;
        }

        $actual = $this->rpc('eth_chainId', array());
        if (is_wp_error($actual)) {
            return $actual;
        }

        $actual = $this->quantity_to_decimal($actual);
        $expected = $this->configured_chain_id();
        if ('' === $actual) {
            return new WP_Error('evm_invalid_chain_id_response', __('The EVM RPC endpoint returned an invalid chain ID.', 'jiuliu-crypto-payment'));
        }
        if (!hash_equals($expected, $actual)) {
            return new WP_Error(
                'evm_chain_id_mismatch',
                sprintf(
                    __('The EVM RPC endpoint reports chain ID %1$s, but this route requires %2$s.', 'jiuliu-crypto-payment'),
                    $actual,
                    $expected
                )
            );
        }
        $this->verified_chain_id = $actual;
        return $this->verified_chain_id;
    }

    private function latest_block_number()
    {
        $latest = $this->rpc('eth_blockNumber', array());
        if (is_wp_error($latest)) {
            return $latest;
        }
        $latest = $this->quantity_to_int($latest);
        if (false === $latest) {
            return new WP_Error('evm_invalid_block_number', __('The EVM RPC endpoint returned an invalid latest block number.', 'jiuliu-crypto-payment'));
        }
        return $latest;
    }

    private function block_info($number)
    {
        $number = (int) $number;
        if (isset($this->block_cache[$number])) {
            return $this->block_cache[$number];
        }

        $block = $this->rpc('eth_getBlockByNumber', array($this->int_to_quantity($number), false));
        if (is_wp_error($block)) {
            return $block;
        }
        if (!is_array($block)) {
            return new WP_Error('evm_block_not_found', __('The EVM RPC endpoint did not return the requested block.', 'jiuliu-crypto-payment'));
        }

        $returned_number = isset($block['number']) ? $this->quantity_to_int($block['number']) : false;
        $timestamp = isset($block['timestamp']) ? $this->quantity_to_int($block['timestamp']) : false;
        $hash = isset($block['hash']) ? $this->normalize_hash($block['hash'], 32) : '';
        if ($returned_number !== $number || false === $timestamp || '' === $hash) {
            return new WP_Error('evm_invalid_block', __('The EVM RPC endpoint returned malformed or mismatched block data.', 'jiuliu-crypto-payment'));
        }
        if ($timestamp > time() + 120) {
            return new WP_Error('evm_future_block_timestamp', __('The EVM block timestamp is unreasonably far in the future.', 'jiuliu-crypto-payment'));
        }

        $this->block_cache[$number] = array(
            'number'    => $number,
            'timestamp' => $timestamp,
            'hash'      => $hash,
        );
        return $this->block_cache[$number];
    }

    private function first_block_at_or_after($target_timestamp, $high)
    {
        $genesis = $this->block_info(0);
        if (is_wp_error($genesis)) {
            return $genesis;
        }
        $last = $this->block_info($high);
        if (is_wp_error($last)) {
            return $last;
        }
        if ($target_timestamp <= $genesis['timestamp']) {
            return 0;
        }
        if ($target_timestamp > $last['timestamp']) {
            return null;
        }

        $low = 0;
        while ($low < $high) {
            $mid = $low + (int) floor(($high - $low) / 2);
            $block = $this->block_info($mid);
            if (is_wp_error($block)) {
                return $block;
            }
            if ($block['timestamp'] < $target_timestamp) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }
        return $low;
    }

    private function last_block_at_or_before($target_timestamp, $high)
    {
        $genesis = $this->block_info(0);
        if (is_wp_error($genesis)) {
            return $genesis;
        }
        $last = $this->block_info($high);
        if (is_wp_error($last)) {
            return $last;
        }
        if ($target_timestamp < $genesis['timestamp']) {
            return null;
        }
        if ($target_timestamp >= $last['timestamp']) {
            return $high;
        }

        $low = 0;
        while ($low < $high) {
            $mid = $low + (int) floor(($high - $low + 1) / 2);
            $block = $this->block_info($mid);
            if (is_wp_error($block)) {
                return $block;
            }
            if ($block['timestamp'] <= $target_timestamp) {
                $low = $mid;
            } else {
                $high = $mid - 1;
            }
        }
        return $low;
    }

    private function normalize_log($log, $address, $txid, $block_number, $block_hash, $timestamp, $confirmations)
    {
        if (!is_array($log) || (isset($log['removed']) && false !== $log['removed'])) {
            return false;
        }

        $contract = isset($log['address']) ? $this->normalize_address($log['address']) : '';
        $log_txid = isset($log['transactionHash']) ? $this->normalize_txid($log['transactionHash']) : '';
        $log_block = isset($log['blockNumber']) ? $this->quantity_to_int($log['blockNumber']) : false;
        $log_block_hash = isset($log['blockHash']) ? $this->normalize_hash($log['blockHash'], 32) : '';
        $topics = isset($log['topics']) && is_array($log['topics']) ? $log['topics'] : array();
        $data = isset($log['data']) ? strtolower((string) $log['data']) : '';

        if ('' === $contract || !hash_equals($this->contract_address(), $contract)) {
            return false;
        }
        if ('' === $log_txid || !hash_equals($txid, $log_txid)) {
            return false;
        }
        if ($log_block !== (int) $block_number || '' === $log_block_hash || !hash_equals($block_hash, $log_block_hash)) {
            return false;
        }
        if (3 !== count($topics) || strtolower((string) $topics[0]) !== self::TRANSFER_TOPIC) {
            return false;
        }

        $from = $this->topic_address($topics[1]);
        $to = $this->topic_address($topics[2]);
        if ('' === $from || '' === $to || !hash_equals($this->normalize_address($address), $to)) {
            return false;
        }
        if (!preg_match('/^0x[a-f0-9]{64}$/D', $data)) {
            return false;
        }

        $raw = $this->hex_to_decimal(substr($data, 2));
        if ('' === $raw) {
            return false;
        }

        return array(
            'transaction_id' => $log_txid,
            'from'            => $from,
            'to'              => $to,
            'value'           => $raw,
            'block_timestamp' => (int) $timestamp,
            'block_number'    => (int) $block_number,
            'block_hash'      => $block_hash,
            'confirmations'   => (int) $confirmations,
            'contract'        => $contract,
            'decimals'        => $this->token_decimals(),
            'chain_id'        => $this->configured_chain_id(),
            'type'            => 'transfer',
        );
    }

    private function rpc($method, $params)
    {
        $url = isset($this->route['rpc_url']) ? trim((string) $this->route['rpc_url']) : '';
        $this->request_id++;
        if ($this->request_id > 2147483647) {
            $this->request_id = 1;
        }
        $request_id = $this->request_id;

        $headers = array(
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent'   => 'Jiuliu-Crypto-Payment/' . (defined('JIULIU_CRYPTO_VERSION') ? JIULIU_CRYPTO_VERSION : '2.1.2') . '; ' . home_url('/'),
        );
        if (isset($this->route['rpc_headers']) && is_array($this->route['rpc_headers'])) {
            foreach ($this->route['rpc_headers'] as $name => $value) {
                $name = trim((string) $name);
                $value = trim((string) $value);
                if (!preg_match('/^[A-Za-z0-9-]{1,64}$/D', $name) || preg_match('/[\r\n]/', $value) || strlen($value) > 1024) {
                    continue;
                }
                $lower = strtolower($name);
                if ('host' === $lower || 'content-length' === $lower || 'content-type' === $lower) {
                    continue;
                }
                $headers[$name] = $value;
            }
        }

        $payload = array(
            'jsonrpc' => '2.0',
            'id'      => $request_id,
            'method'  => (string) $method,
            'params'  => array_values((array) $params),
        );
        $encoded = wp_json_encode($payload);
        if (!is_string($encoded) || '' === $encoded) {
            return new WP_Error('evm_rpc_encode_error', __('Unable to encode the EVM JSON-RPC request.', 'jiuliu-crypto-payment'));
        }

        $args = array(
            'timeout'     => $this->request_timeout,
            'redirection' => 0,
            'headers'     => $headers,
            'body'        => $encoded,
            'data_format' => 'body',
        );
        $args = apply_filters('jiuliu_crypto_evm_request_args', $args, $url, $method, $this->route);
        // The endpoint is administrator-configurable. WordPress' safe HTTP
        // wrapper re-validates the destination at request time and blocks
        // private/reserved network targets, including after DNS resolution.
        $response = wp_safe_remote_post($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error(
                'evm_rpc_network_error',
                sprintf(__('EVM RPC %1$s network error: %2$s', 'jiuliu-crypto-payment'), $method, $response->get_error_message())
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if (429 === $code) {
            return new WP_Error('evm_rpc_rate_limited', sprintf(__('EVM RPC %s is rate limited (HTTP 429).', 'jiuliu-crypto-payment'), $method));
        }
        if (200 !== $code) {
            return new WP_Error(
                'evm_rpc_http_error',
                sprintf(__('EVM RPC %1$s returned HTTP %2$d.', 'jiuliu-crypto-payment'), $method, $code)
            );
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body)) {
            return new WP_Error('evm_rpc_invalid_body', sprintf(__('EVM RPC %s returned a non-text response body.', 'jiuliu-crypto-payment'), $method));
        }
        $configured_max = $this->route_int('rpc_max_response_bytes', 4194304);
        $max_bytes = max(1024, min(self::MAX_RESPONSE_BYTES, $configured_max));
        if (strlen($body) > $max_bytes) {
            return new WP_Error('evm_rpc_response_too_large', sprintf(__('EVM RPC %s response exceeded the configured size limit.', 'jiuliu-crypto-payment'), $method));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || JSON_ERROR_NONE !== json_last_error()) {
            return new WP_Error('evm_rpc_json_error', sprintf(__('EVM RPC %s returned invalid JSON.', 'jiuliu-crypto-payment'), $method));
        }
        if (!isset($decoded['jsonrpc']) || '2.0' !== (string) $decoded['jsonrpc']) {
            return new WP_Error('evm_rpc_protocol_error', sprintf(__('EVM RPC %s returned an invalid JSON-RPC version.', 'jiuliu-crypto-payment'), $method));
        }
        if (!array_key_exists('id', $decoded) || !is_int($decoded['id']) || $request_id !== $decoded['id']) {
            return new WP_Error('evm_rpc_id_mismatch', sprintf(__('EVM RPC %s returned a mismatched request ID.', 'jiuliu-crypto-payment'), $method));
        }
        if (isset($decoded['error'])) {
            $remote_code = is_array($decoded['error']) && isset($decoded['error']['code']) ? (string) $decoded['error']['code'] : 'unknown';
            $remote_message = is_array($decoded['error']) && isset($decoded['error']['message'])
                ? $this->safe_diagnostic($decoded['error']['message'])
                : __('unknown remote error', 'jiuliu-crypto-payment');
            return new WP_Error(
                'evm_rpc_remote_error',
                sprintf(__('EVM RPC %1$s failed (%2$s): %3$s', 'jiuliu-crypto-payment'), $method, $remote_code, $remote_message)
            );
        }
        if (!array_key_exists('result', $decoded)) {
            return new WP_Error('evm_rpc_protocol_error', sprintf(__('EVM RPC %s response is missing result.', 'jiuliu-crypto-payment'), $method));
        }

        return $decoded['result'];
    }

    private function timestamp_in_window($timestamp, $min_timestamp, $max_timestamp)
    {
        $timestamp = (int) $timestamp;
        return $timestamp > 0
            && $timestamp >= (int) $min_timestamp
            && $timestamp <= (int) $max_timestamp
            && $timestamp <= (int) round((microtime(true) + 120) * 1000);
    }

    private function confirmations()
    {
        $key = array_key_exists('confirmations', $this->route) ? 'confirmations' : 'required_confirmations';
        return max(1, min(1000, $this->route_int($key, 12)));
    }

    private function token_decimals()
    {
        $key = array_key_exists('decimals', $this->route) ? 'decimals' : 'asset_decimals';
        return $this->route_int($key, 6);
    }

    private function contract_address()
    {
        return $this->normalize_address(isset($this->route['contract_address']) ? $this->route['contract_address'] : '');
    }

    private function receive_address()
    {
        return $this->normalize_address(isset($this->route['receive_address']) ? $this->route['receive_address'] : '');
    }

    private function configured_chain_id()
    {
        $value = isset($this->route['chain_id']) ? trim((string) $this->route['chain_id']) : '';
        if (preg_match('/^0x(?:0|[1-9a-fA-F][0-9a-fA-F]*)$/D', $value)) {
            return $this->quantity_to_decimal(strtolower($value));
        }
        if (!preg_match('/^[0-9]+$/D', $value)) {
            return '';
        }
        $value = ltrim($value, '0');
        return '' === $value || '0' === $value ? '' : $value;
    }

    private function normalize_address($address)
    {
        $address = strtolower(trim((string) $address));
        return preg_match('/^0x[a-f0-9]{40}$/D', $address) ? $address : '';
    }

    private function normalize_txid($txid)
    {
        $txid = strtolower(trim((string) $txid));
        if (0 === strpos($txid, '0x')) {
            $txid = substr($txid, 2);
        }
        return preg_match('/^[a-f0-9]{64}$/D', $txid) ? $txid : '';
    }

    private function normalize_hash($hash, $bytes)
    {
        $hash = strtolower(trim((string) $hash));
        $length = (int) $bytes * 2;
        return preg_match('/^0x[a-f0-9]{' . $length . '}$/D', $hash) ? $hash : '';
    }

    private function topic_address($topic)
    {
        $topic = strtolower(trim((string) $topic));
        if (!preg_match('/^0x0{24}[a-f0-9]{40}$/D', $topic)) {
            return '';
        }
        return '0x' . substr($topic, -40);
    }

    private function address_topic($address)
    {
        $address = $this->normalize_address($address);
        return '' === $address ? '' : '0x' . str_repeat('0', 24) . substr($address, 2);
    }

    private function quantity_to_decimal($quantity)
    {
        $quantity = strtolower(trim((string) $quantity));
        if (!preg_match('/^0x(?:0|[1-9a-f][0-9a-f]*)$/D', $quantity)) {
            return '';
        }
        return $this->hex_to_decimal(substr($quantity, 2));
    }

    private function quantity_to_int($quantity)
    {
        $decimal = $this->quantity_to_decimal($quantity);
        if ('' === $decimal || $this->decimal_compare($decimal, (string) PHP_INT_MAX) > 0) {
            return false;
        }
        return (int) $decimal;
    }

    private function int_to_quantity($number)
    {
        $number = max(0, (int) $number);
        return '0x' . dechex($number);
    }

    /**
     * Convert an arbitrary-width hexadecimal unsigned integer without GMP.
     */
    private function hex_to_decimal($hex)
    {
        $hex = strtolower(trim((string) $hex));
        if ('' === $hex || !preg_match('/^[a-f0-9]+$/D', $hex)) {
            return '';
        }

        $decimal = '0';
        $length = strlen($hex);
        for ($i = 0; $i < $length; $i++) {
            $digit = strpos('0123456789abcdef', $hex[$i]);
            if (false === $digit) {
                return '';
            }
            $decimal = $this->decimal_multiply_add($decimal, 16, $digit);
        }
        return $this->normalize_raw($decimal);
    }

    private function decimal_multiply_add($decimal, $multiplier, $addition)
    {
        $carry = (int) $addition;
        $output = '';
        for ($i = strlen($decimal) - 1; $i >= 0; $i--) {
            $value = ((int) $decimal[$i]) * (int) $multiplier + $carry;
            $output = (string) ($value % 10) . $output;
            $carry = (int) floor($value / 10);
        }
        while ($carry > 0) {
            $output = (string) ($carry % 10) . $output;
            $carry = (int) floor($carry / 10);
        }
        return $this->normalize_raw($output);
    }

    private function decimal_compare($left, $right)
    {
        $left = $this->normalize_raw($left);
        $right = $this->normalize_raw($right);
        if (strlen($left) !== strlen($right)) {
            return strlen($left) > strlen($right) ? 1 : -1;
        }
        if ($left === $right) {
            return 0;
        }
        return strcmp($left, $right) > 0 ? 1 : -1;
    }

    private function normalize_raw($raw)
    {
        $raw = preg_replace('/[^0-9]/', '', (string) $raw);
        $raw = ltrim($raw, '0');
        return '' === $raw ? '0' : $raw;
    }

    private function route_int($key, $default)
    {
        return isset($this->route[$key]) && is_numeric($this->route[$key]) ? (int) $this->route[$key] : (int) $default;
    }

    private function safe_diagnostic($message)
    {
        $message = strip_tags((string) $message);
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message);
        $message = preg_replace('/\s+/', ' ', $message);
        return substr(trim($message), 0, 240);
    }
}
