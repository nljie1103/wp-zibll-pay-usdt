<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Invoices
{
    private $settings;
    private $db;
    private $rate;
    private $trongrid;

    public function __construct(
        JIULIU_USDT_Settings $settings,
        JIULIU_USDT_DB $db,
        JIULIU_USDT_Rate $rate,
        JIULIU_USDT_Trongrid $trongrid
    ) {
        $this->settings = $settings;
        $this->db       = $db;
        $this->rate     = $rate;
        $this->trongrid = $trongrid;
    }

    public function create_for_zibll($order_data)
    {
        if (!$this->settings->is_enabled()) {
            return new WP_Error('gateway_disabled', __('USDT-TRC20 收款尚未完成配置。', 'jiuliu-usdt-payment'));
        }

        $order_num = isset($order_data['order_num']) ? sanitize_text_field($order_data['order_num']) : '';
        $payment_id = isset($order_data['payment_id']) ? absint($order_data['payment_id']) : 0;
        $user_id = isset($order_data['user_id']) ? absint($order_data['user_id']) : 0;
        $local_amount = isset($order_data['local_price']) ? (float) $order_data['local_price'] : (isset($order_data['order_price']) ? (float) $order_data['order_price'] : 0);

        if (!$order_num || !$payment_id || $local_amount <= 0) {
            return new WP_Error('invalid_zibll_order', __('子比支付单数据不完整，无法生成 USDT 支付单。', 'jiuliu-usdt-payment'));
        }

        if (!$this->db->acquire_payment_lock($payment_id, 3)) {
            return new WP_Error('quote_busy', __('支付单正在生成，请稍后重新打开收银台。', 'jiuliu-usdt-payment'));
        }

        try {
        // Re-read the stable parent payment while holding a payment_id-scoped
        // database lock. This prevents two simultaneous cashier requests from
        // creating two active exact-amount quotes for the same Zibll payment.
        if (class_exists('ZibPay') && is_callable(array('ZibPay', 'get_payment'))) {
            $current_payment = ZibPay::get_payment($payment_id);
            if (!$current_payment || empty($current_payment['order_num']) || 'usdt_trc20' !== (string) $current_payment['method']) {
                return new WP_Error('zibll_payment_changed', __('子比支付方式已经变化，请重新打开收银台。', 'jiuliu-usdt-payment'));
            }
            $order_num = sanitize_text_field($current_payment['order_num']);
            $parent_amount = isset($current_payment['price']) ? (float) $current_payment['price'] : 0;
            if ($parent_amount <= 0 || (int) round($parent_amount * 100) !== (int) round($local_amount * 100)) {
                return new WP_Error('zibll_price_changed', __('子比主支付单金额已经变化，请重新下单。', 'jiuliu-usdt-payment'));
            }
            $local_amount = $parent_amount;
        }

        $minimum = (float) $this->settings->get('minimum_local_amount', 1);
        $maximum = (float) $this->settings->get('maximum_local_amount', 100000);
        if ($local_amount < $minimum || $local_amount > $maximum) {
            return new WP_Error(
                'amount_out_of_range',
                sprintf(
                    __('当前订单金额不在 USDT 支付允许范围内（%1$s - %2$s）。', 'jiuliu-usdt-payment'),
                    $minimum,
                    $maximum
                )
            );
        }

        $existing = $this->db->get_by_order_num($order_num);
        if ($existing && in_array($existing->status, array('pending', 'expired'), true)) {
            $expires = JIULIU_USDT_Util::utc_timestamp_from_mysql($existing->expires_at);
            if ($expires >= time()) {
                $token = JIULIU_USDT_Util::random_token(24);
                $this->db->update_invoice($existing->id, array(
                    'public_token_hash' => hash('sha256', $token),
                    'last_checked_at'   => null,
                ));
                return array(
                    'invoice'     => $this->db->get_invoice($existing->id),
                    'public_token' => $token,
                );
            }
        }

        $expiration = $this->get_expiration_timestamp($payment_id);
        if (is_wp_error($expiration)) {
            return $expiration;
        }

        // One stable quote per payment_id. Zibll refreshes its 520... number on
        // every initiate request; rotate only that mapping and the public token,
        // preserving the address, exact amount and original deadline.
        $reusable = $this->db->get_reusable_by_payment_id($payment_id);
        if (
            $reusable
            && JIULIU_USDT_Util::utc_timestamp_from_mysql($reusable->expires_at) >= time()
            && (int) round((float) $reusable->local_amount * 100) === (int) round($local_amount * 100)
            && (string) $reusable->receive_address === (string) $this->settings->get('receive_address')
        ) {
            $token = JIULIU_USDT_Util::random_token(24);
            if ($this->db->refresh_invoice_attempt($reusable->id, $order_num, hash('sha256', $token))) {
                $this->db->supersede_payment_invoices($payment_id, $order_num);
                return array(
                    'invoice'      => $this->db->get_invoice($reusable->id),
                    'public_token' => $token,
                );
            }

            $raced_invoice = $this->db->get_by_order_num($order_num);
            if ($raced_invoice && 'pending' === $raced_invoice->status && JIULIU_USDT_Util::utc_timestamp_from_mysql($raced_invoice->expires_at) >= time()) {
                $token = JIULIU_USDT_Util::random_token(24);
                if ($this->db->refresh_invoice_attempt($raced_invoice->id, $order_num, hash('sha256', $token))) {
                    return array(
                        'invoice'      => $this->db->get_invoice($raced_invoice->id),
                        'public_token' => $token,
                    );
                }
            }
        }

        $rate_data = $this->rate->get_rate();
        $rate = !empty($rate_data['rate']) ? (float) $rate_data['rate'] : 0;
        if ($rate <= 0) {
            return new WP_Error('invalid_exchange_rate', __('当前无法取得有效汇率，请稍后重试。', 'jiuliu-usdt-payment'));
        }

        $markup = (float) $this->settings->get('rate_markup', 0);
        $quoted = ($local_amount / $rate) * (1 + ($markup / 100));
        if ($quoted <= 0 || !is_finite($quoted) || ($quoted * 1000000) > (PHP_INT_MAX - 10000)) {
            return new WP_Error('invalid_quote', __('USDT 报价计算失败。', 'jiuliu-usdt-payment'));
        }

        $base_raw = (int) ceil($quoted * 1000000);
        // The unique tail is an order identifier. Keep it below 1% of the
        // quote and below 0.001 USDT, while requiring enough capacity that a
        // handful of abandoned same-price orders cannot exhaust the pool.
        $max_unique_tail = min(999, max(1, (int) floor($base_raw * 0.01)));
        if ($max_unique_tail < 500) {
            return new WP_Error(
                'amount_too_small_for_unique_quote',
                __('当前订单金额过低，无法安全生成足够的唯一 USDT 尾数；请提高订单金额或后台最低金额。', 'jiuliu-usdt-payment')
            );
        }
        $address = $this->settings->get('receive_address');
        $token = JIULIU_USDT_Util::random_token(24);
        $created_at = JIULIU_USDT_Util::utc_now_mysql();
        $expires_at = JIULIU_USDT_Util::utc_mysql_from_timestamp($expiration);

        $last_error = null;
        $max_attempts = min(30, $max_unique_tail);
        for ($attempt = 0; $attempt < $max_attempts; $attempt++) {
            $expected_raw = (string) ($base_raw + wp_rand(1, $max_unique_tail));
            $usdt_amount = JIULIU_USDT_Util::raw_to_decimal($expected_raw, 6);
            $active_key = hash('sha256', $address . '|' . $expected_raw);

            $data = array(
                'invoice_no'       => $this->generate_invoice_no(),
                'payment_id'       => $payment_id,
                'zibll_order_num'  => $order_num,
                'user_id'          => $user_id,
                'local_amount'     => number_format($local_amount, 8, '.', ''),
                'local_currency'   => (string) apply_filters('jiuliu_usdt_local_currency', 'CNY', $order_data),
                'rate'             => number_format($rate, 8, '.', ''),
                'rate_source'      => substr(sanitize_key($rate_data['source']), 0, 32),
                'markup'           => number_format($markup, 4, '.', ''),
                'usdt_amount'      => $usdt_amount,
                'expected_raw'     => $expected_raw,
                'receive_address'  => $address,
                'contract_address' => JIULIU_USDT_Settings::USDT_CONTRACT,
                'network'          => 'TRC20',
                'status'           => 'pending',
                'active_key'       => $active_key,
                'public_token_hash'=> hash('sha256', $token),
                'created_at'       => $created_at,
                'expires_at'       => $expires_at,
                'updated_at'       => $created_at,
            );

            $invoice = $this->db->insert_invoice($data);
            if (!is_wp_error($invoice)) {
                // Only retire the previous cashier quote after the replacement
                // exists. A database failure must never leave the customer with
                // neither an old nor a new usable quote.
                $this->db->supersede_payment_invoices($payment_id, $order_num);
                $this->db->log(
                    'invoice_created',
                    __('已创建 USDT-TRC20 支付单。', 'jiuliu-usdt-payment'),
                    $invoice->id,
                    'info',
                    array(
                        'payment_id' => $payment_id,
                        'order_num'  => $order_num,
                        'amount'     => $usdt_amount,
                        'rate_source'=> $rate_data['source'],
                    ),
                    $user_id
                );

                return array(
                    'invoice'      => $invoice,
                    'public_token' => $token,
                );
            }

            // Concurrent requests for the same freshly generated Zibll order
            // number may race. Reuse the winner instead of looping on the
            // unique order-number index.
            $raced_invoice = $this->db->get_by_order_num($order_num);
            if ($raced_invoice && in_array($raced_invoice->status, array('pending', 'expired'), true)) {
                if (JIULIU_USDT_Util::utc_timestamp_from_mysql($raced_invoice->expires_at) < time()) {
                    return new WP_Error('zibll_order_expired', __('此支付单已经过期，请重新下单。', 'jiuliu-usdt-payment'));
                }
                $token = JIULIU_USDT_Util::random_token(24);
                $this->db->update_invoice($raced_invoice->id, array(
                    'public_token_hash' => hash('sha256', $token),
                    'last_checked_at'   => null,
                ));
                return array(
                    'invoice'      => $this->db->get_invoice($raced_invoice->id),
                    'public_token' => $token,
                );
            }
            $last_error = $invoice;
            if ('invoice_duplicate' !== $invoice->get_error_code()) {
                $this->db->log(
                    'invoice_insert_failed',
                    $invoice->get_error_message(),
                    0,
                    'error',
                    array('payment_id' => $payment_id)
                );
                return $invoice;
            }
        }

        return $last_error ?: new WP_Error('quote_collision', __('当前金额的唯一尾数空间已用尽，请稍后重试或提高最低订单金额。', 'jiuliu-usdt-payment'));
        } finally {
            $this->db->release_payment_lock($payment_id);
        }
    }

    private function get_expiration_timestamp($payment_id)
    {
        $configured = time() + absint($this->settings->get('invoice_timeout', 30)) * MINUTE_IN_SECONDS;

        if (class_exists('ZibPay') && function_exists('zibpay_get_payment_pay_over_time')) {
            $payment = ZibPay::get_payment($payment_id);
            if ($payment) {
                $theme_expiration = zibpay_get_payment_pay_over_time($payment);
                if ('over' === $theme_expiration) {
                    return new WP_Error('zibll_order_expired', __('子比支付单已经过期，请重新下单。', 'jiuliu-usdt-payment'));
                }
                if (is_numeric($theme_expiration)) {
                    // Zibll represents local wall-clock strings with strtotime()
                    // while WordPress keeps PHP's default timezone at UTC. Convert
                    // the returned value to a duration before comparing it with a
                    // real UTC Unix timestamp.
                    $zibll_wall_clock_now = strtotime(current_time('mysql'));
                    $remaining = (int) $theme_expiration - (int) $zibll_wall_clock_now;
                    if ($remaining <= 0) {
                        return new WP_Error('zibll_order_expired', __('子比支付单已经过期，请重新下单。', 'jiuliu-usdt-payment'));
                    }
                    return time() + $remaining;
                }
            }
        }

        return $configured;
    }

    private function generate_invoice_no()
    {
        return 'JU' . gmdate('ymdHis') . strtoupper(substr(JIULIU_USDT_Util::random_token(5), 0, 10));
    }

    public function build_zibll_response($order_data)
    {
        $created = $this->create_for_zibll($order_data);
        if (is_wp_error($created)) {
            return array(
                'error' => 1,
                'msg'   => $created->get_error_message(),
            );
        }

        $invoice = $created['invoice'];
        // A second cashier request can update Zibll's parent order number just
        // after create_for_zibll releases its advisory lock. Align once more
        // before rendering so a request that aborts midway cannot strand the
        // surviving cashier on a number with no invoice mapping.
        if (class_exists('ZibPay') && is_callable(array('ZibPay', 'get_payment'))) {
            $payment = ZibPay::get_payment($invoice->payment_id);
            if (
                !$payment
                || empty($payment['order_num'])
                || empty($payment['method'])
                || 'usdt_trc20' !== (string) $payment['method']
                || !isset($payment['status'])
                || '0' !== (string) $payment['status']
            ) {
                return array(
                    'error' => 1,
                    'msg'   => __('子比支付单已经变化，请重新打开收银台。', 'jiuliu-usdt-payment'),
                );
            }

            $current_order_num = sanitize_text_field($payment['order_num']);
            if ((string) $invoice->zibll_order_num !== $current_order_num) {
                if (!$this->db->refresh_invoice_attempt(
                    $invoice->id,
                    $current_order_num,
                    hash('sha256', $created['public_token'])
                )) {
                    return array(
                        'error' => 1,
                        'msg'   => __('支付单并发同步失败，请重新打开收银台。', 'jiuliu-usdt-payment'),
                    );
                }
                $this->db->supersede_payment_invoices($invoice->payment_id, $current_order_num);
                $invoice = $this->db->get_invoice($invoice->id);
            }
        }
        if (!function_exists('zib_get_qrcode_base64')) {
            return array(
                'error' => 1,
                'msg'   => __('当前子比主题缺少二维码组件，无法显示 USDT 收款码。', 'jiuliu-usdt-payment'),
            );
        }

        $qr_payload = apply_filters('jiuliu_usdt_qr_payload', $invoice->receive_address, $invoice);
        $qrcode = zib_get_qrcode_base64($qr_payload);

        return array(
            'error'        => 0,
            'order_num'    => $invoice->zibll_order_num,
            'url_qrcode'   => $qrcode,
            'check_sdk'    => 'jiuliu_usdt_trc20',
            'order_name'   => __('USDT-TRC20 链上支付', 'jiuliu-usdt-payment'),
            'settle_mark'  => '₮',
            'settle_price' => $invoice->usdt_amount,
            'settle_unit'  => ' USDT',
            'more_html'    => $this->render_frontend_details($invoice, $created['public_token']),
        );
    }

    private function render_frontend_details($invoice, $public_token)
    {
        $mark = function_exists('zibpay_get_pay_mark') ? zibpay_get_pay_mark() : '¥';
        $expires_local = JIULIU_USDT_Util::display_datetime($invoice->expires_at);
        $manual = '';

        if ($this->settings->get('frontend_manual_txid')) {
            $manual = '<details class="jiuliu-usdt-manual">'
                . '<summary>' . esc_html__('已经付款？提交交易哈希', 'jiuliu-usdt-payment') . '</summary>'
                . '<form class="jiuliu-usdt-tx-form">'
                . '<input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">'
                . '<input type="hidden" name="public_token" value="' . esc_attr($public_token) . '">'
                . '<input type="hidden" name="security" value="' . esc_attr(wp_create_nonce('jiuliu_usdt_invoice_' . $invoice->id)) . '">'
                . '<div class="jiuliu-usdt-tx-row"><input class="form-control" type="text" name="txid" maxlength="64" autocomplete="off" placeholder="' . esc_attr__('粘贴 64 位交易哈希', 'jiuliu-usdt-payment') . '">'
                . '<button type="submit" class="but c-blue">' . esc_html__('核验', 'jiuliu-usdt-payment') . '</button></div>'
                . '<div class="jiuliu-usdt-tx-result" aria-live="polite"></div>'
                . '</form></details>';
        }

        return '<div class="jiuliu-usdt-details" data-invoice="' . esc_attr($invoice->id) . '">'
            . '<div class="jiuliu-usdt-warning"><strong>' . esc_html__('仅限 TRON（TRC20）网络', 'jiuliu-usdt-payment') . '</strong><br>'
            . esc_html__('请勿使用 ERC20、BEP20 或其他网络，否则资产可能无法找回。', 'jiuliu-usdt-payment') . '</div>'
            . '<div class="jiuliu-usdt-field"><span>' . esc_html__('精确付款金额', 'jiuliu-usdt-payment') . '</span><div><b>' . esc_html($invoice->usdt_amount) . ' USDT</b>'
            . '<button type="button" class="jiuliu-usdt-copy but hollow" data-copy="' . esc_attr($invoice->usdt_amount) . '">' . esc_html__('复制', 'jiuliu-usdt-payment') . '</button></div></div>'
            . '<div class="jiuliu-usdt-field jiuliu-usdt-address"><span>' . esc_html__('TRC20 收款地址', 'jiuliu-usdt-payment') . '</span><div><code>' . esc_html($invoice->receive_address) . '</code>'
            . '<button type="button" class="jiuliu-usdt-copy but hollow" data-copy="' . esc_attr($invoice->receive_address) . '">' . esc_html__('复制', 'jiuliu-usdt-payment') . '</button></div></div>'
            . '<div class="jiuliu-usdt-meta"><span>' . esc_html__('订单', 'jiuliu-usdt-payment') . ' ' . esc_html($invoice->invoice_no) . '</span>'
            . '<span>' . esc_html($mark) . esc_html(number_format((float) $invoice->local_amount, 2, '.', '')) . ' / 1 USDT = ' . esc_html($mark) . esc_html(rtrim(rtrim($invoice->rate, '0'), '.')) . '</span></div>'
            . '<div class="jiuliu-usdt-expiry">' . sprintf(esc_html__('请在 %s 前完成转账，必须支付页面显示的全部六位小数；金额含不超过 0.001 USDT 的订单识别尾数。', 'jiuliu-usdt-payment'), esc_html($expires_local)) . '</div>'
            . $manual
            . '</div>';
    }

    public function check_order($order_num, $force = false)
    {
        $invoice = $this->db->get_by_order_num($order_num);
        $allowed_statuses = array('pending', 'expired');
        if ($force) {
            $allowed_statuses[] = 'closed';
            $allowed_statuses[] = 'closed_no_monitor';
        } elseif ($this->settings->get('monitor_closed_orders', 1)) {
            $allowed_statuses[] = 'closed';
        }
        if (!$invoice || !in_array($invoice->status, $allowed_statuses, true)) {
            return $invoice;
        }

        if (!$force) {
            // A single compare-and-set closes the public polling race. A
            // read-then-write throttle lets a burst of concurrent requests all
            // reach TronGrid before any request observes the new timestamp.
            if (!$this->db->acquire_check_slot($invoice->id, 8)) {
                return $invoice;
            }
        } else {
            $this->db->update_invoice($invoice->id, array('last_checked_at' => JIULIU_USDT_Util::utc_now_mysql()));
        }

        $created_ms = max(0, (JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->created_at) - 5) * 1000);
        $grace_end = JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->expires_at)
            + absint($this->settings->get('late_grace_hours', 24)) * HOUR_IN_SECONDS;
        $max_ms = min(time(), $grace_end) * 1000;
        if ($max_ms < $created_ms) {
            return $invoice;
        }

        $transfers = $this->trongrid->get_transfers(
            $invoice->receive_address,
            $created_ms,
            $max_ms,
            $force ? 8 : 1,
            $force ? 15 : 5
        );
        if (is_wp_error($transfers)) {
            $this->db->log(
                'chain_check_failed',
                $transfers->get_error_message(),
                $invoice->id,
                'warning',
                array('error_code' => $transfers->get_error_code())
            );
            return $invoice;
        }

        foreach ($transfers as $transfer) {
            if (JIULIU_USDT_Util::normalize_raw($transfer['value']) === JIULIU_USDT_Util::normalize_raw($invoice->expected_raw)) {
                return $this->process_transfer($invoice, $transfer, false);
            }
        }

        return $this->db->get_invoice($invoice->id);
    }

    public function verify_public_txid($invoice_id, $txid, $public_token)
    {
        $invoice = $this->db->get_invoice($invoice_id);
        if (!$invoice) {
            return new WP_Error('invoice_not_found', __('支付单不存在。', 'jiuliu-usdt-payment'));
        }

        if (!$this->verify_public_token($invoice, $public_token)) {
            return new WP_Error('invalid_invoice_token', __('支付单验证失败，请刷新页面后重试。', 'jiuliu-usdt-payment'));
        }

        return $this->verify_txid_for_invoice($invoice, $txid, false, 'public');
    }

    public function verify_admin_txid($invoice_id, $txid, $force = false)
    {
        $invoice = $this->db->get_invoice($invoice_id);
        if (!$invoice) {
            return new WP_Error('invoice_not_found', __('支付单不存在。', 'jiuliu-usdt-payment'));
        }

        return $this->verify_txid_for_invoice($invoice, $txid, (bool) $force, 'admin');
    }

    private function verify_txid_for_invoice($invoice, $txid, $force, $source)
    {
        $txid = strtolower(trim((string) $txid));
        if (!JIULIU_USDT_Util::is_valid_txid($txid)) {
            return new WP_Error('invalid_txid', __('交易哈希应为 64 位十六进制字符。', 'jiuliu-usdt-payment'));
        }

        if ('paid' === $invoice->status) {
            if ($invoice->txid === $txid) {
                return $invoice;
            }
            return new WP_Error('invoice_already_paid', __('此支付单已经完成，不能更换交易哈希。', 'jiuliu-usdt-payment'));
        }

        if ('rejected' === $invoice->status && !$force) {
            return new WP_Error('invoice_unavailable', __('此支付单已经失效，请联系管理员处理。', 'jiuliu-usdt-payment'));
        }

        if ('public' === $source && !$this->db->acquire_check_slot($invoice->id, 8)) {
            return new WP_Error('chain_check_throttled', __('链上核验正在进行，请 8 秒后重试。', 'jiuliu-usdt-payment'));
        }

        $used = $this->db->get_by_txid($txid);
        if ($used && (int) $used->id !== (int) $invoice->id) {
            return new WP_Error('txid_already_used', __('此交易哈希已绑定其他支付单。', 'jiuliu-usdt-payment'));
        }

        $min_ms = max(0, (JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->created_at) - 5) * 1000);
        $max_ms = time() * 1000;
        $transfer = $this->trongrid->find_txid($invoice->receive_address, $txid, $min_ms, $max_ms);
        if (is_wp_error($transfer)) {
            $this->db->log(
                'manual_txid_not_found',
                $transfer->get_error_message(),
                $invoice->id,
                'warning',
                array('txid' => $txid, 'error_code' => $transfer->get_error_code()),
                get_current_user_id()
            );
            return $transfer;
        }

        return $this->process_transfer($invoice, $transfer, $force, $source);
    }

    private function verify_public_token($invoice, $token)
    {
        $token = (string) $token;
        if (!$token || empty($invoice->public_token_hash)) {
            return false;
        }
        return hash_equals((string) $invoice->public_token_hash, hash('sha256', $token));
    }

    public function scan_pending()
    {
        $invoices = $this->db->pending_for_scan(
            $this->settings->get('late_grace_hours', 24),
            500,
            (bool) $this->settings->get('monitor_closed_orders', 1)
        );
        if (!$invoices) {
            $closed = $this->sync_zibll_expirations();
            $this->db->expire_due();
            return array('checked' => 0, 'paid' => 0, 'review' => 0, 'errors' => 0, 'closed' => $closed);
        }

        $groups = array();
        foreach ($invoices as $invoice) {
            $groups[$invoice->receive_address][] = $invoice;
        }

        $stats = array('checked' => 0, 'paid' => 0, 'review' => 0, 'errors' => 0, 'closed' => 0);
        foreach ($groups as $address => $address_invoices) {
            $earliest = time();
            foreach ($address_invoices as $invoice) {
                $earliest = min($earliest, JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->created_at));
            }

            $transfers = $this->trongrid->get_transfers($address, max(0, ($earliest - 5) * 1000), time() * 1000, 5);
            if (is_wp_error($transfers)) {
                $stats['errors']++;
                $this->db->log(
                    'cron_chain_check_failed',
                    $transfers->get_error_message(),
                    0,
                    'error',
                    array('address' => JIULIU_USDT_Util::mask_address($address), 'error_code' => $transfers->get_error_code())
                );
                continue;
            }

            $by_raw = array();
            foreach ($address_invoices as $invoice) {
                $by_raw[JIULIU_USDT_Util::normalize_raw($invoice->expected_raw)] = $invoice;
                $this->db->update_invoice($invoice->id, array('last_checked_at' => JIULIU_USDT_Util::utc_now_mysql()));
                $stats['checked']++;
            }

            foreach ($transfers as $transfer) {
                $raw = JIULIU_USDT_Util::normalize_raw($transfer['value']);
                if (!isset($by_raw[$raw])) {
                    continue;
                }

                $invoice = $by_raw[$raw];
                $result = $this->process_transfer($invoice, $transfer, false);
                if (!is_wp_error($result) && is_object($result)) {
                    if ('paid' === $result->status) {
                        $stats['paid']++;
                    } elseif ('review' === $result->status) {
                        $stats['review']++;
                    }
                }
                unset($by_raw[$raw]);
            }
        }

        // Scan first so a transfer confirmed at or before the deadline can be
        // settled before Zibll runs its native lazy timeout/stock restoration.
        $stats['closed'] = $this->sync_zibll_expirations();
        $this->db->expire_due();
        return $stats;
    }

    private function sync_zibll_expirations()
    {
        if (
            !class_exists('ZibPay')
            || !is_callable(array('ZibPay', 'get_payment'))
            || !function_exists('zibpay_get_payment_pay_over_time')
        ) {
            return 0;
        }

        $closed = 0;
        foreach ($this->db->payment_ids_due_for_zibll_close(500) as $payment_id) {
            $payment = ZibPay::get_payment($payment_id);
            if (!$payment || !isset($payment['status']) || '0' !== (string) $payment['status']) {
                continue;
            }

            // Zibll closes the parent and every pending child itself, fires
            // order_closed, and restores Shop stock through its native hooks.
            if ('over' === zibpay_get_payment_pay_over_time($payment)) {
                $closed++;
                $this->db->log(
                    'zibll_payment_timeout_closed',
                    __('已由子比原生逻辑关闭超时支付单并同步库存。', 'jiuliu-usdt-payment'),
                    0,
                    'info',
                    array('payment_id' => $payment_id)
                );
            }
        }

        return $closed;
    }

    private function process_transfer($invoice, $transfer, $force = false, $source = 'auto')
    {
        $txid = isset($transfer['transaction_id']) ? strtolower($transfer['transaction_id']) : '';
        if (!JIULIU_USDT_Util::is_valid_txid($txid)) {
            return new WP_Error('invalid_chain_txid', __('链上记录的交易哈希无效。', 'jiuliu-usdt-payment'));
        }

        $used = $this->db->get_by_txid($txid);
        if ($used && (int) $used->id !== (int) $invoice->id) {
            return new WP_Error('txid_already_used', __('交易哈希已经被其他支付单使用。', 'jiuliu-usdt-payment'));
        }

        $actual_raw = JIULIU_USDT_Util::normalize_raw($transfer['value']);
        $expected_raw = JIULIU_USDT_Util::normalize_raw($invoice->expected_raw);
        $block_time = !empty($transfer['block_timestamp']) ? floor($transfer['block_timestamp'] / 1000) : 0;
        $created = JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->created_at);
        $expires = JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->expires_at);
        $amount_matches = ($actual_raw === $expected_raw);
        $time_matches = $block_time >= ($created - 5) && $block_time <= $expires;

        // A public/manual mismatch is only a claim for administrator review;
        // it must never reserve the globally unique settlement txid. Without
        // this split, anyone holding their own invoice token can watch the
        // public receiver and bind another customer's transaction first.
        if ('public' === $source && !$force && (!$amount_matches || !$time_matches)) {
            $code = !$amount_matches ? 'amount_mismatch' : 'late_payment';
            $note = !$amount_matches
                ? sprintf(__('已记录交易哈希：实收 %1$s USDT，应收 %2$s USDT；交易尚未绑定支付单，等待管理员核对。', 'jiuliu-usdt-payment'), JIULIU_USDT_Util::raw_to_decimal($actual_raw, 6), $invoice->usdt_amount)
                : __('已记录交易哈希：链上时间晚于订单有效期；交易尚未绑定支付单，等待管理员核对。', 'jiuliu-usdt-payment');

            $current = $this->db->get_invoice($invoice->id);
            if (!$current || in_array($current->status, array('processing', 'paid', 'rejected'), true)) {
                return new WP_Error('invoice_state_changed', __('支付单状态已经变化，请刷新页面后重试。', 'jiuliu-usdt-payment'));
            }
            if (!$this->db->record_unbound_submission($invoice->id, $txid, $transfer, $note)) {
                return new WP_Error('txid_submission_failed', __('交易哈希记录失败，请稍后重试或联系管理员。', 'jiuliu-usdt-payment'));
            }

            $this->db->log(
                'payment_submission_unbound',
                $note,
                $invoice->id,
                'warning',
                array('txid' => $txid, 'reason' => $code),
                get_current_user_id()
            );

            $review = $this->db->get_invoice($invoice->id);
            if ($review) {
                // Keep the database settlement txid NULL. These temporary
                // values only make the existing AJAX/admin-email response clear.
                $review->status = 'review';
                $review->txid = $txid;
                $review->note = $note;
                $this->notify_admin_review($review);
                return $review;
            }

            return new WP_Error('invoice_not_found', __('支付单不存在。', 'jiuliu-usdt-payment'));
        }

        $original_status = (string) $invoice->status;
        $claimable_statuses = array('pending', 'expired', 'superseded', 'closed', 'closed_no_monitor', 'review');
        if (
            !in_array($original_status, $claimable_statuses, true)
            || !$this->db->claim_invoice($invoice->id, $txid, $transfer, array($original_status))
        ) {
            $current = $this->db->get_invoice($invoice->id);
            if ($current && 'paid' === $current->status && $current->txid === $txid) {
                return $current;
            }
            return new WP_Error('invoice_claim_failed', __('支付单正在处理或交易哈希已被占用。', 'jiuliu-usdt-payment'));
        }

        // Closed and superseded quotes may still receive funds from a stale QR
        // code. Bind the transfer for audit/replay protection, but never reopen
        // or fulfil the underlying Zibll order—even for an administrator force.
        if (in_array($original_status, array('closed', 'closed_no_monitor', 'superseded'), true)) {
            $note = __('交易已确认，但支付单在到账前已经关闭或被替代；禁止自动发货，等待管理员处理。', 'jiuliu-usdt-payment');
            $review = $this->transition_processing_to_review($invoice, $txid, 'inactive_invoice_payment', $note);
            if (is_wp_error($review)) {
                return $review;
            }
            $this->db->log(
                'inactive_invoice_payment',
                $note,
                $invoice->id,
                'warning',
                array('txid' => $txid, 'original_status' => $original_status, 'source' => $source)
            );
            $this->notify_admin_review($review);
            return $review;
        }

        if (!$force && (!$amount_matches || !$time_matches)) {
            $code = !$amount_matches ? 'amount_mismatch' : 'late_payment';
            $note = !$amount_matches
                ? sprintf(__('实收 %1$s USDT，应收 %2$s USDT，等待管理员处理。', 'jiuliu-usdt-payment'), JIULIU_USDT_Util::raw_to_decimal($actual_raw, 6), $invoice->usdt_amount)
                : __('交易已确认，但链上时间晚于订单有效期，等待管理员处理。', 'jiuliu-usdt-payment');

            $review = $this->transition_processing_to_review($invoice, $txid, $code, $note);
            if (is_wp_error($review)) {
                return $review;
            }
            $this->db->log('payment_needs_review', $note, $invoice->id, 'warning', array('txid' => $txid));
            $this->notify_admin_review($review);
            return $review;
        }

        return $this->settle_zibll($this->db->get_invoice($invoice->id), $txid, $force);
    }

    private function transition_processing_to_review($invoice, $txid, $error_code, $note)
    {
        if ($this->db->mark_processing_review($invoice->id, $txid, $error_code, $note)) {
            return $this->db->get_invoice($invoice->id);
        }

        $current = $this->db->get_invoice($invoice->id);
        if (
            $current
            && 'review' === $current->status
            && strtolower((string) $current->txid) === strtolower((string) $txid)
        ) {
            // The stale-processing recovery job may already have moved this
            // exact owner to review. Do not overwrite its diagnostic reason.
            return $current;
        }

        $this->db->log(
            'processing_owner_changed',
            __('结算处理权已经变化，旧进程未修改支付单状态。', 'jiuliu-usdt-payment'),
            $invoice->id,
            'warning',
            array(
                'expected_txid' => strtolower((string) $txid),
                'current_status'=> $current ? $current->status : 'missing',
                'current_txid'  => $current ? strtolower((string) $current->txid) : '',
            )
        );

        return new WP_Error('invoice_processing_owner_changed', __('支付单已由另一个结算进程接管，请刷新状态后再处理。', 'jiuliu-usdt-payment'));
    }

    private function settle_zibll($invoice, $txid, $force)
    {
        if (!class_exists('ZibPay') || !is_callable(array('ZibPay', 'payment_order'))) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_api_missing',
                __('找不到子比统一结算接口。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            return new WP_Error('zibll_api_missing', __('找不到子比统一结算接口，请管理员检查主题兼容状态。', 'jiuliu-usdt-payment'));
        }

        // payment_id is stable. Zibll regenerates the 520... payment order number
        // every time the cashier is initiated, so never use the saved number as
        // the primary lookup key here.
        $payment = is_callable(array('ZibPay', 'get_payment')) ? ZibPay::get_payment($invoice->payment_id) : array();
        if (!$payment) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_payment_missing',
                __('对应的子比支付单不存在。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            return new WP_Error('zibll_payment_missing', __('对应的子比支付单不存在，已转人工处理。', 'jiuliu-usdt-payment'));
        }

        if (
            empty($payment['order_num'])
            || (string) $payment['order_num'] !== (string) $invoice->zibll_order_num
            || empty($payment['method'])
            || 'usdt_trc20' !== (string) $payment['method']
        ) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_payment_changed',
                __('用户重新发起或切换了支付方式；旧 USDT 支付单到账需人工处理。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->notify_admin_review($review);
            return new WP_Error('zibll_payment_changed', __('支付方式已经变更，USDT 到账已转人工处理。', 'jiuliu-usdt-payment'));
        }

        $invoice_cents = (int) round((float) $invoice->local_amount * 100);
        $payment_cents = isset($payment['price']) ? (int) round((float) $payment['price'] * 100) : -1;
        if ($invoice_cents <= 0 || $invoice_cents !== $payment_cents) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_price_changed',
                __('USDT 报价对应的本地金额与当前子比主支付单不一致，禁止自动结算。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->notify_admin_review($review);
            return new WP_Error('zibll_price_changed', __('订单金额已经变化，USDT 到账已转人工处理。', 'jiuliu-usdt-payment'));
        }

        if (isset($payment['status']) && '1' === (string) $payment['status']) {
            if (isset($payment['pay_num']) && strtolower((string) $payment['pay_num']) === $txid) {
                if (!$this->children_are_fully_completed($invoice->payment_id)) {
                    $review = $this->transition_processing_to_review(
                        $invoice,
                        $txid,
                        'zibll_child_settlement_incomplete',
                        __('子比主支付单已支付，但至少一个子订单或成功回调未完整执行，请人工核对权益与库存。', 'jiuliu-usdt-payment')
                    );
                    if (is_wp_error($review)) {
                        return $review;
                    }
                    $this->notify_admin_review($review);
                    return new WP_Error('zibll_child_settlement_incomplete', __('链上已到账，部分站内权益正在人工核对。', 'jiuliu-usdt-payment'));
                }
                if (!$this->mark_paid($invoice, $txid, $force)) {
                    return new WP_Error('invoice_paid_transition_failed', __('子比订单已完成，但插件支付单状态写入失败，请人工核对。', 'jiuliu-usdt-payment'));
                }
                return $this->db->get_invoice($invoice->id);
            }

            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_already_paid',
                __('子比支付单已经通过其他支付记录完成，USDT 到账需人工处理。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->notify_admin_review($review);
            return new WP_Error('zibll_already_paid', __('订单已通过其他方式支付，USDT 到账已转人工处理。', 'jiuliu-usdt-payment'));
        }

        // Zibll 9.0 accepts a closed parent payment in payment_payment(), but it
        // only completes child orders whose status is still 0. Never auto-settle
        // a closed/mixed group: shop stock may already have been restored.
        if (!isset($payment['status']) || '0' !== (string) $payment['status']) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_payment_closed',
                __('子比主支付单已关闭，禁止自动补发（商城库存可能已恢复）。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->notify_admin_review($review);
            return new WP_Error('zibll_payment_closed', __('订单已关闭，USDT 到账已转人工处理。', 'jiuliu-usdt-payment'));
        }

        $child_orders = is_callable(array('ZibPay', 'get_order_by_payment_id'))
            ? ZibPay::get_order_by_payment_id($invoice->payment_id, 'id,status,pay_price')
            : array();
        if (!$child_orders) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_children_missing',
                __('找不到子比关联子订单。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            return new WP_Error('zibll_children_missing', __('找不到关联订单，已转人工处理。', 'jiuliu-usdt-payment'));
        }
        $child_total_cents = 0;
        foreach ($child_orders as $child_order) {
            if (!isset($child_order['status']) || '0' !== (string) $child_order['status']) {
                $review = $this->transition_processing_to_review(
                    $invoice,
                    $txid,
                    'zibll_child_not_pending',
                    __('至少一个子比关联订单不是待支付状态，禁止自动补发。', 'jiuliu-usdt-payment')
                );
                if (is_wp_error($review)) {
                    return $review;
                }
                $this->notify_admin_review($review);
                return new WP_Error('zibll_child_not_pending', __('关联订单状态已变化，USDT 到账已转人工处理。', 'jiuliu-usdt-payment'));
            }
            $child_total_cents += isset($child_order['pay_price']) ? (int) round((float) $child_order['pay_price'] * 100) : 0;
        }
        if ($child_total_cents !== $payment_cents) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_child_price_mismatch',
                __('子比关联子订单应付合计与主支付单不一致，禁止自动结算。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->notify_admin_review($review);
            return new WP_Error('zibll_child_price_mismatch', __('关联订单金额异常，USDT 到账已转人工处理。', 'jiuliu-usdt-payment'));
        }

        $result = ZibPay::payment_order(array(
            'order_num' => $payment['order_num'],
            'pay_type'  => 'usdt_trc20',
            'pay_num'   => $txid,
        ));

        if (!$result) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_settlement_failed',
                __('链上已到账，但子比订单结算失败。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->db->log(
                'zibll_settlement_failed',
                __('链上已到账，但子比订单结算失败。', 'jiuliu-usdt-payment'),
                $invoice->id,
                'error',
                array('txid' => $txid)
            );
            $this->notify_admin_review($review);
            return new WP_Error('zibll_settlement_failed', __('链上已到账，站内权益正在人工补发。', 'jiuliu-usdt-payment'));
        }

        if (!$this->children_are_fully_completed($invoice->payment_id)) {
            $review = $this->transition_processing_to_review(
                $invoice,
                $txid,
                'zibll_child_settlement_incomplete',
                __('子比主支付单已更新，但至少一个子订单或成功回调未完整执行，请人工核对权益与库存。', 'jiuliu-usdt-payment')
            );
            if (is_wp_error($review)) {
                return $review;
            }
            $this->notify_admin_review($review);
            return new WP_Error('zibll_child_settlement_incomplete', __('链上已到账，部分站内权益正在人工核对。', 'jiuliu-usdt-payment'));
        }

        if (!$this->mark_paid($invoice, $txid, $force)) {
            return new WP_Error('invoice_paid_transition_failed', __('子比订单已完成，但插件支付单状态写入失败，请人工核对。', 'jiuliu-usdt-payment'));
        }
        return $this->db->get_invoice($invoice->id);
    }

    private function children_are_fully_completed($payment_id)
    {
        if (!is_callable(array('ZibPay', 'get_order_by_payment_id')) || !is_callable(array('ZibPay', 'get_meta'))) {
            return false;
        }

        $children = ZibPay::get_order_by_payment_id($payment_id, 'id,status');
        if (!$children) {
            return false;
        }

        foreach ($children as $child) {
            if (
                empty($child['id'])
                || !isset($child['status'])
                || '1' !== (string) $child['status']
                || !ZibPay::get_meta($child['id'], 'jiuliu_usdt_success_hooks_completed')
            ) {
                return false;
            }
        }

        return true;
    }

    private function mark_paid($invoice, $txid, $force)
    {
        $note = $force ? __('管理员核验后补单完成。', 'jiuliu-usdt-payment') : __('链上确认并自动结算完成。', 'jiuliu-usdt-payment');
        if (!$this->db->mark_invoice_paid($invoice->id, $txid, $note)) {
            $current = $this->db->get_invoice($invoice->id);
            if ($current && 'paid' === $current->status && strtolower((string) $current->txid) === strtolower((string) $txid)) {
                // Another worker completed the state transition and owns the
                // one-time notification side effects.
                return true;
            }

            $this->db->mark_processing_review(
                $invoice->id,
                $txid,
                'invoice_paid_transition_failed',
                __('子比权益已完成，但插件支付单未能原子切换为已支付，请人工核对。', 'jiuliu-usdt-payment')
            );
            $this->db->log(
                'invoice_paid_transition_failed',
                __('插件支付单的已支付状态写入失败。', 'jiuliu-usdt-payment'),
                $invoice->id,
                'error',
                array('txid' => $txid)
            );
            return false;
        }

        $invoice = $this->db->get_invoice($invoice->id);
        $this->db->log(
            $force ? 'payment_force_completed' : 'payment_completed',
            $force ? __('管理员补单完成。', 'jiuliu-usdt-payment') : __('USDT 链上支付确认并结算完成。', 'jiuliu-usdt-payment'),
            $invoice->id,
            'info',
            array('txid' => $txid, 'amount' => $invoice->actual_amount),
            get_current_user_id()
        );

        $this->send_paid_emails($invoice);
        do_action('jiuliu_usdt_payment_completed', $invoice);
        return true;
    }

    private function send_paid_emails($invoice)
    {
        $site_name = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
        $subject = sprintf(__('[%s] USDT 支付成功', 'jiuliu-usdt-payment'), $site_name);
        $message = sprintf(
            __("USDT-TRC20 支付已确认。\n\n站内订单：%1\$s\nUSDT 金额：%2\$s\n交易哈希：%3\$s\n状态：子比订单已完成。", 'jiuliu-usdt-payment'),
            $invoice->zibll_order_num,
            $invoice->actual_amount ?: $invoice->usdt_amount,
            $invoice->txid
        );

        if ($this->settings->get('admin_email_notifications')) {
            $admin_email = get_option('admin_email');
            if (is_email($admin_email)) {
                wp_mail($admin_email, $subject, $message);
            }
        }

        if ($this->settings->get('user_email_notifications') && $invoice->user_id) {
            $user = get_userdata($invoice->user_id);
            if ($user && is_email($user->user_email)) {
                wp_mail($user->user_email, $subject, $message);
            }
        }
    }

    private function notify_admin_review($invoice)
    {
        if (!$this->settings->get('admin_email_notifications')) {
            return;
        }

        $admin_email = get_option('admin_email');
        if (!is_email($admin_email)) {
            return;
        }

        $subject = sprintf(
            __('[%s] USDT 到账需要人工处理', 'jiuliu-usdt-payment'),
            wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES)
        );
        $message = sprintf(
            __("USDT 支付单进入人工处理。\n\n插件订单：%1\$s\n站内订单：%2\$s\n状态原因：%3\$s\n交易哈希：%4\$s\n\n请登录 WordPress 后台的“USDT 收款”页面处理。", 'jiuliu-usdt-payment'),
            $invoice->invoice_no,
            $invoice->zibll_order_num,
            $invoice->note,
            $invoice->txid
        );
        wp_mail($admin_email, $subject, $message);
    }

    public function status_label($status)
    {
        $labels = array(
            'pending'    => __('等待付款', 'jiuliu-usdt-payment'),
            'processing' => __('正在结算', 'jiuliu-usdt-payment'),
            'paid'       => __('支付成功', 'jiuliu-usdt-payment'),
            'expired'    => __('已过期', 'jiuliu-usdt-payment'),
            'closed'     => __('子比已关闭（继续观察）', 'jiuliu-usdt-payment'),
            'closed_no_monitor' => __('子比已关闭（停止观察）', 'jiuliu-usdt-payment'),
            'superseded' => __('已被新支付单替代', 'jiuliu-usdt-payment'),
            'review'     => __('需要人工处理', 'jiuliu-usdt-payment'),
            'rejected'   => __('已拒绝', 'jiuliu-usdt-payment'),
        );
        return isset($labels[$status]) ? $labels[$status] : $status;
    }
}
