<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_CRYPTO_Admin
{
    private $settings;
    private $db;
    private $rate;
    private $trongrid;
    private $invoices;
    private $routes;

    public function __construct(
        JIULIU_CRYPTO_Settings $settings,
        JIULIU_CRYPTO_DB $db,
        JIULIU_CRYPTO_Rate $rate,
        JIULIU_CRYPTO_Trongrid $trongrid,
        JIULIU_CRYPTO_Invoices $invoices,
        $routes = null
    ) {
        $this->settings = $settings;
        $this->db = $db;
        $this->rate = $rate;
        $this->trongrid = $trongrid;
        $this->invoices = $invoices;
        $this->routes = $routes;

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_jiuliu_crypto_save_settings', array($this, 'save_settings'));
        add_action('admin_post_jiuliu_crypto_test_api', array($this, 'test_api'));
        add_action('admin_post_jiuliu_crypto_test_rate', array($this, 'test_rate'));
        add_action('admin_post_jiuliu_crypto_run_scan', array($this, 'run_scan'));
        add_action('admin_post_jiuliu_crypto_rotate_cron_token', array($this, 'rotate_cron_token'));
        add_action('admin_post_jiuliu_crypto_check_invoice', array($this, 'check_invoice'));
        add_action('admin_post_jiuliu_crypto_verify_invoice', array($this, 'verify_invoice'));
        add_action('admin_post_jiuliu_crypto_reject_invoice', array($this, 'reject_invoice'));
    }

    public function admin_menu()
    {
        add_menu_page(
            __('多链收款', 'jiuliu-crypto-payment'),
            __('多链收款', 'jiuliu-crypto-payment'),
            'manage_options',
            'jiuliu-crypto',
            array($this, 'render_page'),
            'dashicons-money-alt',
            56
        );
    }

    public function enqueue_assets($hook)
    {
        if ('toplevel_page_jiuliu-crypto' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'jiuliu-crypto-admin',
            JIULIU_CRYPTO_URL . 'assets/css/admin.css',
            array(),
            JIULIU_CRYPTO_VERSION
        );
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'jiuliu-crypto-payment'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'orders';
        if (!in_array($tab, array('orders', 'settings', 'logs', 'status'), true)) {
            $tab = 'orders';
        }

        echo '<div class="wrap jiuliu-crypto-admin">';
        echo '<h1>' . esc_html__('九流网络多链加密货币支付', 'jiuliu-crypto-payment') . '</h1>';
        $this->render_notice();
        $this->render_tabs($tab);

        if ('settings' === $tab) {
            $this->render_settings();
        } elseif ('logs' === $tab) {
            $this->render_logs();
        } elseif ('status' === $tab) {
            $this->render_status();
        } else {
            $this->render_orders();
        }
        echo '</div>';
    }

    private function render_tabs($active)
    {
        $tabs = array(
            'orders'   => __('支付单', 'jiuliu-crypto-payment'),
            'settings' => __('设置', 'jiuliu-crypto-payment'),
            'status'   => __('系统状态', 'jiuliu-crypto-payment'),
            'logs'     => __('日志', 'jiuliu-crypto-payment'),
        );
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => 'jiuliu-crypto', 'tab' => $key), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($active === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function render_notice()
    {
        $key = 'jiuliu_crypto_admin_notice_' . get_current_user_id();
        $notice = get_transient($key);
        if (!$notice || !is_array($notice)) {
            return;
        }
        delete_transient($key);
        $type = !empty($notice['type']) ? sanitize_html_class($notice['type']) : 'success';
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($notice['message']) . '</p></div>';
    }

    private function set_notice($message, $type = 'success')
    {
        set_transient(
            'jiuliu_crypto_admin_notice_' . get_current_user_id(),
            array('message' => $message, 'type' => $type),
            MINUTE_IN_SECONDS
        );
    }

    private function redirect($tab = 'orders')
    {
        wp_safe_redirect(add_query_arg(array('page' => 'jiuliu-crypto', 'tab' => $tab), admin_url('admin.php')));
        exit;
    }

    private function require_admin_action($nonce_action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'jiuliu-crypto-payment'));
        }
        check_admin_referer($nonce_action);
    }

    public function save_settings()
    {
        $this->require_admin_action('jiuliu_crypto_save_settings');
        $input = isset($_POST['settings']) && is_array($_POST['settings']) ? $_POST['settings'] : array();
        $old_settings = $this->settings->all();
        $old_monitor_closed = (bool) $this->settings->get('monitor_closed_orders', 1);
        $result = $this->settings->update($input);
        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
        } else {
            $new_monitor_closed = !empty($result['monitor_closed_orders']);
            if ($new_monitor_closed !== $old_monitor_closed) {
                $changed = $this->db->sync_closed_monitoring($new_monitor_closed);
                if (false === $changed) {
                    update_option(JIULIU_CRYPTO_Settings::OPTION_NAME, $old_settings, false);
                    delete_transient('jiuliu_crypto_auto_rate');
                    $this->db->log(
                        'closed_monitor_mode_change_failed',
                        __('关闭订单到账观察切换失败，全部设置已恢复为保存前状态。', 'jiuliu-crypto-payment'),
                        0,
                        'error',
                        array(),
                        get_current_user_id()
                    );
                    $this->set_notice(__('数据库未能同步关闭订单的监控状态；本次设置没有生效，请检查数据库后重试。', 'jiuliu-crypto-payment'), 'error');
                    $this->redirect('settings');
                }
                $this->db->log(
                    'closed_monitor_mode_changed',
                    $new_monitor_closed
                        ? __('管理员开启了关闭订单到账观察。', 'jiuliu-crypto-payment')
                        : __('管理员关闭了关闭订单到账观察。', 'jiuliu-crypto-payment'),
                    0,
                    'warning',
                    array('affected_invoices' => (int) $changed),
                    get_current_user_id()
                );
            }
            $this->db->log('settings_saved', __('管理员更新了多链收款设置。', 'jiuliu-crypto-payment'), 0, 'info', array(), get_current_user_id());
            $this->set_notice(__('设置已保存。', 'jiuliu-crypto-payment'));
        }
        $this->redirect('settings');
    }

    public function test_api()
    {
        $this->require_admin_action('jiuliu_crypto_test_api');
        $routes = $this->routes && is_callable(array($this->routes, 'enabled')) ? $this->routes->enabled() : array();
        if (!$routes) {
            $this->set_notice(__('没有可测试的已启用支付路线。', 'jiuliu-crypto-payment'), 'error');
            $this->redirect('status');
        }
        $failures = array();
        foreach ($routes as $route) {
            if ('evm' === $route['adapter']) {
                $result = (new JIULIU_CRYPTO_EVM($route))->test_connection();
            } else {
                $now = (int) round(microtime(true) * 1000);
                $result = $this->trongrid->get_transfers($route['receive_address'], $now - DAY_IN_SECONDS * 1000, $now, 1, 15, $route);
            }
            if (is_wp_error($result)) {
                $failures[] = $route['asset_symbol'] . ' · ' . $route['network'] . ': ' . $result->get_error_message();
            }
        }
        if ($failures) {
            $this->set_notice(sprintf(__('路线连接测试失败：%s', 'jiuliu-crypto-payment'), implode('；', $failures)), 'error');
        } else {
            $this->set_notice(sprintf(__('全部 %d 条已启用支付路线连接正常。', 'jiuliu-crypto-payment'), count($routes)));
        }
        $this->redirect('status');
    }

    public function test_rate()
    {
        $this->require_admin_action('jiuliu_crypto_test_rate');
        $result = $this->rate->get_rate(true);
        if (!empty($result['fallback'])) {
            $this->set_notice(sprintf(__('自动汇率失败，已回退固定汇率 %1$s：%2$s', 'jiuliu-crypto-payment'), $result['rate'], $result['error']), 'warning');
        } else {
            $this->set_notice(sprintf(__('汇率读取正常：1 USDT = %1$s CNY（来源：%2$s）。', 'jiuliu-crypto-payment'), $result['rate'], $result['source']));
        }
        $this->redirect('status');
    }

    public function run_scan()
    {
        $this->require_admin_action('jiuliu_crypto_run_scan');
        $result = jiuliu_crypto_payment()->cron->run();
        if (!empty($result['error'])) {
            $this->set_notice(sprintf(__('扫描失败：%s', 'jiuliu-crypto-payment'), $result['error']), 'error');
            $this->redirect('orders');
        }
        if (!empty($result['paused'])) {
            $this->set_notice(__('链上监控处于紧急暂停状态，本次没有扫描。', 'jiuliu-crypto-payment'), 'warning');
            $this->redirect('orders');
        }
        if (!empty($result['busy'])) {
            $this->set_notice(__('另一轮链上扫描正在运行，本次请求未重复执行。', 'jiuliu-crypto-payment'), 'warning');
            $this->redirect('orders');
        }
        if (!empty($result['partial']) || !empty($result['errors'])) {
            $this->set_notice(sprintf(
                __('扫描未完全成功：检查 %1$d 个支付单，完成 %2$d 个，转人工 %3$d 个，错误 %4$d 个。请查看日志并重试。', 'jiuliu-crypto-payment'),
                isset($result['checked']) ? $result['checked'] : 0,
                isset($result['paid']) ? $result['paid'] : 0,
                isset($result['review']) ? $result['review'] : 0,
                isset($result['errors']) ? $result['errors'] : 0
            ), 'warning');
            $this->redirect('orders');
        }
        $this->set_notice(sprintf(
            __('扫描完成：检查 %1$d 个支付单，完成 %2$d 个，转人工 %3$d 个。', 'jiuliu-crypto-payment'),
            isset($result['checked']) ? $result['checked'] : 0,
            isset($result['paid']) ? $result['paid'] : 0,
            isset($result['review']) ? $result['review'] : 0
        ));
        $this->redirect('orders');
    }

    public function rotate_cron_token()
    {
        $this->require_admin_action('jiuliu_crypto_rotate_cron_token');
        $result = $this->settings->rotate_cron_token();
        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
        } else {
            $this->db->log('cron_token_rotated', __('管理员更换了外部 Cron 密钥。', 'jiuliu-crypto-payment'), 0, 'warning', array(), get_current_user_id());
            $this->set_notice(__('Cron 密钥已更换。请立即更新服务器定时任务，旧密钥已失效。', 'jiuliu-crypto-payment'), 'warning');
        }
        $this->redirect('status');
    }

    public function check_invoice()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $this->require_admin_action('jiuliu_crypto_check_invoice_' . $invoice_id);
        $invoice = $this->db->get_invoice($invoice_id);
        if (!$invoice) {
            $this->set_notice(__('支付单不存在。', 'jiuliu-crypto-payment'), 'error');
        } else {
            $result = $this->invoices->check_order($invoice->zibll_order_num, true);
            if (is_wp_error($result)) {
                $this->set_notice($result->get_error_message(), 'error');
            } else {
                $this->set_notice(__('链上状态检查完成。', 'jiuliu-crypto-payment'));
            }
        }
        $this->redirect('orders');
    }

    public function verify_invoice()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $this->require_admin_action('jiuliu_crypto_verify_invoice_' . $invoice_id);
        $txid = isset($_POST['txid']) ? strtolower(sanitize_text_field(wp_unslash($_POST['txid']))) : '';
        $force = !empty($_POST['force']);
        $confirm_uncertain = !empty($_POST['confirm_uncertain_settlement']);
        $invoice = $this->db->get_invoice($invoice_id);
        if (
            $invoice
            && isset($invoice->error_code)
            && 'zibll_settlement_uncertain' === $invoice->error_code
            && (!$confirm_uncertain || !$force)
        ) {
            $this->set_notice(__('此支付单的子比结算结果不确定。必须先核对权益、库存、发货和通知，并同时勾选“强制补单”与重复结算风险确认。', 'jiuliu-crypto-payment'), 'error');
            $this->redirect('orders');
        }

        $result = $this->invoices->verify_admin_txid($invoice_id, $txid, $force, $confirm_uncertain);
        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
        } elseif ('paid' === $result->status) {
            $this->set_notice(__('交易核验通过，子比订单已经完成。', 'jiuliu-crypto-payment'));
        } elseif ('review' === $result->status) {
            $this->set_notice(__('交易已确认，但金额、时间或子比订单状态异常，仍需人工处理。', 'jiuliu-crypto-payment'), 'warning');
        } else {
            $this->set_notice(__('交易核验完成。', 'jiuliu-crypto-payment'));
        }
        $this->redirect('orders');
    }

    public function reject_invoice()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $this->require_admin_action('jiuliu_crypto_reject_invoice_' . $invoice_id);
        $invoice = $this->db->get_invoice($invoice_id);
        if ($invoice && isset($invoice->error_code) && 'zibll_settlement_uncertain' === (string) $invoice->error_code) {
            $this->set_notice(__('该支付单的子比结算结果不确定，不能标记为拒绝；请先核对已发权益、库存、发货和通知。', 'jiuliu-crypto-payment'), 'error');
            $this->redirect('orders');
        }
        if ($this->db->reject_invoice($invoice_id)) {
            $this->db->log('invoice_rejected', __('管理员拒绝了支付单。', 'jiuliu-crypto-payment'), $invoice_id, 'warning', array(), get_current_user_id());
            $this->set_notice(__('支付单已标记为拒绝。', 'jiuliu-crypto-payment'));
        } else {
            $this->set_notice(__('支付单状态已经变化；已支付或正在结算的支付单不能拒绝。', 'jiuliu-crypto-payment'), 'error');
        }
        $this->redirect('orders');
    }

    private function render_settings()
    {
        $s = $this->settings->all();
        $registry = new JIULIU_CRYPTO_Routes((array) $s['payment_routes']);
        $routes = $registry->all(false);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="jiuliu-crypto-settings">';
        echo '<input type="hidden" name="action" value="jiuliu_crypto_save_settings">';
        wp_nonce_field('jiuliu_crypto_save_settings');

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('多链网关总开关', 'jiuliu-crypto-payment') . '</h2><table class="form-table"><tbody>';
        $this->checkbox_row('enabled', __('接受新的链上支付订单', 'jiuliu-crypto-payment'), $s['enabled'], __('启用后，下面勾选且配置完整的路线会分别出现在子比收银台。', 'jiuliu-crypto-payment'));
        echo '</tbody></table><div class="notice notice-warning inline"><p><strong>' . esc_html__('收款金额规则：网站必须完整收到收银台显示的精确金额。', 'jiuliu-crypto-payment') . '</strong> '
            . esc_html__('链上网络费及交易所提币手续费全部由付款方另行承担，不得从页面金额中扣除。', 'jiuliu-crypto-payment') . '</p></div></div>';

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('币种与网络路线', 'jiuliu-crypto-payment') . '</h2>';
        echo '<p>' . esc_html__('每条路线会成为子比中的独立支付方式。只填写公开收款地址和只读 RPC/API，不要填写私钥或助记词。2.0.0 安全版仅开放官方 USDT/USDC 六位精度预设。', 'jiuliu-crypto-payment') . '</p>';
        foreach ($routes as $route_id => $route) {
            $base = 'settings[payment_routes][' . esc_attr($route_id) . ']';
            foreach (array('id', 'asset_symbol', 'asset_name', 'asset_decimals', 'adapter', 'network_label', 'chain_key', 'chain_id', 'contract_address', 'fee_symbol', 'scan_block_chunk', 'scan_max_blocks', 'scan_max_results', 'rpc_timeout') as $field) {
                echo '<input type="hidden" name="' . $base . '[' . esc_attr($field) . ']" value="' . esc_attr($route[$field]) . '">';
            }
            echo '<div class="jiuliu-crypto-route-card" style="border:1px solid #dcdcde;padding:14px;margin:12px 0;border-radius:8px">';
            echo '<h3 style="margin-top:0">' . esc_html($route['asset_symbol'] . ' · ' . $route['network_label']) . '</h3>';
            echo '<label><input type="checkbox" name="' . $base . '[enabled]" value="1" ' . checked(!empty($route['enabled']), true, false) . '> <strong>' . esc_html__('启用此路线', 'jiuliu-crypto-payment') . '</strong></label>';
            echo '<table class="form-table"><tbody>';
            echo '<tr><th>' . esc_html__('官方代币合约', 'jiuliu-crypto-payment') . '</th><td><code>' . esc_html($route['contract_address']) . '</code></td></tr>';
            echo '<tr><th><label>' . esc_html__('收款地址', 'jiuliu-crypto-payment') . '</label></th><td><input class="large-text code" type="text" name="' . $base . '[receive_address]" value="' . esc_attr($route['receive_address']) . '" placeholder="' . esc_attr('tron' === $route['adapter'] ? 'T...' : '0x...') . '"></td></tr>';
            if ('evm' === $route['adapter']) {
                echo '<tr><th><label>' . esc_html__('HTTPS JSON-RPC', 'jiuliu-crypto-payment') . '</label></th><td><input class="large-text code" type="url" name="' . $base . '[rpc_url]" value="' . esc_attr($route['rpc_url']) . '" placeholder="https://..."><p class="description">' . esc_html__('必须支持 eth_getLogs；公共 RPC 可能限流，生产环境建议使用自己的只读节点服务。', 'jiuliu-crypto-payment') . '</p></td></tr>';
                $header_count = isset($route['rpc_headers']) && is_array($route['rpc_headers']) ? count($route['rpc_headers']) : 0;
                echo '<tr><th><label>' . esc_html__('RPC 请求头 JSON', 'jiuliu-crypto-payment') . '</label></th><td><input class="large-text code" type="password" autocomplete="new-password" name="' . $base . '[rpc_headers_json]" value="" placeholder="' . esc_attr($header_count ? sprintf(__('已配置 %d 个，留空保留', 'jiuliu-crypto-payment'), $header_count) : '{"Authorization":"Bearer ..."}') . '"><label><input type="checkbox" name="' . $base . '[clear_rpc_headers]" value="1"> ' . esc_html__('清除已保存请求头', 'jiuliu-crypto-payment') . '</label><p class="description">' . esc_html__('可选；仅用于需要 Authorization 等只读凭据的 RPC。留空保留已配置值。', 'jiuliu-crypto-payment') . '</p></td></tr>';
                echo '<input type="hidden" name="' . $base . '[api_key]" value="">';
            } else {
                echo '<input type="hidden" name="' . $base . '[rpc_url]" value="">';
                echo '<tr><th><label>' . esc_html__('TronGrid API Key', 'jiuliu-crypto-payment') . '</label></th><td><input class="large-text code" type="password" autocomplete="new-password" name="' . $base . '[api_key]" value="" placeholder="' . esc_attr(!empty($route['api_key']) ? __('已配置，留空保留', 'jiuliu-crypto-payment') : __('尚未配置', 'jiuliu-crypto-payment')) . '"><label><input type="checkbox" name="' . $base . '[clear_api_key]" value="1"> ' . esc_html__('清除已保存值', 'jiuliu-crypto-payment') . '</label></td></tr>';
            }
            if ('evm' === $route['adapter']) {
                echo '<tr><th>' . esc_html__('确认数', 'jiuliu-crypto-payment') . '</th><td><input type="number" min="1" max="1000" name="' . $base . '[required_confirmations]" value="' . esc_attr($route['required_confirmations']) . '"></td></tr>';
            } else {
                echo '<input type="hidden" name="' . $base . '[required_confirmations]" value="1">';
                echo '<tr><th>' . esc_html__('最终性', 'jiuliu-crypto-payment') . '</th><td>' . esc_html__('TronGrid walletsolidity 已固化区块（固定）', 'jiuliu-crypto-payment') . '</td></tr>';
            }
            echo '<tr><th>' . esc_html__('CNY 固定/备用汇率', 'jiuliu-crypto-payment') . '</th><td><input type="number" min="1" max="20" step="0.00000001" name="' . $base . '[rate_cny]" value="' . esc_attr($route['rate_cny']) . '"><p class="description">1 ' . esc_html($route['asset_symbol']) . ' = ? CNY</p></td></tr>';
            echo '</tbody></table></div>';
        }
        echo '</div>';

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('汇率与金额', 'jiuliu-crypto-payment') . '</h2><table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('汇率模式', 'jiuliu-crypto-payment') . '</th><td><select name="settings[rate_mode]">'
            . '<option value="fixed" ' . selected($s['rate_mode'], 'fixed', false) . '>' . esc_html__('固定汇率', 'jiuliu-crypto-payment') . '</option>'
            . '<option value="auto" ' . selected($s['rate_mode'], 'auto', false) . '>' . esc_html__('CoinGecko 市场参考汇率（失败回退固定）', 'jiuliu-crypto-payment') . '</option>'
            . '</select></td></tr>';
        $this->number_row('fixed_rate', __('全局备用汇率', 'jiuliu-crypto-payment'), $s['fixed_rate'], '0.0001', __('仅在路线未提供汇率时使用；每条路线可单独配置。', 'jiuliu-crypto-payment'));
        $this->secret_row('coingecko_api_key', __('CoinGecko Demo Key', 'jiuliu-crypto-payment'), $this->settings->masked_api_key('coingecko_api_key'), __('可选；没有 Key 时会尝试公共接口，也可在 wp-config.php 定义 JIULIU_CRYPTO_COINGECKO_API_KEY。CoinGecko 是第三方市场数据，仅作报价参考，并非 Tether 官方结算汇率。', 'jiuliu-crypto-payment'));
        $this->number_row('auto_rate_max_deviation', __('自动汇率偏差熔断（%）', 'jiuliu-crypto-payment'), $s['auto_rate_max_deviation'], '0.01', __('相对备用固定汇率的最大允许偏差；允许 1% 至 30%，默认 10%。', 'jiuliu-crypto-payment'));
        $this->number_row('rate_markup', __('汇率加成（%）', 'jiuliu-crypto-payment'), $s['rate_markup'], '0.0001', __('正数增加应付币数量，负数提供优惠；允许 -50% 至 100%。', 'jiuliu-crypto-payment'));
        $this->number_row('minimum_local_amount', __('最低订单金额', 'jiuliu-crypto-payment'), $s['minimum_local_amount'], '0.01', __('金额过低时插件会拒绝生成尾数空间不足的共享地址报价。', 'jiuliu-crypto-payment'));
        $this->number_row('maximum_local_amount', __('最高订单金额', 'jiuliu-crypto-payment'), $s['maximum_local_amount'], '0.01', '');
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('核验、安全与通知', 'jiuliu-crypto-payment') . '</h2><table class="form-table"><tbody>';
        $this->checkbox_row('pause_monitoring', __('紧急暂停全部自动监控与结算', 'jiuliu-crypto-payment'), $s['pause_monitoring'], __('仅用于故障处置。开启后现有支付单也不会被浏览器轮询或定时任务自动核验；恢复前必须人工检查是否已有用户转账。', 'jiuliu-crypto-payment'));
        $this->number_row('invoice_timeout', __('备用有效期（分钟）', 'jiuliu-crypto-payment'), $s['invoice_timeout'], '1', __('正常情况下严格使用子比原生订单截止时间；仅在主题未返回截止时间时使用此备用值。', 'jiuliu-crypto-payment'));
        $this->number_row('late_grace_hours', __('过期到账观察期（小时）', 'jiuliu-crypto-payment'), $s['late_grace_hours'], '1', __('过期到账只记录并转人工，不会自动发货。', 'jiuliu-crypto-payment'));
        $this->checkbox_row('monitor_closed_orders', __('子比订单关闭后继续观察到账', 'jiuliu-crypto-payment'), $s['monitor_closed_orders'], __('推荐启用：观察期内发现到账会转人工处理，绝不自动发货。关闭此开关可节省链上查询额度，但可能错过“先转账、后关闭”的到账。', 'jiuliu-crypto-payment'));
        $this->checkbox_row('frontend_manual_txid', __('允许前台提交交易哈希', 'jiuliu-crypto-payment'), $s['frontend_manual_txid'], __('用于交易所截断小数、少付、多付等异常核验。', 'jiuliu-crypto-payment'));
        $this->checkbox_row('admin_email_notifications', __('管理员邮件通知', 'jiuliu-crypto-payment'), $s['admin_email_notifications'], '');
        $this->checkbox_row('user_email_notifications', __('用户成功邮件', 'jiuliu-crypto-payment'), $s['user_email_notifications'], '');
        $this->number_row('log_retention_days', __('日志保留天数', 'jiuliu-crypto-payment'), $s['log_retention_days'], '1', '');
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('外部系统 Cron', 'jiuliu-crypto-payment') . '</h2><table class="form-table"><tbody>';
        $this->text_row('cron_token', __('Cron 密钥', 'jiuliu-crypto-payment'), $s['cron_token'], '', __('至少 32 位。也可在 wp-config.php 中定义 JIULIU_CRYPTO_CRON_TOKEN。', 'jiuliu-crypto-payment'));
        echo '<tr><th>' . esc_html__('IP 白名单', 'jiuliu-crypto-payment') . '</th><td><textarea name="settings[cron_ip_allowlist]" rows="4" class="large-text code">' . esc_textarea($s['cron_ip_allowlist']) . '</textarea><p class="description">' . esc_html__('可选，每行或逗号分隔一个 IPv4/IPv6；留空不限制来源 IP。', 'jiuliu-crypto-payment') . '</p></td></tr>';
        echo '</tbody></table></div>';

        submit_button(__('保存设置', 'jiuliu-crypto-payment'));
        echo '</form>';
    }

    private function checkbox_row($key, $label, $checked, $description)
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1" ' . checked($checked, 1, false) . '> ' . esc_html__('启用', 'jiuliu-crypto-payment') . '</label>';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function text_row($key, $label, $value, $placeholder, $description)
    {
        echo '<tr><th><label for="jiuliu-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input id="jiuliu-' . esc_attr($key) . '" class="regular-text code" type="text" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" placeholder="' . esc_attr($placeholder) . '">';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function number_row($key, $label, $value, $step, $description)
    {
        echo '<tr><th><label for="jiuliu-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input id="jiuliu-' . esc_attr($key) . '" type="number" name="settings[' . esc_attr($key) . ']" value="' . esc_attr($value) . '" step="' . esc_attr($step) . '">';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function secret_row($key, $label, $masked, $description)
    {
        echo '<tr><th><label for="jiuliu-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input id="jiuliu-' . esc_attr($key) . '" class="regular-text code" type="password" name="settings[' . esc_attr($key) . ']" value="" autocomplete="new-password" placeholder="' . esc_attr($masked ?: __('尚未配置', 'jiuliu-crypto-payment')) . '">';
        echo '<label class="jiuliu-clear-secret"><input type="checkbox" name="settings[clear_' . esc_attr($key) . ']" value="1"> ' . esc_html__('清除已保存值', 'jiuliu-crypto-payment') . '</label>';
        if ($description) {
            echo '<p class="description">' . esc_html($description) . '</p>';
        }
        echo '</td></tr>';
    }

    private function render_orders()
    {
        $status = isset($_GET['status']) ? sanitize_key(wp_unslash($_GET['status'])) : '';
        $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash($_GET['s'])) : '';
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $per_page = 20;
        $total = $this->db->count_invoices($status, $search);
        $orders = $this->db->list_invoices($status, $search, $page, $per_page);

        echo '<div class="jiuliu-crypto-toolbar">';
        echo '<form method="get"><input type="hidden" name="page" value="jiuliu-crypto"><input type="hidden" name="tab" value="orders">';
        echo '<select name="status"><option value="">' . esc_html__('全部状态', 'jiuliu-crypto-payment') . '</option>';
        foreach (array('pending', 'paid', 'review', 'expired', 'closed', 'closed_no_monitor', 'superseded', 'rejected') as $value) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($this->invoices->status_label($value)) . '</option>';
        }
        echo '</select> <input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('订单号或交易哈希', 'jiuliu-crypto-payment') . '"> ';
        submit_button(__('筛选', 'jiuliu-crypto-payment'), 'secondary', '', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_crypto_run_scan">';
        wp_nonce_field('jiuliu_crypto_run_scan');
        submit_button(__('立即扫描链上到账', 'jiuliu-crypto-payment'), 'secondary', '', false);
        echo '</form></div>';

        echo '<table class="widefat striped jiuliu-crypto-orders"><thead><tr>'
            . '<th>' . esc_html__('支付单', 'jiuliu-crypto-payment') . '</th>'
            . '<th>' . esc_html__('金额', 'jiuliu-crypto-payment') . '</th>'
            . '<th>' . esc_html__('状态', 'jiuliu-crypto-payment') . '</th>'
            . '<th>' . esc_html__('链上交易', 'jiuliu-crypto-payment') . '</th>'
            . '<th>' . esc_html__('时间', 'jiuliu-crypto-payment') . '</th>'
            . '<th>' . esc_html__('操作', 'jiuliu-crypto-payment') . '</th>'
            . '</tr></thead><tbody>';

        if (!$orders) {
            echo '<tr><td colspan="6">' . esc_html__('暂无链上支付单。', 'jiuliu-crypto-payment') . '</td></tr>';
        }
        foreach ($orders as $invoice) {
            $this->render_order_row($invoice);
        }
        echo '</tbody></table>';

        $pages = (int) ceil($total / $per_page);
        if ($pages > 1) {
            echo '<div class="tablenav"><div class="tablenav-pages">';
            echo wp_kses_post(paginate_links(array(
                'base'      => add_query_arg('paged', '%#%'),
                'format'    => '',
                'current'   => $page,
                'total'     => $pages,
                'prev_text' => '‹',
                'next_text' => '›',
            )));
            echo '</div></div>';
        }
    }

    private function render_order_row($invoice)
    {
        $display_txid = $invoice->txid ?: $invoice->submitted_txid;
        $valid_txid = $display_txid && JIULIU_CRYPTO_Util::is_valid_txid($display_txid);
        echo '<tr>';
        echo '<td><strong>' . esc_html($invoice->invoice_no) . '</strong><br><code>' . esc_html($invoice->zibll_order_num) . '</code><br><span class="description">payment_id: ' . esc_html($invoice->payment_id) . '</span></td>';
        $asset_symbol = !empty($invoice->asset_symbol) ? $invoice->asset_symbol : '—';
        echo '<td><strong>' . esc_html($invoice->asset_amount) . ' ' . esc_html($asset_symbol) . '</strong><br><span class="description">' . esc_html(number_format((float) $invoice->local_amount, 2)) . ' ' . esc_html($invoice->local_currency) . '<br>1 ' . esc_html($asset_symbol) . ' = ' . esc_html(rtrim(rtrim($invoice->rate, '0'), '.')) . '</span></td>';
        echo '<td><span class="jiuliu-status jiuliu-status-' . esc_attr($invoice->status) . '">' . esc_html($this->invoices->status_label($invoice->status)) . '</span>';
        if ($invoice->note) {
            echo '<p class="description">' . esc_html($invoice->note) . '</p>';
        }
        echo '</td>';
        echo '<td>';
        if ($valid_txid) {
            echo '<code title="' . esc_attr($display_txid) . '">' . esc_html(substr($display_txid, 0, 12) . '…' . substr($display_txid, -8)) . '</code>';
            if (!$invoice->txid && $invoice->submitted_txid) {
                echo '<br><span class="description">' . esc_html__('仅为用户提交，尚未绑定到此支付单', 'jiuliu-crypto-payment') . '</span>';
            }
            if ($invoice->actual_amount) {
                echo '<br><span class="description">' . esc_html($invoice->actual_amount) . ' ' . esc_html($asset_symbol) . '</span>';
            }
        } else {
            echo '—';
        }
        echo '</td>';
        echo '<td><span title="UTC">' . esc_html(JIULIU_CRYPTO_Util::display_datetime($invoice->created_at)) . '</span><br><span class="description">' . esc_html__('到期：', 'jiuliu-crypto-payment') . esc_html(JIULIU_CRYPTO_Util::display_datetime($invoice->expires_at)) . '</span></td>';
        echo '<td class="jiuliu-order-actions">';

        if (in_array($invoice->status, array('pending', 'expired', 'closed', 'closed_no_monitor'), true)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_crypto_check_invoice"><input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            wp_nonce_field('jiuliu_crypto_check_invoice_' . $invoice->id);
            submit_button(__('检查', 'jiuliu-crypto-payment'), 'small secondary', '', false);
            echo '</form>';
        }

        if (in_array($invoice->status, array('pending', 'expired', 'review', 'superseded', 'closed', 'closed_no_monitor'), true)) {
            $is_uncertain = isset($invoice->error_code) && 'zibll_settlement_uncertain' === $invoice->error_code;
            echo '<details><summary>' . esc_html__('核验/补单', 'jiuliu-crypto-payment') . '</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_crypto_verify_invoice"><input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            wp_nonce_field('jiuliu_crypto_verify_invoice_' . $invoice->id);
            echo '<input type="text" name="txid" maxlength="66" value="' . esc_attr($invoice->txid ?: $invoice->submitted_txid) . '" placeholder="' . esc_attr__('64 位或 0x + 64 位交易哈希', 'jiuliu-crypto-payment') . '" required>';
            echo '<label><input type="checkbox" name="force" value="1"' . ($is_uncertain ? ' required' : '') . '> ' . esc_html__('金额/时间异常时尝试强制补单（关闭的商城订单仍会被安全拦截）', 'jiuliu-crypto-payment') . '</label>';
            if ($is_uncertain) {
                echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('高风险：此前子比结算结果不确定。再次结算可能重复开通权益、扣减库存、发货或发送通知。', 'jiuliu-crypto-payment') . '</strong></p></div>';
                echo '<label><input type="checkbox" name="confirm_uncertain_settlement" value="1" required> ' . esc_html__('我已核对会员/余额/内容权限、库存、发货及通知，理解重复结算风险，仍确认重试。', 'jiuliu-crypto-payment') . '</label>';
            }
            submit_button(__('核验交易', 'jiuliu-crypto-payment'), 'small primary', '', false);
            echo '</form></details>';
        }

        $can_reject = in_array($invoice->status, array('pending', 'expired', 'review', 'superseded', 'closed', 'closed_no_monitor'), true)
            && (!isset($invoice->error_code) || 'zibll_settlement_uncertain' !== (string) $invoice->error_code);
        if ($can_reject) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('确定拒绝此支付单？此操作不会退回链上资产。', 'jiuliu-crypto-payment')) . '\');"><input type="hidden" name="action" value="jiuliu_crypto_reject_invoice"><input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            wp_nonce_field('jiuliu_crypto_reject_invoice_' . $invoice->id);
            submit_button(__('拒绝', 'jiuliu-crypto-payment'), 'small delete', '', false);
            echo '</form>';
        }
        echo '</td></tr>';
    }

    private function render_logs()
    {
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $logs = $this->db->list_logs($page, 50);
        echo '<div class="jiuliu-crypto-card"><p>' . esc_html__('日志不会记录 API Key、Cron 密钥或支付单公开令牌。', 'jiuliu-crypto-payment') . '</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('时间', 'jiuliu-crypto-payment') . '</th><th>' . esc_html__('级别/事件', 'jiuliu-crypto-payment') . '</th><th>' . esc_html__('支付单', 'jiuliu-crypto-payment') . '</th><th>' . esc_html__('内容', 'jiuliu-crypto-payment') . '</th></tr></thead><tbody>';
        if (!$logs) {
            echo '<tr><td colspan="5">' . esc_html__('暂无日志。', 'jiuliu-crypto-payment') . '</td></tr>';
        }
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log->id) . '</td><td>' . esc_html(JIULIU_CRYPTO_Util::display_datetime($log->created_at)) . '</td><td><code>' . esc_html($log->level) . '</code><br>' . esc_html($log->event) . '</td><td>' . esc_html($log->invoice_id ?: '—') . '</td><td>' . esc_html($log->message);
            if ($log->context) {
                echo '<details><summary>' . esc_html__('上下文', 'jiuliu-crypto-payment') . '</summary><pre>' . esc_html($log->context) . '</pre></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_status()
    {
        $zibll = jiuliu_crypto_payment()->zibll->status();
        $next_cron = wp_next_scheduled(JIULIU_CRYPTO_Cron::EVENT);
        $endpoint = rest_url('jiuliu-crypto/v1/cron');
        $token = $this->settings->get('cron_token');
        $backoff_remaining = 0;
        $enabled_routes = $this->routes && is_callable(array($this->routes, 'enabled')) ? $this->routes->enabled() : array();
        foreach ($enabled_routes as $route) {
            if ('tron' === $route['adapter']) {
                $backoff_remaining = max($backoff_remaining, JIULIU_CRYPTO_Trongrid::backoff_remaining($route));
            }
        }
        $transactional_tables = $this->db->settlement_tables_are_transactional();
        $status_rows = array(
            __('WordPress', 'jiuliu-crypto-payment')       => get_bloginfo('version'),
            __('PHP', 'jiuliu-crypto-payment')             => PHP_VERSION,
            __('64 位整数', 'jiuliu-crypto-payment')       => PHP_INT_SIZE >= 8 ? __('正常', 'jiuliu-crypto-payment') : __('不支持', 'jiuliu-crypto-payment'),
            __('父主题', 'jiuliu-crypto-payment')          => $zibll['is_zibll'] ? 'Zibll ' . $zibll['version'] : get_template(),
            __('Zibll V9 支付接口', 'jiuliu-crypto-payment') => $zibll['api_ok'] ? __('正常', 'jiuliu-crypto-payment') : __('缺失', 'jiuliu-crypto-payment'),
            __('插件网关', 'jiuliu-crypto-payment')        => $this->settings->is_enabled() ? __('已启用', 'jiuliu-crypto-payment') : __('未启用/地址无效', 'jiuliu-crypto-payment'),
            __('既有支付单自动监控', 'jiuliu-crypto-payment') => $this->settings->get('pause_monitoring', 0) ? __('紧急暂停', 'jiuliu-crypto-payment') : __('运行中', 'jiuliu-crypto-payment'),
            __('关闭订单到账观察', 'jiuliu-crypto-payment') => $this->settings->get('monitor_closed_orders', 1) ? __('已启用（只转人工）', 'jiuliu-crypto-payment') : __('已停用', 'jiuliu-crypto-payment'),
            __('结算表事务引擎', 'jiuliu-crypto-payment')    => is_wp_error($transactional_tables) ? $transactional_tables->get_error_message() : __('正常（InnoDB）', 'jiuliu-crypto-payment'),
            __('TronGrid 查询退避', 'jiuliu-crypto-payment') => $backoff_remaining > 0 ? sprintf(__('剩余 %d 秒', 'jiuliu-crypto-payment'), $backoff_remaining) : __('正常', 'jiuliu-crypto-payment'),
            __('WP-Cron 下次运行', 'jiuliu-crypto-payment') => $next_cron ? wp_date('Y-m-d H:i:s', $next_cron) : __('未计划', 'jiuliu-crypto-payment'),
        );

        echo '<div class="jiuliu-crypto-status-grid">';
        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('系统状态', 'jiuliu-crypto-payment') . '</h2><table class="widefat striped"><tbody>';
        foreach ($status_rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('连接测试', 'jiuliu-crypto-payment') . '</h2>';
        echo '<div class="jiuliu-crypto-inline-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_crypto_test_api">';
        wp_nonce_field('jiuliu_crypto_test_api');
        submit_button(__('测试全部已启用路线', 'jiuliu-crypto-payment'), 'secondary', '', false);
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_crypto_test_rate">';
        wp_nonce_field('jiuliu_crypto_test_rate');
        submit_button(__('测试汇率', 'jiuliu-crypto-payment'), 'secondary', '', false);
        echo '</form></div></div>';

        echo '<div class="jiuliu-crypto-card jiuliu-crypto-cron-card"><h2>' . esc_html__('服务器 Cron（推荐）', 'jiuliu-crypto-payment') . '</h2>';
        echo '<p>' . esc_html__('每分钟调用一次。建议使用请求头传密钥，避免密钥出现在代理访问日志中：', 'jiuliu-crypto-payment') . '</p>';
        echo '<pre><code>* * * * * curl -fsS -X POST -H "X-Jiuliu-Cron-Token: ' . esc_html($token) . '" "' . esc_html($endpoint) . '" &gt;/dev/null 2&gt;&amp;1</code></pre>';
        echo '<p class="description">' . esc_html__('接口只接受 POST，并且密钥只能放在 X-Jiuliu-Cron-Token 请求头；URL 查询参数不会被接受。WP-Cron 会继续作为兜底。', 'jiuliu-crypto-payment') . '</p>';
        if (defined('JIULIU_CRYPTO_CRON_TOKEN')) {
            echo '<p class="description">' . esc_html__('当前密钥由 wp-config.php 中的 JIULIU_CRYPTO_CRON_TOKEN 管理，请在服务器配置中更换。', 'jiuliu-crypto-payment') . '</p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('更换后旧 Cron 密钥会立即失效。确定继续？', 'jiuliu-crypto-payment')) . '\');"><input type="hidden" name="action" value="jiuliu_crypto_rotate_cron_token">';
            wp_nonce_field('jiuliu_crypto_rotate_cron_token');
            submit_button(__('一键更换 Cron 密钥', 'jiuliu-crypto-payment'), 'secondary', '', false);
            echo '</form>';
        }
        echo '</div>';

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('安全说明', 'jiuliu-crypto-payment') . '</h2><ul class="ul-disc">';
        echo '<li>' . esc_html__('插件只读取公开链上数据，不保存、请求或使用钱包私钥和助记词。', 'jiuliu-crypto-payment') . '</li>';
        echo '<li>' . esc_html__('同一交易哈希只能结算一个支付单；到账金额、网络、合约、地址与时间都会核验。', 'jiuliu-crypto-payment') . '</li>';
        echo '<li>' . esc_html__('过期、少付、多付、支付方式已变更或商城订单已关闭时不会自动发货。', 'jiuliu-crypto-payment') . '</li>';
        echo '<li>' . esc_html__('链上资产退款必须由管理员在钱包中人工完成；插件绝不持有转账权限。', 'jiuliu-crypto-payment') . '</li>';
        echo '</ul></div></div>';
    }
}
