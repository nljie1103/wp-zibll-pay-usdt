<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Admin
{
    private $settings;
    private $db;
    private $rate;
    private $trongrid;
    private $invoices;

    public function __construct(
        JIULIU_USDT_Settings $settings,
        JIULIU_USDT_DB $db,
        JIULIU_USDT_Rate $rate,
        JIULIU_USDT_Trongrid $trongrid,
        JIULIU_USDT_Invoices $invoices
    ) {
        $this->settings = $settings;
        $this->db = $db;
        $this->rate = $rate;
        $this->trongrid = $trongrid;
        $this->invoices = $invoices;

        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_post_jiuliu_usdt_save_settings', array($this, 'save_settings'));
        add_action('admin_post_jiuliu_usdt_test_api', array($this, 'test_api'));
        add_action('admin_post_jiuliu_usdt_test_rate', array($this, 'test_rate'));
        add_action('admin_post_jiuliu_usdt_run_scan', array($this, 'run_scan'));
        add_action('admin_post_jiuliu_usdt_rotate_cron_token', array($this, 'rotate_cron_token'));
        add_action('admin_post_jiuliu_usdt_check_invoice', array($this, 'check_invoice'));
        add_action('admin_post_jiuliu_usdt_verify_invoice', array($this, 'verify_invoice'));
        add_action('admin_post_jiuliu_usdt_reject_invoice', array($this, 'reject_invoice'));
    }

    public function admin_menu()
    {
        add_menu_page(
            __('USDT 收款', 'jiuliu-usdt-payment'),
            __('USDT 收款', 'jiuliu-usdt-payment'),
            'manage_options',
            'jiuliu-usdt',
            array($this, 'render_page'),
            'dashicons-money-alt',
            56
        );
    }

    public function enqueue_assets($hook)
    {
        if ('toplevel_page_jiuliu-usdt' !== $hook) {
            return;
        }
        wp_enqueue_style(
            'jiuliu-usdt-admin',
            JIULIU_USDT_URL . 'assets/css/admin.css',
            array(),
            JIULIU_USDT_VERSION
        );
    }

    public function render_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'jiuliu-usdt-payment'));
        }

        $tab = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'orders';
        if (!in_array($tab, array('orders', 'settings', 'logs', 'status'), true)) {
            $tab = 'orders';
        }

        echo '<div class="wrap jiuliu-usdt-admin">';
        echo '<h1>' . esc_html__('九流网络 USDT 支付', 'jiuliu-usdt-payment') . '</h1>';
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
            'orders'   => __('支付单', 'jiuliu-usdt-payment'),
            'settings' => __('设置', 'jiuliu-usdt-payment'),
            'status'   => __('系统状态', 'jiuliu-usdt-payment'),
            'logs'     => __('日志', 'jiuliu-usdt-payment'),
        );
        echo '<nav class="nav-tab-wrapper">';
        foreach ($tabs as $key => $label) {
            $url = add_query_arg(array('page' => 'jiuliu-usdt', 'tab' => $key), admin_url('admin.php'));
            echo '<a class="nav-tab ' . ($active === $key ? 'nav-tab-active' : '') . '" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</nav>';
    }

    private function render_notice()
    {
        $key = 'jiuliu_usdt_admin_notice_' . get_current_user_id();
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
            'jiuliu_usdt_admin_notice_' . get_current_user_id(),
            array('message' => $message, 'type' => $type),
            MINUTE_IN_SECONDS
        );
    }

    private function redirect($tab = 'orders')
    {
        wp_safe_redirect(add_query_arg(array('page' => 'jiuliu-usdt', 'tab' => $tab), admin_url('admin.php')));
        exit;
    }

    private function require_admin_action($nonce_action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('权限不足。', 'jiuliu-usdt-payment'));
        }
        check_admin_referer($nonce_action);
    }

    public function save_settings()
    {
        $this->require_admin_action('jiuliu_usdt_save_settings');
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
                    update_option(JIULIU_USDT_Settings::OPTION_NAME, $old_settings, false);
                    delete_transient('jiuliu_usdt_auto_rate');
                    $this->db->log(
                        'closed_monitor_mode_change_failed',
                        __('关闭订单到账观察切换失败，全部设置已恢复为保存前状态。', 'jiuliu-usdt-payment'),
                        0,
                        'error',
                        array(),
                        get_current_user_id()
                    );
                    $this->set_notice(__('数据库未能同步关闭订单的监控状态；本次设置没有生效，请检查数据库后重试。', 'jiuliu-usdt-payment'), 'error');
                    $this->redirect('settings');
                }
                $this->db->log(
                    'closed_monitor_mode_changed',
                    $new_monitor_closed
                        ? __('管理员开启了关闭订单到账观察。', 'jiuliu-usdt-payment')
                        : __('管理员关闭了关闭订单到账观察。', 'jiuliu-usdt-payment'),
                    0,
                    'warning',
                    array('affected_invoices' => (int) $changed),
                    get_current_user_id()
                );
            }
            $this->db->log('settings_saved', __('管理员更新了 USDT 收款设置。', 'jiuliu-usdt-payment'), 0, 'info', array(), get_current_user_id());
            $this->set_notice(__('设置已保存。', 'jiuliu-usdt-payment'));
        }
        $this->redirect('settings');
    }

    public function test_api()
    {
        $this->require_admin_action('jiuliu_usdt_test_api');
        $result = $this->trongrid->test_connection();
        if (is_wp_error($result)) {
            $this->set_notice(sprintf(__('TronGrid 测试失败：%s', 'jiuliu-usdt-payment'), $result->get_error_message()), 'error');
        } else {
            $this->set_notice(sprintf(__('TronGrid 连接正常；最近 24 小时读取到 %d 条已确认入账记录。', 'jiuliu-usdt-payment'), $result['transfer_count']));
        }
        $this->redirect('status');
    }

    public function test_rate()
    {
        $this->require_admin_action('jiuliu_usdt_test_rate');
        $result = $this->rate->get_rate(true);
        if (!empty($result['fallback'])) {
            $this->set_notice(sprintf(__('自动汇率失败，已回退固定汇率 %1$s：%2$s', 'jiuliu-usdt-payment'), $result['rate'], $result['error']), 'warning');
        } else {
            $this->set_notice(sprintf(__('汇率读取正常：1 USDT = %1$s CNY（来源：%2$s）。', 'jiuliu-usdt-payment'), $result['rate'], $result['source']));
        }
        $this->redirect('status');
    }

    public function run_scan()
    {
        $this->require_admin_action('jiuliu_usdt_run_scan');
        $result = jiuliu_usdt_payment()->cron->run();
        if (!empty($result['error'])) {
            $this->set_notice(sprintf(__('扫描失败：%s', 'jiuliu-usdt-payment'), $result['error']), 'error');
            $this->redirect('orders');
        }
        if (!empty($result['paused'])) {
            $this->set_notice(__('链上监控处于紧急暂停状态，本次没有扫描。', 'jiuliu-usdt-payment'), 'warning');
            $this->redirect('orders');
        }
        if (!empty($result['busy'])) {
            $this->set_notice(__('另一轮链上扫描正在运行，本次请求未重复执行。', 'jiuliu-usdt-payment'), 'warning');
            $this->redirect('orders');
        }
        if (!empty($result['partial']) || !empty($result['errors'])) {
            $this->set_notice(sprintf(
                __('扫描未完全成功：检查 %1$d 个支付单，完成 %2$d 个，转人工 %3$d 个，错误 %4$d 个。请查看日志并重试。', 'jiuliu-usdt-payment'),
                isset($result['checked']) ? $result['checked'] : 0,
                isset($result['paid']) ? $result['paid'] : 0,
                isset($result['review']) ? $result['review'] : 0,
                isset($result['errors']) ? $result['errors'] : 0
            ), 'warning');
            $this->redirect('orders');
        }
        $this->set_notice(sprintf(
            __('扫描完成：检查 %1$d 个支付单，完成 %2$d 个，转人工 %3$d 个。', 'jiuliu-usdt-payment'),
            isset($result['checked']) ? $result['checked'] : 0,
            isset($result['paid']) ? $result['paid'] : 0,
            isset($result['review']) ? $result['review'] : 0
        ));
        $this->redirect('orders');
    }

    public function rotate_cron_token()
    {
        $this->require_admin_action('jiuliu_usdt_rotate_cron_token');
        $result = $this->settings->rotate_cron_token();
        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
        } else {
            $this->db->log('cron_token_rotated', __('管理员更换了外部 Cron 密钥。', 'jiuliu-usdt-payment'), 0, 'warning', array(), get_current_user_id());
            $this->set_notice(__('Cron 密钥已更换。请立即更新服务器定时任务，旧密钥已失效。', 'jiuliu-usdt-payment'), 'warning');
        }
        $this->redirect('status');
    }

    public function check_invoice()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $this->require_admin_action('jiuliu_usdt_check_invoice_' . $invoice_id);
        $invoice = $this->db->get_invoice($invoice_id);
        if (!$invoice) {
            $this->set_notice(__('支付单不存在。', 'jiuliu-usdt-payment'), 'error');
        } else {
            $result = $this->invoices->check_order($invoice->zibll_order_num, true);
            if (is_wp_error($result)) {
                $this->set_notice($result->get_error_message(), 'error');
            } else {
                $this->set_notice(__('链上状态检查完成。', 'jiuliu-usdt-payment'));
            }
        }
        $this->redirect('orders');
    }

    public function verify_invoice()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $this->require_admin_action('jiuliu_usdt_verify_invoice_' . $invoice_id);
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
            $this->set_notice(__('此支付单的子比结算结果不确定。必须先核对权益、库存、发货和通知，并同时勾选“强制补单”与重复结算风险确认。', 'jiuliu-usdt-payment'), 'error');
            $this->redirect('orders');
        }

        $result = $this->call_verify_admin_txid($invoice_id, $txid, $force, $confirm_uncertain);
        if (is_wp_error($result)) {
            $this->set_notice($result->get_error_message(), 'error');
        } elseif ('paid' === $result->status) {
            $this->set_notice(__('交易核验通过，子比订单已经完成。', 'jiuliu-usdt-payment'));
        } elseif ('review' === $result->status) {
            $this->set_notice(__('交易已确认，但金额、时间或子比订单状态异常，仍需人工处理。', 'jiuliu-usdt-payment'), 'warning');
        } else {
            $this->set_notice(__('交易核验完成。', 'jiuliu-usdt-payment'));
        }
        $this->redirect('orders');
    }

    private function call_verify_admin_txid($invoice_id, $txid, $force, $confirm_uncertain)
    {
        $method = new ReflectionMethod($this->invoices, 'verify_admin_txid');
        if ($method->getNumberOfParameters() >= 4) {
            return $this->invoices->verify_admin_txid($invoice_id, $txid, $force, $confirm_uncertain);
        }

        // Compatibility with an older invoices service. The administrator
        // confirmation is still enforced above for uncertain settlements.
        return $this->invoices->verify_admin_txid($invoice_id, $txid, $force);
    }

    public function reject_invoice()
    {
        $invoice_id = isset($_POST['invoice_id']) ? absint($_POST['invoice_id']) : 0;
        $this->require_admin_action('jiuliu_usdt_reject_invoice_' . $invoice_id);
        $invoice = $this->db->get_invoice($invoice_id);
        if ($invoice && isset($invoice->error_code) && 'zibll_settlement_uncertain' === (string) $invoice->error_code) {
            $this->set_notice(__('该支付单的子比结算结果不确定，不能标记为拒绝；请先核对已发权益、库存、发货和通知。', 'jiuliu-usdt-payment'), 'error');
            $this->redirect('orders');
        }
        if ($this->db->reject_invoice($invoice_id)) {
            $this->db->log('invoice_rejected', __('管理员拒绝了支付单。', 'jiuliu-usdt-payment'), $invoice_id, 'warning', array(), get_current_user_id());
            $this->set_notice(__('支付单已标记为拒绝。', 'jiuliu-usdt-payment'));
        } else {
            $this->set_notice(__('支付单状态已经变化；已支付或正在结算的支付单不能拒绝。', 'jiuliu-usdt-payment'), 'error');
        }
        $this->redirect('orders');
    }

    private function render_settings()
    {
        $s = $this->settings->all();
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="jiuliu-usdt-settings">';
        echo '<input type="hidden" name="action" value="jiuliu_usdt_save_settings">';
        wp_nonce_field('jiuliu_usdt_save_settings');

        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('基础收款', 'jiuliu-usdt-payment') . '</h2><table class="form-table"><tbody>';
        $this->checkbox_row('enabled', __('接受新的 USDT-TRC20 订单', 'jiuliu-usdt-payment'), $s['enabled'], __('启用后 USDT 会出现在子比收银台；关闭只阻止新报价，已经签发给用户的支付单仍会继续安全核验。', 'jiuliu-usdt-payment'));
        $this->text_row('receive_address', __('TRC20 收款地址', 'jiuliu-usdt-payment'), $s['receive_address'], 'T...', __('只填写公开收款地址，绝不要填写私钥或助记词。', 'jiuliu-usdt-payment'));
        echo '<tr><th>' . esc_html__('USDT 合约', 'jiuliu-usdt-payment') . '</th><td><code>' . esc_html(JIULIU_USDT_Settings::USDT_CONTRACT) . '</code><p class="description">' . esc_html__('TRON 主网官方 USDT 合约，插件内固定且不可修改。', 'jiuliu-usdt-payment') . '</p></td></tr>';
        $this->secret_row('trongrid_api_key', __('TronGrid API Key', 'jiuliu-usdt-payment'), $this->settings->masked_api_key('trongrid_api_key'), __('可留空，但生产环境建议配置只读 API Key 以提高查询额度。', 'jiuliu-usdt-payment'));
        $this->number_row('trongrid_max_pages', __('TronGrid 单次最大页数', 'jiuliu-usdt-payment'), $s['trongrid_max_pages'], '1', __('允许 1 至 10 页，每页最多 200 条。该上限同时约束自动扫描和人工核验，降低异常请求的资源消耗。', 'jiuliu-usdt-payment'));
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('汇率与金额', 'jiuliu-usdt-payment') . '</h2><table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('汇率模式', 'jiuliu-usdt-payment') . '</th><td><select name="settings[rate_mode]">'
            . '<option value="fixed" ' . selected($s['rate_mode'], 'fixed', false) . '>' . esc_html__('固定汇率', 'jiuliu-usdt-payment') . '</option>'
            . '<option value="auto" ' . selected($s['rate_mode'], 'auto', false) . '>' . esc_html__('CoinGecko 市场参考汇率（失败回退固定）', 'jiuliu-usdt-payment') . '</option>'
            . '</select></td></tr>';
        $this->number_row('fixed_rate', __('固定/备用汇率', 'jiuliu-usdt-payment'), $s['fixed_rate'], '0.0001', __('每 1 USDT 对应多少人民币；安全范围 1 至 20。', 'jiuliu-usdt-payment'));
        $this->secret_row('coingecko_api_key', __('CoinGecko Demo Key', 'jiuliu-usdt-payment'), $this->settings->masked_api_key('coingecko_api_key'), __('可选；没有 Key 时会尝试公共接口，也可在 wp-config.php 定义 JIULIU_USDT_COINGECKO_API_KEY。CoinGecko 是第三方市场数据，仅作报价参考，并非 Tether 官方结算汇率。', 'jiuliu-usdt-payment'));
        $this->number_row('auto_rate_max_deviation', __('自动汇率偏差熔断（%）', 'jiuliu-usdt-payment'), $s['auto_rate_max_deviation'], '0.01', __('相对备用固定汇率的最大允许偏差；允许 1% 至 30%，默认 10%。', 'jiuliu-usdt-payment'));
        $this->number_row('rate_markup', __('汇率加成（%）', 'jiuliu-usdt-payment'), $s['rate_markup'], '0.0001', __('正数增加应付 USDT，负数提供优惠；允许 -50% 至 100%。', 'jiuliu-usdt-payment'));
        $this->number_row('minimum_local_amount', __('最低订单金额', 'jiuliu-usdt-payment'), $s['minimum_local_amount'], '0.01', __('金额过低时插件会拒绝生成尾数空间不足的共享地址报价。', 'jiuliu-usdt-payment'));
        $this->number_row('maximum_local_amount', __('最高订单金额', 'jiuliu-usdt-payment'), $s['maximum_local_amount'], '0.01', '');
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('核验、安全与通知', 'jiuliu-usdt-payment') . '</h2><table class="form-table"><tbody>';
        $this->checkbox_row('pause_monitoring', __('紧急暂停全部自动监控与结算', 'jiuliu-usdt-payment'), $s['pause_monitoring'], __('仅用于故障处置。开启后现有支付单也不会被浏览器轮询或定时任务自动核验；恢复前必须人工检查是否已有用户转账。', 'jiuliu-usdt-payment'));
        $this->number_row('invoice_timeout', __('备用有效期（分钟）', 'jiuliu-usdt-payment'), $s['invoice_timeout'], '1', __('正常情况下严格使用子比原生订单截止时间；仅在主题未返回截止时间时使用此备用值。', 'jiuliu-usdt-payment'));
        $this->number_row('late_grace_hours', __('过期到账观察期（小时）', 'jiuliu-usdt-payment'), $s['late_grace_hours'], '1', __('过期到账只记录并转人工，不会自动发货。', 'jiuliu-usdt-payment'));
        $this->checkbox_row('monitor_closed_orders', __('子比订单关闭后继续观察到账', 'jiuliu-usdt-payment'), $s['monitor_closed_orders'], __('推荐启用：观察期内发现到账会转人工处理，绝不自动发货。关闭此开关可节省链上查询额度，但可能错过“先转账、后关闭”的到账。', 'jiuliu-usdt-payment'));
        $this->checkbox_row('frontend_manual_txid', __('允许前台提交交易哈希', 'jiuliu-usdt-payment'), $s['frontend_manual_txid'], __('用于交易所截断小数、少付、多付等异常核验。', 'jiuliu-usdt-payment'));
        $this->checkbox_row('admin_email_notifications', __('管理员邮件通知', 'jiuliu-usdt-payment'), $s['admin_email_notifications'], '');
        $this->checkbox_row('user_email_notifications', __('用户成功邮件', 'jiuliu-usdt-payment'), $s['user_email_notifications'], '');
        $this->number_row('log_retention_days', __('日志保留天数', 'jiuliu-usdt-payment'), $s['log_retention_days'], '1', '');
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('外部系统 Cron', 'jiuliu-usdt-payment') . '</h2><table class="form-table"><tbody>';
        $this->text_row('cron_token', __('Cron 密钥', 'jiuliu-usdt-payment'), $s['cron_token'], '', __('至少 32 位。也可在 wp-config.php 中定义 JIULIU_USDT_CRON_TOKEN。', 'jiuliu-usdt-payment'));
        echo '<tr><th>' . esc_html__('IP 白名单', 'jiuliu-usdt-payment') . '</th><td><textarea name="settings[cron_ip_allowlist]" rows="4" class="large-text code">' . esc_textarea($s['cron_ip_allowlist']) . '</textarea><p class="description">' . esc_html__('可选，每行或逗号分隔一个 IPv4/IPv6；留空不限制来源 IP。', 'jiuliu-usdt-payment') . '</p></td></tr>';
        echo '</tbody></table></div>';

        submit_button(__('保存设置', 'jiuliu-usdt-payment'));
        echo '</form>';
    }

    private function checkbox_row($key, $label, $checked, $description)
    {
        echo '<tr><th>' . esc_html($label) . '</th><td><label><input type="checkbox" name="settings[' . esc_attr($key) . ']" value="1" ' . checked($checked, 1, false) . '> ' . esc_html__('启用', 'jiuliu-usdt-payment') . '</label>';
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
        echo '<tr><th><label for="jiuliu-' . esc_attr($key) . '">' . esc_html($label) . '</label></th><td><input id="jiuliu-' . esc_attr($key) . '" class="regular-text code" type="password" name="settings[' . esc_attr($key) . ']" value="" autocomplete="new-password" placeholder="' . esc_attr($masked ?: __('尚未配置', 'jiuliu-usdt-payment')) . '">';
        echo '<label class="jiuliu-clear-secret"><input type="checkbox" name="settings[clear_' . esc_attr($key) . ']" value="1"> ' . esc_html__('清除已保存值', 'jiuliu-usdt-payment') . '</label>';
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

        echo '<div class="jiuliu-usdt-toolbar">';
        echo '<form method="get"><input type="hidden" name="page" value="jiuliu-usdt"><input type="hidden" name="tab" value="orders">';
        echo '<select name="status"><option value="">' . esc_html__('全部状态', 'jiuliu-usdt-payment') . '</option>';
        foreach (array('pending', 'paid', 'review', 'expired', 'closed', 'closed_no_monitor', 'superseded', 'rejected') as $value) {
            echo '<option value="' . esc_attr($value) . '" ' . selected($status, $value, false) . '>' . esc_html($this->invoices->status_label($value)) . '</option>';
        }
        echo '</select> <input type="search" name="s" value="' . esc_attr($search) . '" placeholder="' . esc_attr__('订单号或交易哈希', 'jiuliu-usdt-payment') . '"> ';
        submit_button(__('筛选', 'jiuliu-usdt-payment'), 'secondary', '', false);
        echo '</form>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_usdt_run_scan">';
        wp_nonce_field('jiuliu_usdt_run_scan');
        submit_button(__('立即扫描链上到账', 'jiuliu-usdt-payment'), 'secondary', '', false);
        echo '</form></div>';

        echo '<table class="widefat striped jiuliu-usdt-orders"><thead><tr>'
            . '<th>' . esc_html__('支付单', 'jiuliu-usdt-payment') . '</th>'
            . '<th>' . esc_html__('金额', 'jiuliu-usdt-payment') . '</th>'
            . '<th>' . esc_html__('状态', 'jiuliu-usdt-payment') . '</th>'
            . '<th>' . esc_html__('链上交易', 'jiuliu-usdt-payment') . '</th>'
            . '<th>' . esc_html__('时间', 'jiuliu-usdt-payment') . '</th>'
            . '<th>' . esc_html__('操作', 'jiuliu-usdt-payment') . '</th>'
            . '</tr></thead><tbody>';

        if (!$orders) {
            echo '<tr><td colspan="6">' . esc_html__('暂无 USDT 支付单。', 'jiuliu-usdt-payment') . '</td></tr>';
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
        $tx_link = $display_txid && JIULIU_USDT_Util::is_valid_txid($display_txid)
            ? 'https://tronscan.org/#/transaction/' . rawurlencode($display_txid)
            : '';
        echo '<tr>';
        echo '<td><strong>' . esc_html($invoice->invoice_no) . '</strong><br><code>' . esc_html($invoice->zibll_order_num) . '</code><br><span class="description">payment_id: ' . esc_html($invoice->payment_id) . '</span></td>';
        echo '<td><strong>' . esc_html($invoice->usdt_amount) . ' USDT</strong><br><span class="description">' . esc_html(number_format((float) $invoice->local_amount, 2)) . ' ' . esc_html($invoice->local_currency) . '<br>1 USDT = ' . esc_html(rtrim(rtrim($invoice->rate, '0'), '.')) . '</span></td>';
        echo '<td><span class="jiuliu-status jiuliu-status-' . esc_attr($invoice->status) . '">' . esc_html($this->invoices->status_label($invoice->status)) . '</span>';
        if ($invoice->note) {
            echo '<p class="description">' . esc_html($invoice->note) . '</p>';
        }
        echo '</td>';
        echo '<td>';
        if ($tx_link) {
            echo '<a href="' . esc_url($tx_link) . '" target="_blank" rel="noopener noreferrer"><code>' . esc_html(substr($display_txid, 0, 12) . '…' . substr($display_txid, -8)) . '</code></a>';
            if (!$invoice->txid && $invoice->submitted_txid) {
                echo '<br><span class="description">' . esc_html__('仅为用户提交，尚未绑定到此支付单', 'jiuliu-usdt-payment') . '</span>';
            }
            if ($invoice->actual_amount) {
                echo '<br><span class="description">' . esc_html($invoice->actual_amount) . ' USDT</span>';
            }
        } else {
            echo '—';
        }
        echo '</td>';
        echo '<td><span title="UTC">' . esc_html(JIULIU_USDT_Util::display_datetime($invoice->created_at)) . '</span><br><span class="description">' . esc_html__('到期：', 'jiuliu-usdt-payment') . esc_html(JIULIU_USDT_Util::display_datetime($invoice->expires_at)) . '</span></td>';
        echo '<td class="jiuliu-order-actions">';

        if (in_array($invoice->status, array('pending', 'expired', 'closed', 'closed_no_monitor'), true)) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_usdt_check_invoice"><input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            wp_nonce_field('jiuliu_usdt_check_invoice_' . $invoice->id);
            submit_button(__('检查', 'jiuliu-usdt-payment'), 'small secondary', '', false);
            echo '</form>';
        }

        if (in_array($invoice->status, array('pending', 'expired', 'review', 'superseded', 'closed', 'closed_no_monitor'), true)) {
            $is_uncertain = isset($invoice->error_code) && 'zibll_settlement_uncertain' === $invoice->error_code;
            echo '<details><summary>' . esc_html__('核验/补单', 'jiuliu-usdt-payment') . '</summary><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_usdt_verify_invoice"><input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            wp_nonce_field('jiuliu_usdt_verify_invoice_' . $invoice->id);
            echo '<input type="text" name="txid" maxlength="64" value="' . esc_attr($invoice->txid ?: $invoice->submitted_txid) . '" placeholder="' . esc_attr__('64 位交易哈希', 'jiuliu-usdt-payment') . '" required>';
            echo '<label><input type="checkbox" name="force" value="1"' . ($is_uncertain ? ' required' : '') . '> ' . esc_html__('金额/时间异常时尝试强制补单（关闭的商城订单仍会被安全拦截）', 'jiuliu-usdt-payment') . '</label>';
            if ($is_uncertain) {
                echo '<div class="notice notice-error inline"><p><strong>' . esc_html__('高风险：此前子比结算结果不确定。再次结算可能重复开通权益、扣减库存、发货或发送通知。', 'jiuliu-usdt-payment') . '</strong></p></div>';
                echo '<label><input type="checkbox" name="confirm_uncertain_settlement" value="1" required> ' . esc_html__('我已核对会员/余额/内容权限、库存、发货及通知，理解重复结算风险，仍确认重试。', 'jiuliu-usdt-payment') . '</label>';
            }
            submit_button(__('核验交易', 'jiuliu-usdt-payment'), 'small primary', '', false);
            echo '</form></details>';
        }

        $can_reject = in_array($invoice->status, array('pending', 'expired', 'review', 'superseded', 'closed', 'closed_no_monitor'), true)
            && (!isset($invoice->error_code) || 'zibll_settlement_uncertain' !== (string) $invoice->error_code);
        if ($can_reject) {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('确定拒绝此支付单？此操作不会退回链上资产。', 'jiuliu-usdt-payment')) . '\');"><input type="hidden" name="action" value="jiuliu_usdt_reject_invoice"><input type="hidden" name="invoice_id" value="' . esc_attr($invoice->id) . '">';
            wp_nonce_field('jiuliu_usdt_reject_invoice_' . $invoice->id);
            submit_button(__('拒绝', 'jiuliu-usdt-payment'), 'small delete', '', false);
            echo '</form>';
        }
        echo '</td></tr>';
    }

    private function render_logs()
    {
        $page = isset($_GET['paged']) ? max(1, absint($_GET['paged'])) : 1;
        $logs = $this->db->list_logs($page, 50);
        echo '<div class="jiuliu-usdt-card"><p>' . esc_html__('日志不会记录 API Key、Cron 密钥或支付单公开令牌。', 'jiuliu-usdt-payment') . '</p></div>';
        echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__('时间', 'jiuliu-usdt-payment') . '</th><th>' . esc_html__('级别/事件', 'jiuliu-usdt-payment') . '</th><th>' . esc_html__('支付单', 'jiuliu-usdt-payment') . '</th><th>' . esc_html__('内容', 'jiuliu-usdt-payment') . '</th></tr></thead><tbody>';
        if (!$logs) {
            echo '<tr><td colspan="5">' . esc_html__('暂无日志。', 'jiuliu-usdt-payment') . '</td></tr>';
        }
        foreach ($logs as $log) {
            echo '<tr><td>' . esc_html($log->id) . '</td><td>' . esc_html(JIULIU_USDT_Util::display_datetime($log->created_at)) . '</td><td><code>' . esc_html($log->level) . '</code><br>' . esc_html($log->event) . '</td><td>' . esc_html($log->invoice_id ?: '—') . '</td><td>' . esc_html($log->message);
            if ($log->context) {
                echo '<details><summary>' . esc_html__('上下文', 'jiuliu-usdt-payment') . '</summary><pre>' . esc_html($log->context) . '</pre></details>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function render_status()
    {
        $zibll = jiuliu_usdt_payment()->zibll->status();
        $next_cron = wp_next_scheduled(JIULIU_USDT_Cron::EVENT);
        $endpoint = rest_url('jiuliu-usdt/v1/cron');
        $token = $this->settings->get('cron_token');
        $backoff_remaining = JIULIU_USDT_Trongrid::backoff_remaining();
        $transactional_tables = $this->db->settlement_tables_are_transactional();
        $status_rows = array(
            __('WordPress', 'jiuliu-usdt-payment')       => get_bloginfo('version'),
            __('PHP', 'jiuliu-usdt-payment')             => PHP_VERSION,
            __('64 位整数', 'jiuliu-usdt-payment')       => PHP_INT_SIZE >= 8 ? __('正常', 'jiuliu-usdt-payment') : __('不支持', 'jiuliu-usdt-payment'),
            __('父主题', 'jiuliu-usdt-payment')          => $zibll['is_zibll'] ? 'Zibll ' . $zibll['version'] : get_template(),
            __('Zibll V9 支付接口', 'jiuliu-usdt-payment') => $zibll['api_ok'] ? __('正常', 'jiuliu-usdt-payment') : __('缺失', 'jiuliu-usdt-payment'),
            __('插件网关', 'jiuliu-usdt-payment')        => $this->settings->is_enabled() ? __('已启用', 'jiuliu-usdt-payment') : __('未启用/地址无效', 'jiuliu-usdt-payment'),
            __('既有支付单自动监控', 'jiuliu-usdt-payment') => $this->settings->get('pause_monitoring', 0) ? __('紧急暂停', 'jiuliu-usdt-payment') : __('运行中', 'jiuliu-usdt-payment'),
            __('关闭订单到账观察', 'jiuliu-usdt-payment') => $this->settings->get('monitor_closed_orders', 1) ? __('已启用（只转人工）', 'jiuliu-usdt-payment') : __('已停用', 'jiuliu-usdt-payment'),
            __('结算表事务引擎', 'jiuliu-usdt-payment')    => is_wp_error($transactional_tables) ? $transactional_tables->get_error_message() : __('正常（InnoDB）', 'jiuliu-usdt-payment'),
            __('TronGrid 查询退避', 'jiuliu-usdt-payment') => $backoff_remaining > 0 ? sprintf(__('剩余 %d 秒', 'jiuliu-usdt-payment'), $backoff_remaining) : __('正常', 'jiuliu-usdt-payment'),
            __('WP-Cron 下次运行', 'jiuliu-usdt-payment') => $next_cron ? wp_date('Y-m-d H:i:s', $next_cron) : __('未计划', 'jiuliu-usdt-payment'),
        );

        echo '<div class="jiuliu-usdt-status-grid">';
        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('兼容状态', 'jiuliu-usdt-payment') . '</h2><table class="widefat striped"><tbody>';
        foreach ($status_rows as $label => $value) {
            echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html($value) . '</td></tr>';
        }
        echo '</tbody></table></div>';

        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('连接测试', 'jiuliu-usdt-payment') . '</h2>';
        echo '<div class="jiuliu-usdt-inline-actions"><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_usdt_test_api">';
        wp_nonce_field('jiuliu_usdt_test_api');
        submit_button(__('测试 TronGrid', 'jiuliu-usdt-payment'), 'secondary', '', false);
        echo '</form><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="jiuliu_usdt_test_rate">';
        wp_nonce_field('jiuliu_usdt_test_rate');
        submit_button(__('测试汇率', 'jiuliu-usdt-payment'), 'secondary', '', false);
        echo '</form></div></div>';

        echo '<div class="jiuliu-usdt-card jiuliu-usdt-cron-card"><h2>' . esc_html__('服务器 Cron（推荐）', 'jiuliu-usdt-payment') . '</h2>';
        echo '<p>' . esc_html__('每分钟调用一次。建议使用请求头传密钥，避免密钥出现在代理访问日志中：', 'jiuliu-usdt-payment') . '</p>';
        echo '<pre><code>* * * * * curl -fsS -X POST -H "X-Jiuliu-Cron-Token: ' . esc_html($token) . '" "' . esc_html($endpoint) . '" &gt;/dev/null 2&gt;&amp;1</code></pre>';
        echo '<p class="description">' . esc_html__('接口只接受 POST，并且密钥只能放在 X-Jiuliu-Cron-Token 请求头；URL 查询参数不会被接受。WP-Cron 会继续作为兜底。', 'jiuliu-usdt-payment') . '</p>';
        if (defined('JIULIU_USDT_CRON_TOKEN')) {
            echo '<p class="description">' . esc_html__('当前密钥由 wp-config.php 中的 JIULIU_USDT_CRON_TOKEN 管理，请在服务器配置中更换。', 'jiuliu-usdt-payment') . '</p>';
        } else {
            echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return confirm(\'' . esc_js(__('更换后旧 Cron 密钥会立即失效。确定继续？', 'jiuliu-usdt-payment')) . '\');"><input type="hidden" name="action" value="jiuliu_usdt_rotate_cron_token">';
            wp_nonce_field('jiuliu_usdt_rotate_cron_token');
            submit_button(__('一键更换 Cron 密钥', 'jiuliu-usdt-payment'), 'secondary', '', false);
            echo '</form>';
        }
        echo '</div>';

        echo '<div class="jiuliu-usdt-card"><h2>' . esc_html__('安全说明', 'jiuliu-usdt-payment') . '</h2><ul class="ul-disc">';
        echo '<li>' . esc_html__('插件只读取公开链上数据，不保存、请求或使用钱包私钥和助记词。', 'jiuliu-usdt-payment') . '</li>';
        echo '<li>' . esc_html__('同一交易哈希只能结算一个支付单；到账金额、网络、合约、地址与时间都会核验。', 'jiuliu-usdt-payment') . '</li>';
        echo '<li>' . esc_html__('过期、少付、多付、支付方式已变更或商城订单已关闭时不会自动发货。', 'jiuliu-usdt-payment') . '</li>';
        echo '<li>' . esc_html__('USDT 退款必须由管理员在钱包中人工完成；插件绝不持有转账权限。', 'jiuliu-usdt-payment') . '</li>';
        echo '</ul></div></div>';
    }
}
