<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Zibll
{
    const METHOD = 'usdt_trc20';
    const SDK = 'jiuliu_usdt_trc20';

    private $settings;
    private $db;
    private $invoices;
    private $registered = false;
    private $created_order_ids = array();
    private $initiate_payment_locks = array();

    public function __construct(JIULIU_USDT_Settings $settings, JIULIU_USDT_DB $db, JIULIU_USDT_Invoices $invoices)
    {
        $this->settings = $settings;
        $this->db = $db;
        $this->invoices = $invoices;
    }

    public function register()
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;

        add_filter('zibpay_payment_methods', array($this, 'add_payment_method'), 30, 2);
        add_filter('zibpay_initiate_paysdk', array($this, 'route_payment_sdk'), 30, 2);
        add_filter('zibpay_initiate_' . self::SDK, array($this, 'initiate_payment'), 10, 1);
        add_filter('pre_order_create_data', array($this, 'normalize_order_before_create'), 1, 1);
        add_action('order_created', array($this, 'remember_created_order'), 1, 1);
        add_action('order_closed', array($this, 'handle_order_closed'), 20, 3);

        // Zibll refreshes payment.order_num (and may change method) before it
        // reaches the gateway filter, without a status predicate. Serialize the
        // whole initiate request with final settlement so a stale cashier
        // request cannot overwrite a just-paid parent row.
        add_action('wp_ajax_initiate_pay', array($this, 'lock_initiate_payment'), 6);
        add_action('wp_ajax_nopriv_initiate_pay', array($this, 'lock_initiate_payment'), 6);
        add_action('shutdown', array($this, 'release_initiate_payment_locks'), PHP_INT_MAX);

        add_filter('user_order_details_footer_left', array($this, 'append_order_proof_footer'), 30, 2);
        add_filter('user_order_details_modal', array($this, 'append_shop_order_proof'), 99, 3);

        // Must run before Zibll's priority 5+ income, rebate, mail and delivery
        // listeners. Zibll 9.0 hard-codes valid cash detail keys and would
        // otherwise calculate a custom gateway's effective amount as zero.
        add_action('payment_order_success', array($this, 'normalize_paid_order'), 1, 1);
        add_action('payment_order_success', array($this, 'mark_success_hooks_completed'), PHP_INT_MAX, 1);

        add_action('wp_ajax_check_pay', array($this, 'preflight_check_pay'), 5);
        add_action('wp_ajax_nopriv_check_pay', array($this, 'preflight_check_pay'), 5);
        add_action('wp_enqueue_scripts', array($this, 'enqueue_assets'), 30);
        add_action('admin_notices', array($this, 'compatibility_notice'));

        add_filter('plugin_action_links_' . plugin_basename(JIULIU_USDT_FILE), array($this, 'plugin_action_links'));
    }

    public function add_payment_method($methods, $pay_type)
    {
        if (
            !$this->settings->is_enabled()
            || !$this->is_zibll_template()
            || is_wp_error($this->db->settlement_tables_are_transactional())
        ) {
            return $methods;
        }

        $methods[self::METHOD] = array(
            'name' => __('USDT', 'jiuliu-usdt-payment'),
            'img'  => '<img class="jiuliu-usdt-method-logo" src="' . esc_url(JIULIU_USDT_URL . 'assets/img/usdt-trc20.svg') . '" alt="USDT-TRC20">',
        );
        return $methods;
    }

    public function route_payment_sdk($pay_sdk, $order_data)
    {
        if (!empty($order_data['payment_method']) && self::METHOD === $order_data['payment_method']) {
            return self::SDK;
        }
        return $pay_sdk;
    }

    public function initiate_payment($order_data)
    {
        $transactional_tables = $this->db->settlement_tables_are_transactional();
        if (is_wp_error($transactional_tables)) {
            return array(
                'error' => 1,
                'msg'   => $transactional_tables->get_error_message(),
            );
        }

        $authorized = $this->authorize_initiate($order_data);
        if (is_wp_error($authorized)) {
            return array(
                'error' => 1,
                'msg'   => $authorized->get_error_message(),
            );
        }

        $response = $this->invoices->build_zibll_response($order_data);
        if (!is_array($response) || !empty($response['error'])) {
            return $response;
        }

        // Zibll regenerates the parent 520... number on every initiate call.
        // Return the value that is current after invoice creation so pay.js
        // polls the same number that our invoice table is bound to.
        $payment_id = isset($order_data['payment_id']) ? absint($order_data['payment_id']) : 0;
        $payment = $payment_id ? ZibPay::get_payment($payment_id) : false;
        if (
            !$payment
            || empty($payment['order_num'])
            || empty($payment['method'])
            || self::METHOD !== (string) $payment['method']
        ) {
            return array(
                'error' => 1,
                'msg'   => __('子比支付方式已经变化，请重新打开收银台。', 'jiuliu-usdt-payment'),
            );
        }

        $response['order_num'] = sanitize_text_field($payment['order_num']);
        return $response;
    }

    /**
     * Remember orders created in this request. Guest cookies are sent in the
     * response headers and are not available in $_COOKIE until the next HTTP
     * request, so this provides a narrow first-request authorization path.
     */
    public function remember_created_order($order)
    {
        $order = (array) $order;
        if (!empty($order['id'])) {
            $this->created_order_ids[absint($order['id'])] = true;
        }
    }

    public function lock_initiate_payment()
    {
        $raw_payment_id = isset($_REQUEST['payment_id']) ? wp_unslash($_REQUEST['payment_id']) : '';
        $payment_id = is_scalar($raw_payment_id) ? absint($raw_payment_id) : 0;
        if (!$payment_id || !empty($this->initiate_payment_locks[$payment_id])) {
            return;
        }

        // Terminal orders are safe to leave to the theme because it returns
        // them before refreshing order_num. Every pending initiate request is
        // serialized, including other->other: otherwise such a request could
        // race an other->USDT transition while the latter holds our lock.
        if (!class_exists('ZibPay') || !is_callable(array('ZibPay', 'get_payment'))) {
            return;
        }
        $raw_requested_method = isset($_REQUEST['payment_method']) ? wp_unslash($_REQUEST['payment_method']) : '';
        $requested_method = is_scalar($raw_requested_method) ? sanitize_key($raw_requested_method) : '';
        $payment = (array) ZibPay::get_payment($payment_id);
        if (!$payment || !isset($payment['status']) || '0' !== (string) $payment['status']) {
            return;
        }
        if (!$this->db->acquire_settlement_lock($payment_id, 10)) {
            wp_send_json_error(array(
                'code' => 'jiuliu_usdt_payment_busy',
                'msg'  => __('该订单正在确认 USDT 到账，请稍后刷新，暂时不要切换支付方式。', 'jiuliu-usdt-payment'),
            ), 409);
            return;
        }

        $this->initiate_payment_locks[$payment_id] = true;

        // Zibll's AJAX handler refreshes order_num and may switch method before
        // it reaches our gateway filter. Its own handler does not verify order
        // ownership first, so protect every request entering or leaving USDT
        // while the settlement lock is held.
        $payment = (array) ZibPay::get_payment($payment_id);
        if (!$payment || !isset($payment['status']) || '0' !== (string) $payment['status']) {
            $this->db->release_settlement_lock($payment_id);
            unset($this->initiate_payment_locks[$payment_id]);
            return;
        }

        $current_method = isset($payment['method']) ? sanitize_key((string) $payment['method']) : '';
        if (self::METHOD !== $current_method && self::METHOD !== $requested_method) {
            // Keep the lifecycle lock until shutdown so a concurrent request
            // cannot enter USDT while this theme handler mutates the parent.
            return;
        }

        $authorized = $this->authorize_initiate(array('payment_id' => $payment_id));
        if (is_wp_error($authorized)) {
            wp_send_json_error(array(
                'code' => 'jiuliu_usdt_payment_forbidden',
                'msg'  => $authorized->get_error_message(),
            ), 403);
        }
    }

    public function release_initiate_payment_locks()
    {
        foreach (array_keys($this->initiate_payment_locks) as $payment_id) {
            $this->db->release_settlement_lock($payment_id);
        }
        $this->initiate_payment_locks = array();
    }

    private function authorize_initiate($order_data)
    {
        if (!is_array($order_data) || empty($order_data['payment_id'])) {
            return new WP_Error('zibll_payment_forbidden', __('支付单校验失败，请从原订单页面重新打开收银台。', 'jiuliu-usdt-payment'));
        }

        $payment_id = absint($order_data['payment_id']);
        $orders = ZibPay::get_order_by_payment_id(
            $payment_id,
            'id,user_id,post_id,order_num,order_price,ip_address,status'
        );
        if (!$orders || !is_array($orders)) {
            return new WP_Error('zibll_payment_forbidden', __('支付单校验失败，请从原订单页面重新打开收银台。', 'jiuliu-usdt-payment'));
        }

        $current_user_id = get_current_user_id();
        foreach ($orders as $order) {
            $order = (array) $order;
            $order_id = !empty($order['id']) ? absint($order['id']) : 0;
            $owner_id = !empty($order['user_id']) ? absint($order['user_id']) : 0;

            if (!$order_id || !isset($order['status']) || '0' !== (string) $order['status']) {
                return new WP_Error('zibll_payment_forbidden', __('支付单已经失效，请从原订单页面重新打开收银台。', 'jiuliu-usdt-payment'));
            }

            if ($owner_id) {
                if (!$current_user_id || $current_user_id !== $owner_id) {
                    return new WP_Error('zibll_payment_forbidden', __('无权发起此支付单，请从原订单页面重新打开收银台。', 'jiuliu-usdt-payment'));
                }
                continue;
            }

            if ($this->is_guest_order_created_in_this_request($order_id)) {
                continue;
            }

            if (
                !function_exists('zibpay_posts_order_check_cookie_token')
                || !zibpay_posts_order_check_cookie_token($order)
            ) {
                return new WP_Error('zibll_payment_forbidden', __('无权发起此支付单，请从原订单页面重新打开收银台。', 'jiuliu-usdt-payment'));
            }
        }

        return true;
    }

    private function is_guest_order_created_in_this_request($order_id)
    {
        $doing_ajax = function_exists('wp_doing_ajax') ? wp_doing_ajax() : (defined('DOING_AJAX') && DOING_AJAX);
        $action = isset($_REQUEST['action']) ? sanitize_key(wp_unslash($_REQUEST['action'])) : '';

        return $doing_ajax
            && in_array($action, array('submit_order', 'shop_submit_order'), true)
            && !empty($this->created_order_ids[absint($order_id)]);
    }

    public function handle_order_closed($order_id, $type = 'timeout', $reason = '')
    {
        $order_id = absint($order_id);
        if (!$order_id || !is_callable(array('ZibPay', 'get_order'))) {
            return;
        }

        $order = ZibPay::get_order($order_id, 'payment_id');
        if (empty($order['payment_id']) || !is_callable(array($this->db, 'close_payment_invoices'))) {
            return;
        }

        $monitor = (bool) $this->settings->get('monitor_closed_orders', 1);
        $type = sanitize_key((string) $type);
        $reason = sanitize_text_field((string) $reason);
        $this->db->close_payment_invoices(
            absint($order['payment_id']),
            $monitor,
            $type ? $type : 'closed',
            $reason
        );
    }

    public function append_order_proof_footer($footer_left, $order)
    {
        $proof = $this->get_order_proof_html($order, false);
        if (!$proof) {
            return $footer_left;
        }

        return $footer_left . $proof;
    }

    public function append_shop_order_proof($html, $order_type, $order)
    {
        // Zibll Shop (type 10) returns a complete modal at priority 10 and
        // therefore never reaches the default footer filters.
        if (!$html || '10' !== (string) $order_type) {
            return $html;
        }

        $proof = $this->get_order_proof_html($order, true);
        if (!$proof) {
            return $html;
        }

        $footer_needle = '</div><div class="flex ac jsb modal-full-footer">';
        $position = strrpos($html, $footer_needle);
        if (false === $position) {
            return $html . $proof;
        }

        // Insert before the mini-scrollbar's closing tag so the proof remains
        // readable on mobile without displacing the Shop action buttons.
        return substr_replace($html, $proof, $position, 0);
    }

    private function get_order_proof_html($order, $panel)
    {
        $order = (array) $order;
        if (
            empty($order['id'])
            || empty($order['pay_type'])
            || self::METHOD !== (string) $order['pay_type']
            || !is_callable(array('ZibPay', 'get_meta'))
        ) {
            return '';
        }

        $proof = ZibPay::get_meta(absint($order['id']), 'jiuliu_usdt_payment', array());
        if (!is_array($proof)) {
            return '';
        }

        $txid = !empty($proof['txid']) ? strtolower(trim((string) $proof['txid'])) : '';
        if (!JIULIU_USDT_Util::is_valid_txid($txid)) {
            return '';
        }

        $network = !empty($proof['network']) ? sanitize_text_field($proof['network']) : 'TRC20';
        $amount = isset($proof['usdt_amount']) ? trim((string) $proof['usdt_amount']) : '';
        if (!preg_match('/^[0-9]+(?:\.[0-9]{1,6})?$/D', $amount)) {
            $amount = '';
        }

        $invoice_no = !empty($proof['invoice_no']) ? sanitize_text_field($proof['invoice_no']) : '';
        $short_txid = substr($txid, 0, 8) . '&hellip;' . substr($txid, -8);
        $tx_url = 'https://tronscan.org/#/transaction/' . rawurlencode($txid);

        $details = '<span class="jiuliu-usdt-order-proof-network">USDT · ' . esc_html($network) . '</span>';
        if ($amount) {
            $details .= '<span>' . esc_html($amount) . ' USDT</span>';
        }
        if ($invoice_no) {
            $details .= '<span>' . esc_html__('凭证', 'jiuliu-usdt-payment') . ' ' . esc_html($invoice_no) . '</span>';
        }
        $details .= '<a href="' . esc_url($tx_url) . '" target="_blank" rel="noopener noreferrer">'
            . esc_html__('交易', 'jiuliu-usdt-payment') . ' <code>' . $short_txid . '</code></a>';

        if ($panel) {
            return '<div class="jiuliu-usdt-order-proof-panel zib-widget">'
                . '<div class="jiuliu-usdt-order-proof-title">' . esc_html__('USDT 链上凭证', 'jiuliu-usdt-payment') . '</div>'
                . '<div class="jiuliu-usdt-order-proof">' . $details . '</div>'
                . '</div>';
        }

        return '<span class="jiuliu-usdt-order-proof jiuliu-usdt-order-proof-footer">' . $details . '</span>';
    }

    public function normalize_order_before_create($order_data)
    {
        if (empty($order_data['pay_detail'])) {
            return $order_data;
        }

        $pay_detail = maybe_unserialize($order_data['pay_detail']);
        if (is_array($pay_detail) && array_key_exists(self::METHOD, $pay_detail)) {
            // All detailed quote/discount data remains available in Zibll's
            // order_data meta and in our invoice table. An empty core detail
            // intentionally makes Zibll fall back to the correct pay_price.
            $order_data['pay_detail'] = '';
        }
        return $order_data;
    }

    public function normalize_paid_order($order)
    {
        if (!is_object($order) || empty($order->id) || empty($order->pay_type) || self::METHOD !== $order->pay_type) {
            return;
        }

        global $wpdb;
        $original = isset($order->pay_detail) ? maybe_unserialize($order->pay_detail) : array();
        $invoice = !empty($order->pay_num) ? $this->db->get_by_txid($order->pay_num) : null;

        if (class_exists('ZibPay') && is_callable(array('ZibPay', 'update_meta'))) {
            ZibPay::update_meta($order->id, 'jiuliu_usdt_payment', array(
                'invoice_no'  => $invoice ? $invoice->invoice_no : '',
                'network'     => 'TRC20',
                'usdt_amount' => $invoice ? $invoice->actual_amount : '',
                'rate'        => $invoice ? $invoice->rate : '',
                'txid'        => isset($order->pay_num) ? $order->pay_num : '',
                'core_detail' => is_array($original) ? $original : array(),
            ));
        }

        $wpdb->update(
            $wpdb->zibpay_order,
            array('pay_detail' => ''),
            array('id' => absint($order->id))
        );
        $order->pay_detail = '';
    }

    public function mark_success_hooks_completed($order)
    {
        if (
            !is_object($order)
            || empty($order->id)
            || empty($order->pay_type)
            || self::METHOD !== $order->pay_type
            || !class_exists('ZibPay')
            || !is_callable(array('ZibPay', 'update_meta'))
        ) {
            return;
        }

        // This marker is written only after every earlier Zibll success hook
        // returned normally. It lets recovery distinguish a fully delivered
        // order from a parent payment that became status=1 before a PHP fatal.
        ZibPay::update_meta($order->id, 'jiuliu_usdt_success_hooks_completed', JIULIU_USDT_Util::utc_now_mysql());
    }

    public function preflight_check_pay()
    {
        if ($this->settings->get('pause_monitoring', 0)) {
            return;
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD']))) : '';
        $action = isset($_POST['action']) ? sanitize_key(wp_unslash($_POST['action'])) : '';
        if ('POST' !== $method || 'check_pay' !== $action) {
            return;
        }

        $check_sdk = isset($_POST['check_sdk']) ? sanitize_key(wp_unslash($_POST['check_sdk'])) : '';
        if (self::SDK !== $check_sdk) {
            return;
        }

        $order_num = isset($_POST['order_num']) ? sanitize_text_field(wp_unslash($_POST['order_num'])) : '';
        if (!$order_num) {
            return;
        }

        $invoice = $this->db->get_by_order_num($order_num);
        $allowed_statuses = array('pending', 'expired');
        if ($this->settings->get('monitor_closed_orders', 1)) {
            $allowed_statuses[] = 'closed';
        }
        if (!$invoice || !in_array($invoice->status, $allowed_statuses, true)) {
            return;
        }

        if (!empty($invoice->last_checked_at)) {
            $last_checked = JIULIU_USDT_Util::utc_timestamp_from_mysql($invoice->last_checked_at);
            if ($last_checked > time() - 8) {
                return;
            }
        }

        if (JIULIU_USDT_Trongrid::backoff_remaining() > 0 || !$this->consume_preflight_scan_budget()) {
            return;
        }

        // Do not emit a response here. Zibll's own handler runs immediately
        // afterwards and returns its canonical status JSON to pay.js.
        $this->invoices->check_order($order_num, false);
    }

    /**
     * Apply a soft global/IP budget before a public poll can reach TronGrid.
     * The database compare-and-set in check_order remains the atomic guard.
     */
    private function consume_preflight_scan_budget()
    {
        $window = (int) floor(time() / MINUTE_IN_SECONDS);
        $global_limit = (int) apply_filters('jiuliu_usdt_preflight_global_limit', 120);
        $ip_limit = (int) apply_filters('jiuliu_usdt_preflight_ip_limit', 20);
        $global_limit = max(1, min(1000, $global_limit));
        $ip_limit = max(1, min(120, $ip_limit));

        $ip = (string) JIULIU_USDT_Util::client_ip();
        $ip_hash = substr(hash('sha256', $ip ? $ip : 'unknown'), 0, 20);
        $global_key = 'jiuliu_usdt_preflight_global_' . $window;
        $ip_key = 'jiuliu_usdt_preflight_ip_' . $window . '_' . $ip_hash;
        $global_count = absint(get_transient($global_key));
        $ip_count = absint(get_transient($ip_key));

        if ($global_count >= $global_limit || $ip_count >= $ip_limit) {
            return false;
        }

        // This counter is deliberately a soft availability limit. Concurrent
        // requests are finally serialized by DB::acquire_check_slot().
        set_transient($global_key, $global_count + 1, 2 * MINUTE_IN_SECONDS);
        set_transient($ip_key, $ip_count + 1, 2 * MINUTE_IN_SECONDS);

        return true;
    }

    public function enqueue_assets()
    {
        if (!$this->settings->is_enabled() || is_admin()) {
            return;
        }

        wp_enqueue_style(
            'jiuliu-usdt-payment',
            JIULIU_USDT_URL . 'assets/css/frontend.css',
            array(),
            JIULIU_USDT_VERSION
        );
        wp_enqueue_script(
            'jiuliu-usdt-payment',
            JIULIU_USDT_URL . 'assets/js/frontend.js',
            array('jquery'),
            JIULIU_USDT_VERSION,
            true
        );
        wp_localize_script('jiuliu-usdt-payment', 'jiuliuUsdt', array(
            'ajaxUrl'  => admin_url('admin-ajax.php'),
            'copyOk'   => __('已复制', 'jiuliu-usdt-payment'),
            'copyFail' => __('复制失败，请手动复制', 'jiuliu-usdt-payment'),
            'checking' => __('正在核验链上交易…', 'jiuliu-usdt-payment'),
            'error'    => __('核验失败，请稍后重试', 'jiuliu-usdt-payment'),
            'waiting'  => __('等待 USDT 链上确认，请勿关闭支付页面', 'jiuliu-usdt-payment'),
        ));
    }

    public function compatibility_notice()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && false === strpos($screen->id, 'jiuliu-usdt')) {
            return;
        }

        if (!$this->is_zibll_template()) {
            echo '<div class="notice notice-warning"><p>'
                . esc_html__('九流网络 USDT 支付已安装，但当前父主题不是 Zibll。网关不会在前台启用。', 'jiuliu-usdt-payment')
                . '</p></div>';
            return;
        }

        if (!$this->has_required_api()) {
            echo '<div class="notice notice-error"><p>'
                . esc_html__('当前 Zibll 缺少 V9 支付扩展接口（ZibPay::payment_order 或支付函数）。请确认主题为完整的 Zibll 9.0。', 'jiuliu-usdt-payment')
                . '</p></div>';
            return;
        }

        $transactional_tables = $this->db->settlement_tables_are_transactional();
        if (is_wp_error($transactional_tables)) {
            echo '<div class="notice notice-error"><p>'
                . esc_html($transactional_tables->get_error_message())
                . '</p></div>';
        }
    }

    public function has_required_api()
    {
        return function_exists('zibpay_get_payment_methods')
            && function_exists('zibpay_get_payment_pay_over_time')
            && class_exists('ZibPay')
            && is_callable(array('ZibPay', 'payment_order'))
            && is_callable(array('ZibPay', 'get_payment'))
            && is_callable(array('ZibPay', 'get_order_by_payment_id'))
            && is_callable(array('ZibPay', 'get_order'))
            && is_callable(array('ZibPay', 'get_meta'))
            && is_callable(array('ZibPay', 'update_meta'));
    }

    public function is_zibll_template()
    {
        return function_exists('get_template') && 'zibll' === strtolower((string) get_template());
    }

    public function plugin_action_links($links)
    {
        array_unshift(
            $links,
            '<a href="' . esc_url(admin_url('admin.php?page=jiuliu-usdt')) . '">' . esc_html__('设置', 'jiuliu-usdt-payment') . '</a>'
        );
        return $links;
    }

    public function status()
    {
        $theme = wp_get_theme(get_template());
        return array(
            'is_zibll' => $this->is_zibll_template(),
            'version'  => $theme->exists() ? $theme->get('Version') : '',
            'api_ok'   => $this->has_required_api(),
        );
    }
}
