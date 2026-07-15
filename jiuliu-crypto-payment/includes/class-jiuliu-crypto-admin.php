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
    private $settings_draft;

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
        add_action('wp_ajax_jiuliu_crypto_toggle_route', array($this, 'toggle_route'));
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
        wp_enqueue_script(
            'jiuliu-crypto-admin',
            JIULIU_CRYPTO_URL . 'assets/js/admin.js',
            array(),
            JIULIU_CRYPTO_VERSION,
            true
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

    private function redirect($tab = 'orders', $anchor = '')
    {
        $url = add_query_arg(array('page' => 'jiuliu-crypto', 'tab' => $tab), admin_url('admin.php'));
        if ('' !== $anchor) {
            $url .= '#' . rawurlencode($anchor);
        }
        wp_safe_redirect($url);
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
        $settings_action = isset($_POST['jiuliu_settings_action'])
            ? sanitize_key(wp_unslash($_POST['jiuliu_settings_action']))
            : 'save_all';
        $route_id = isset($_POST['jiuliu_settings_route_id'])
            ? sanitize_key(wp_unslash($_POST['jiuliu_settings_route_id']))
            : '';
        $close_gateway = !empty($_POST['jiuliu_close_gateway']);
        $submitted_input = $input;
        $prepared = $this->prepare_settings_action($input, $old_settings, $settings_action, $route_id, $close_gateway);
        $result = is_wp_error($prepared) ? $prepared : $this->settings->update($input);
        if (is_wp_error($result)) {
            $message = $result->get_error_message();
            if ($this->submission_contains_new_secret($submitted_input)) {
                $message .= ' ' . __('本次保存失败，新填写的密钥尚未保存，请重新填写。', 'jiuliu-crypto-payment');
            }
            $this->remember_failed_route_draft($submitted_input, $route_id, $settings_action, $result);
            $this->set_notice($message, 'error');
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
                    $this->redirect('settings', $this->route_anchor($route_id));
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
            delete_transient($this->settings_draft_key());
            $this->db->log(
                'settings_saved',
                __('管理员更新了多链收款设置。', 'jiuliu-crypto-payment'),
                0,
                'info',
                array('settings_action' => $settings_action, 'route_id' => $route_id),
                get_current_user_id()
            );
            $this->set_notice($this->settings_action_notice($settings_action, $route_id, $result));
        }
        $this->redirect('settings', $this->route_anchor($route_id));
    }

    public function toggle_route()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array(
                'code' => 'forbidden',
                'message' => __('权限不足。', 'jiuliu-crypto-payment'),
            ), 403);
        }
        if (false === check_ajax_referer('jiuliu_crypto_toggle_route', 'nonce', false)) {
            wp_send_json_error(array(
                'code' => 'invalid_nonce',
                'message' => __('安全校验失败，请刷新页面后重试。', 'jiuliu-crypto-payment'),
            ), 403);
        }

        $route_id = isset($_POST['route_id']) ? sanitize_key(wp_unslash($_POST['route_id'])) : '';
        $enable = isset($_POST['enabled']) && '1' === (string) wp_unslash($_POST['enabled']);
        $close_gateway = !empty($_POST['close_gateway']);
        $presets = JIULIU_CRYPTO_Routes::presets();
        if ('' === $route_id || !isset($presets[$route_id])) {
            wp_send_json_error(array(
                'code' => 'invalid_route_id',
                'message' => __('支付路线不在插件内置白名单中。', 'jiuliu-crypto-payment'),
            ), 400);
        }

        $settings = $this->settings->all();
        $registry = new JIULIU_CRYPTO_Routes((array) $settings['payment_routes']);
        $routes = $registry->all(false);
        if (is_wp_error($registry->get_error()) || !isset($routes[$route_id])) {
            wp_send_json_error(array(
                'code' => 'route_not_available',
                'message' => __('支付路线配置不可用，请先检查插件路线数据。', 'jiuliu-crypto-payment'),
            ), 400);
        }

        $route = $routes[$route_id];
        $label = $this->route_label($route);
        if ($enable) {
            $validation = $this->validate_route_configuration($route_id, $route);
            if (is_wp_error($validation)) {
                wp_send_json_error(array(
                    'code' => 'route_incomplete',
                    'message' => sprintf(__('%1$s 配置不完整，无法启用：%2$s', 'jiuliu-crypto-payment'), $label, $validation->get_error_message()),
                ), 400);
            }
        }

        $enabled_count = count($registry->enabled());
        $gateway_was_enabled = !empty($settings['enabled']);
        $closed_gateway = false;
        $is_last_enabled = !$enable && !empty($route['enabled']) && 1 === $enabled_count;
        if ($gateway_was_enabled && $is_last_enabled) {
            if (!$close_gateway) {
                wp_send_json_error(array(
                    'code' => 'last_enabled_route',
                    'message' => __('这是当前最后一条已启用路线。停用后将没有可用的数字货币支付路线，是否同时关闭数字货币支付总网关？', 'jiuliu-crypto-payment'),
                ), 409);
            }
            $settings['enabled'] = 0;
            $closed_gateway = true;
        }

        $routes[$route_id]['enabled'] = $enable ? 1 : 0;
        $settings['payment_routes'] = array_values($routes);
        $result = $this->settings->update($settings);
        if (is_wp_error($result)) {
            wp_send_json_error(array(
                'code' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 400);
        }

        $message = $enable
            ? sprintf(__('%s 已启用。', 'jiuliu-crypto-payment'), $label)
            : sprintf(__('%s 已停用，原配置已保留。', 'jiuliu-crypto-payment'), $label);
        if ($enable && empty($result['enabled'])) {
            $message .= ' ' . __('路线已启用，但数字货币支付总网关当前关闭。', 'jiuliu-crypto-payment');
        }
        if ($closed_gateway) {
            $message .= ' ' . __('数字货币支付总网关已同时关闭。', 'jiuliu-crypto-payment');
        }

        $this->db->log(
            $enable ? 'route_enabled' : 'route_disabled',
            $message,
            0,
            $enable ? 'info' : 'warning',
            array('route_id' => $route_id, 'gateway_enabled' => !empty($result['enabled'])),
            get_current_user_id()
        );
        wp_send_json_success(array(
            'route_id' => $route_id,
            'enabled' => $enable ? 1 : 0,
            'gateway_enabled' => empty($result['enabled']) ? 0 : 1,
            'enabled_count' => $enable
                ? $enabled_count + (empty($route['enabled']) ? 1 : 0)
                : max(0, $enabled_count - (!empty($route['enabled']) ? 1 : 0)),
            'runtime_label' => $enable ? __('已启用', 'jiuliu-crypto-payment') : __('已停用', 'jiuliu-crypto-payment'),
            'toggle_label' => $enable ? __('停用', 'jiuliu-crypto-payment') : __('启用', 'jiuliu-crypto-payment'),
            'message' => $message,
        ));
    }

    private function prepare_settings_action(&$input, $old_settings, $settings_action, $route_id, $close_gateway)
    {
        $allowed = array('save_all', 'save_route', 'save_and_enable_route', 'disable_route');
        if (!in_array($settings_action, $allowed, true)) {
            return new WP_Error('invalid_settings_action', __('设置保存动作无效，请刷新页面后重试。', 'jiuliu-crypto-payment'));
        }
        if ('save_all' === $settings_action) {
            return true;
        }

        $presets = JIULIU_CRYPTO_Routes::presets();
        if ('' === $route_id || !isset($presets[$route_id])) {
            return new WP_Error('invalid_route_id', __('支付路线不在插件内置白名单中。', 'jiuliu-crypto-payment'));
        }
        $old_registry = new JIULIU_CRYPTO_Routes((array) $old_settings['payment_routes']);
        $old_routes = $old_registry->all(false);
        if (is_wp_error($old_registry->get_error()) || !isset($old_routes[$route_id])) {
            return new WP_Error('route_not_available', __('支付路线配置不可用，请先检查插件路线数据。', 'jiuliu-crypto-payment'));
        }
        if (!isset($input['payment_routes']) || !is_array($input['payment_routes'])) {
            return new WP_Error('missing_payment_routes', __('支付路线表单数据缺失，请刷新页面后重试。', 'jiuliu-crypto-payment'));
        }

        $route_key = null;
        foreach ($input['payment_routes'] as $candidate_key => $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $candidate_id = isset($candidate['id']) ? sanitize_key(wp_unslash($candidate['id'])) : sanitize_key((string) $candidate_key);
            if ($candidate_id === $route_id) {
                $route_key = $candidate_key;
                break;
            }
        }
        if (null === $route_key) {
            return new WP_Error('missing_route_input', __('目标支付路线表单数据缺失，请刷新页面后重试。', 'jiuliu-crypto-payment'));
        }

        $target_route = $input['payment_routes'][$route_key];
        $persisted_enabled = empty($old_routes[$route_id]['enabled']) ? 0 : 1;
        if ('save_route' === $settings_action) {
            $target_route['enabled'] = $persisted_enabled;
        } elseif ('save_and_enable_route' === $settings_action) {
            $target_route['enabled'] = 1;
        } else {
            $target_route['enabled'] = 0;
            $is_last_enabled = $persisted_enabled && 1 === count($old_registry->enabled());
            if (!empty($old_settings['enabled']) && $is_last_enabled) {
                if (!$close_gateway) {
                    return new WP_Error(
                        'last_enabled_route_confirmation_required',
                        __('这是当前最后一条已启用路线。请确认同时关闭数字货币支付总网关后再停用。', 'jiuliu-crypto-payment')
                    );
                }
            }
        }

        // A route action is deliberately scoped to one route. Start from the
        // persisted settings so unsaved controls elsewhere on the page cannot
        // be written by this request, then replace only the submitted target.
        // wp_slash keeps persisted values intact when Settings::update()
        // applies the normal WordPress form unslashing and secret semantics.
        $merged = wp_slash($old_settings);
        $merged_routes = wp_slash($old_routes);
        $merged_routes[$route_id] = $target_route;
        $merged['payment_routes'] = array_values($merged_routes);
        if ('disable_route' === $settings_action
            && !empty($old_settings['enabled'])
            && $persisted_enabled
            && 1 === count($old_registry->enabled())
            && $close_gateway
        ) {
            $merged['enabled'] = 0;
        }
        $input = $merged;
        return true;
    }

    private function settings_action_notice($settings_action, $route_id, $result)
    {
        if ('save_all' === $settings_action) {
            return __('全部设置已保存。', 'jiuliu-crypto-payment');
        }
        $routes = new JIULIU_CRYPTO_Routes(isset($result['payment_routes']) ? (array) $result['payment_routes'] : array());
        $route = $routes->get_by_id($route_id, false);
        $label = $route ? $this->route_label($route) : __('支付路线', 'jiuliu-crypto-payment');
        if ('save_and_enable_route' === $settings_action) {
            $message = sprintf(__('%s 已保存并启用。', 'jiuliu-crypto-payment'), $label);
            if (empty($result['enabled'])) {
                $message .= ' ' . __('路线已启用，但数字货币支付总网关当前关闭。', 'jiuliu-crypto-payment');
            }
            return $message;
        }
        if ('disable_route' === $settings_action) {
            return sprintf(__('%s 已停用，原配置已保留。', 'jiuliu-crypto-payment'), $label);
        }
        return sprintf(__('%s 路线配置已保存。', 'jiuliu-crypto-payment'), $label);
    }

    private function route_label($route)
    {
        $symbol = isset($route['asset_symbol']) ? (string) $route['asset_symbol'] : '';
        $network = isset($route['network_label']) ? (string) $route['network_label'] : '';
        return trim($symbol . ' · ' . $network, ' ·');
    }

    private function validate_route_configuration($route_id, $route)
    {
        $presets = JIULIU_CRYPTO_Routes::presets();
        if (!isset($presets[$route_id]) || !is_array($route)) {
            return new WP_Error('invalid_route_id', __('支付路线不在插件内置白名单中。', 'jiuliu-crypto-payment'));
        }
        $candidate = $route;
        $candidate['id'] = $route_id;
        $candidate['enabled'] = 1;
        $normalized = JIULIU_CRYPTO_Routes::normalize(array($candidate));
        if (is_wp_error($normalized)) {
            return $normalized;
        }
        return reset($normalized);
    }

    private function route_configuration_status($route_id, $route)
    {
        if (!is_wp_error($this->validate_route_configuration($route_id, $route))) {
            return 'configured';
        }
        $has_configuration = !empty($route['receive_address'])
            || !empty($route['rpc_url'])
            || !empty($route['api_key'])
            || (!empty($route['rpc_headers']) && is_array($route['rpc_headers']));
        return $has_configuration ? 'incomplete' : 'unconfigured';
    }

    private function route_anchor($route_id)
    {
        return '' === $route_id ? '' : 'jiuliu-route-config-' . $this->route_dom_id($route_id);
    }

    private function settings_draft_key()
    {
        return 'jiuliu_crypto_settings_draft_' . get_current_user_id();
    }

    private function remember_failed_route_draft($input, $route_id, $settings_action, $error)
    {
        if ('save_all' === $settings_action || '' === $route_id || empty($input['payment_routes']) || !is_array($input['payment_routes'])) {
            return;
        }
        $candidate = null;
        foreach ($input['payment_routes'] as $candidate_key => $route_input) {
            if (!is_array($route_input)) {
                continue;
            }
            $candidate_id = isset($route_input['id']) ? sanitize_key(wp_unslash($route_input['id'])) : sanitize_key((string) $candidate_key);
            if ($candidate_id === $route_id) {
                $candidate = wp_unslash($route_input);
                break;
            }
        }
        if (!$candidate) {
            return;
        }
        $ordinary = array();
        foreach (array('enabled', 'receive_address', 'rpc_url', 'required_confirmations', 'rate_cny') as $field) {
            if (isset($candidate[$field]) && !is_array($candidate[$field])) {
                $ordinary[$field] = (string) $candidate[$field];
            }
        }
        set_transient($this->settings_draft_key(), array(
            'route_id' => $route_id,
            'settings_action' => $settings_action,
            'route' => $ordinary,
            'error_code' => $error->get_error_code(),
            'error_message' => $error->get_error_message(),
        ), 5 * MINUTE_IN_SECONDS);
    }

    private function load_settings_draft()
    {
        if (null !== $this->settings_draft) {
            return $this->settings_draft;
        }
        $draft = get_transient($this->settings_draft_key());
        delete_transient($this->settings_draft_key());
        $this->settings_draft = is_array($draft) ? $draft : array();
        return $this->settings_draft;
    }

    private function submission_contains_new_secret($input)
    {
        if (!empty($input['coingecko_api_key'])) {
            return true;
        }
        if (empty($input['payment_routes']) || !is_array($input['payment_routes'])) {
            return false;
        }
        foreach ($input['payment_routes'] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            if (!empty($candidate['api_key']) || !empty($candidate['rpc_headers_json'])) {
                return true;
            }
        }
        return false;
    }

    private function route_field_error($route_id, $field)
    {
        $draft = $this->load_settings_draft();
        if (empty($draft['route_id']) || $route_id !== $draft['route_id']) {
            return '';
        }
        $map = array(
            'missing_receiver' => 'receive_address',
            'invalid_evm_address' => 'receive_address',
            'invalid_tron_address' => 'receive_address',
            'missing_rpc_url' => 'rpc_url',
            'invalid_rpc_url' => 'rpc_url',
            'invalid_rpc_headers_json' => 'rpc_headers_json',
            'invalid_rpc_headers' => 'rpc_headers_json',
            'invalid_confirmations' => 'required_confirmations',
            'invalid_route_rate' => 'rate_cny',
        );
        $error_code = isset($draft['error_code']) ? (string) $draft['error_code'] : '';
        if (!isset($map[$error_code]) || $field !== $map[$error_code]) {
            return '';
        }
        return isset($draft['error_message']) ? (string) $draft['error_message'] : '';
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
        $routes = $this->routes && is_callable(array($this->routes, 'enabled')) ? $this->routes->enabled() : array();
        if (!$routes) {
            $this->set_notice(__('请先完整配置并启用至少一条支付路线，再测试对应币种汇率。', 'jiuliu-crypto-payment'), 'error');
            $this->redirect('status');
        }

        $messages = array();
        $has_warning = false;
        $seen_assets = array();
        foreach ($routes as $route) {
            $asset_key = isset($route['asset_id']) ? (string) $route['asset_id'] : '';
            $force = !isset($seen_assets[$asset_key]);
            $seen_assets[$asset_key] = true;
            $result = $this->rate->get_rate($force, $route['asset_symbol'], $route);
            $label = $route['asset_symbol'] . ' · ' . $route['network'];
            if (empty($result['rate'])) {
                $has_warning = true;
                $messages[] = sprintf(__('%1$s：报价不可用（%2$s）', 'jiuliu-crypto-payment'), $label, isset($result['error']) ? $result['error'] : __('未知错误', 'jiuliu-crypto-payment'));
            } elseif (!empty($result['fallback'])) {
                $has_warning = true;
                $messages[] = sprintf(__('%1$s：自动报价失败，使用固定汇率 %2$s（%3$s）', 'jiuliu-crypto-payment'), $label, $result['rate'], isset($result['error']) ? $result['error'] : __('接口不可用', 'jiuliu-crypto-payment'));
            } else {
                $messages[] = sprintf(__('%1$s：1 %2$s = %3$s CNY（来源：%4$s）', 'jiuliu-crypto-payment'), $label, $route['asset_symbol'], $result['rate'], $result['source']);
            }
        }
        $this->set_notice(implode('；', $messages), $has_warning ? 'warning' : 'success');
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
        $enabled_count = count($registry->enabled());
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" class="jiuliu-crypto-settings" data-jiuliu-settings-form>';
        echo '<input type="hidden" name="action" value="jiuliu_crypto_save_settings">';
        echo '<input type="hidden" name="jiuliu_settings_action" value="save_all" data-settings-action>';
        echo '<input type="hidden" name="jiuliu_settings_route_id" value="" data-settings-route-id>';
        echo '<input type="hidden" name="jiuliu_close_gateway" value="0" data-close-gateway>';
        wp_nonce_field('jiuliu_crypto_save_settings');

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('多链网关总开关', 'jiuliu-crypto-payment') . '</h2><table class="form-table"><tbody>';
        $this->checkbox_row('enabled', __('接受新的链上支付订单', 'jiuliu-crypto-payment'), $s['enabled'], __('启用后，收银台会将已启用路线归入一个数字货币入口，用户先选币种、再选网络；后台仍以真实路线独立核验。', 'jiuliu-crypto-payment'));
        echo '</tbody></table>';
        echo '<p class="jiuliu-gateway-state"><strong>' . esc_html__('总网关状态：', 'jiuliu-crypto-payment') . '</strong> <span data-gateway-runtime-label>'
            . esc_html(!empty($s['enabled']) ? __('已开启', 'jiuliu-crypto-payment') : __('已关闭', 'jiuliu-crypto-payment')) . '</span></p>';
        echo '<p class="jiuliu-gateway-error" data-gateway-error role="alert"' . ((!empty($s['enabled']) && 0 === $enabled_count) ? '' : ' hidden') . '>'
            . esc_html__('请先配置并启用至少一条支付路线，再开启数字货币支付总网关。', 'jiuliu-crypto-payment') . '</p>';
        if (empty($s['enabled']) && $enabled_count > 0) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('路线已启用，但数字货币支付总网关当前关闭。', 'jiuliu-crypto-payment') . '</p></div>';
        }
        echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('收款金额规则：网站必须完整收到收银台显示的精确金额。', 'jiuliu-crypto-payment') . '</strong> '
            . esc_html__('链上网络费及交易所提币手续费全部由付款方另行承担，不得从页面金额中扣除。', 'jiuliu-crypto-payment') . '</p></div></div>';

        $this->render_route_manager($routes, $s);

        echo '<div class="jiuliu-crypto-card"><h2>' . esc_html__('汇率与金额', 'jiuliu-crypto-payment') . '</h2><table class="form-table"><tbody>';
        echo '<tr><th>' . esc_html__('汇率模式', 'jiuliu-crypto-payment') . '</th><td><select name="settings[rate_mode]">'
            . '<option value="fixed" ' . selected($s['rate_mode'], 'fixed', false) . '>' . esc_html__('固定汇率', 'jiuliu-crypto-payment') . '</option>'
            . '<option value="auto" ' . selected($s['rate_mode'], 'auto', false) . '>' . esc_html__('CoinGecko 市场参考汇率（失败回退固定）', 'jiuliu-crypto-payment') . '</option>'
            . '</select></td></tr>';
        echo '<tr><th><label for="jiuliu-fixed_rate">' . esc_html__('全局备用汇率', 'jiuliu-crypto-payment') . '</label></th><td><input id="jiuliu-fixed_rate" type="number" min="1" max="30" step="0.00000001" name="settings[fixed_rate]" value="' . esc_attr($s['fixed_rate']) . '"><p class="description">' . esc_html__('仅在路线未提供汇率时使用；当前白名单均为美元或欧元稳定币，因此限制在 1 至 30 CNY，防止小数点误填造成大额少收。', 'jiuliu-crypto-payment') . '</p></td></tr>';
        $this->secret_row('coingecko_api_key', __('CoinGecko Demo Key', 'jiuliu-crypto-payment'), $this->settings->masked_api_key('coingecko_api_key'), __('可选；没有 Key 时会尝试公共接口，也可在 wp-config.php 定义 JIULIU_CRYPTO_COINGECKO_API_KEY。CoinGecko 是第三方市场数据，仅作报价参考，不是任何代币发行方的官方结算汇率。', 'jiuliu-crypto-payment'));
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

        submit_button(__('保存全部设置', 'jiuliu-crypto-payment'), 'primary', 'submit', true, array('data-save-all' => '1'));
        echo '</form>';
    }

    private function render_route_manager($routes, $settings)
    {
        $configured = array();
        $available = array();
        $configuration_states = array();
        $enabled_count = 0;
        foreach ($routes as $route_id => $route) {
            $state = $this->route_configuration_status($route_id, $route);
            $configuration_states[$route_id] = $state;
            if ('configured' === $state) {
                $configured[$route_id] = $route;
            } else {
                $available[$route_id] = $route;
            }
            if (!empty($route['enabled'])) {
                $enabled_count++;
            }
        }

        echo '<div class="jiuliu-crypto-card jiuliu-route-manager" data-route-manager'
            . ' data-route-toggle-url="' . esc_url(admin_url('admin-ajax.php')) . '"'
            . ' data-route-toggle-nonce="' . esc_attr(wp_create_nonce('jiuliu_crypto_toggle_route')) . '"'
            . ' data-gateway-enabled="' . (!empty($settings['enabled']) ? '1' : '0') . '"'
            . ' data-enabled-count="' . esc_attr($enabled_count) . '">';
        echo '<div class="jiuliu-route-manager-heading"><div><h2>' . esc_html__('币种与网络路线', 'jiuliu-crypto-payment') . '</h2>';
        echo '<p>' . esc_html__('配置状态和运行状态彼此独立。停用路线只停止新订单使用，不会清空地址、RPC、只读凭据、汇率或确认数。只填写公开收款地址和只读 RPC/API，绝不要填写私钥或助记词。', 'jiuliu-crypto-payment') . '</p></div>';
        echo '<span class="jiuliu-route-count" data-route-count-label>' . esc_html(sprintf(__('共 %1$d 条路线，已配置 %2$d 条，已启用 %3$d 条', 'jiuliu-crypto-payment'), count($routes), count($configured), $enabled_count)) . '</span></div>';

        if (!$routes) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('没有加载到内置支付路线。为防止配置丢失，请不要保存，并检查插件文件完整性。', 'jiuliu-crypto-payment') . '</p></div></div>';
            return;
        }

        $this->render_route_toolbar($routes);
        $this->render_configured_routes($configured, $configuration_states);
        $this->render_available_routes($available, $configuration_states);

        $draft = $this->load_settings_draft();
        $open_configurations = !empty($draft['route_id']) && isset($routes[$draft['route_id']]);
        echo '<details class="jiuliu-route-configurations" data-route-configurations aria-labelledby="jiuliu-route-configurations-title"' . ($open_configurations ? ' open' : '') . '>';
        echo '<summary><span class="jiuliu-route-configurations-summary"><strong id="jiuliu-route-configurations-title">' . esc_html(sprintf(__('路线详细配置（%d 条）', 'jiuliu-crypto-payment'), count($routes))) . '</strong><span>' . esc_html__('点击展开完整配置', 'jiuliu-crypto-payment') . '</span></span></summary>';
        echo '<div class="jiuliu-route-configurations-body">';
        echo '<div class="jiuliu-route-section-heading"><div><h3>' . esc_html__('路线详细配置', 'jiuliu-crypto-payment') . '</h3>';
        echo '<p class="description">' . esc_html__('每条路线仅有下方这一套真实表单。收起只隐藏正文，标题、表单值和未保存输入仍保留在页面中。', 'jiuliu-crypto-payment') . '</p></div>';
        echo '<div class="jiuliu-route-bulk-actions"><button type="button" class="button button-small" data-route-expand-all>' . esc_html__('展开全部路线', 'jiuliu-crypto-payment') . '</button> <button type="button" class="button button-small" data-route-collapse-all>' . esc_html__('收起全部路线', 'jiuliu-crypto-payment') . '</button></div></div>';
        foreach ($routes as $route_id => $route) {
            $this->render_route_config($route_id, $route, $configuration_states[$route_id]);
        }
        echo '</div></details>';
        echo '<p class="screen-reader-text" aria-live="polite" aria-atomic="true" data-route-live></p>';
        echo '</div>';
    }

    private function render_route_toolbar($routes)
    {
        $assets = array();
        foreach ($routes as $route) {
            $symbol = isset($route['asset_symbol']) ? strtoupper((string) $route['asset_symbol']) : '';
            if ('' !== $symbol && !isset($assets[$symbol])) {
                $assets[$symbol] = isset($route['asset_name']) ? (string) $route['asset_name'] : $symbol;
            }
        }
        ksort($assets, SORT_NATURAL | SORT_FLAG_CASE);

        echo '<div class="jiuliu-route-filter" role="search" aria-label="' . esc_attr__('筛选支付路线', 'jiuliu-crypto-payment') . '">';
        echo '<div class="jiuliu-route-filter-field jiuliu-route-filter-search"><label for="jiuliu-route-search">' . esc_html__('搜索路线', 'jiuliu-crypto-payment') . '</label><input id="jiuliu-route-search" type="search" class="regular-text" data-route-search placeholder="' . esc_attr__('币种、网络、链 ID 或路线 ID', 'jiuliu-crypto-payment') . '"></div>';
        echo '<div class="jiuliu-route-filter-field"><label for="jiuliu-route-config-filter">' . esc_html__('配置状态', 'jiuliu-crypto-payment') . '</label><select id="jiuliu-route-config-filter" data-route-config-filter><option value="all">' . esc_html__('全部配置状态', 'jiuliu-crypto-payment') . '</option><option value="configured">' . esc_html__('已配置', 'jiuliu-crypto-payment') . '</option><option value="incomplete">' . esc_html__('配置不完整', 'jiuliu-crypto-payment') . '</option><option value="unconfigured">' . esc_html__('尚未配置', 'jiuliu-crypto-payment') . '</option></select></div>';
        echo '<div class="jiuliu-route-filter-field"><label for="jiuliu-route-runtime-filter">' . esc_html__('运行状态', 'jiuliu-crypto-payment') . '</label><select id="jiuliu-route-runtime-filter" data-route-runtime-filter><option value="all">' . esc_html__('全部运行状态', 'jiuliu-crypto-payment') . '</option><option value="enabled">' . esc_html__('已启用', 'jiuliu-crypto-payment') . '</option><option value="disabled">' . esc_html__('已停用', 'jiuliu-crypto-payment') . '</option></select></div>';
        echo '<div class="jiuliu-route-filter-field"><label for="jiuliu-route-asset-filter">' . esc_html__('币种', 'jiuliu-crypto-payment') . '</label><select id="jiuliu-route-asset-filter" data-route-asset><option value="all">' . esc_html__('全部币种', 'jiuliu-crypto-payment') . '</option>';
        foreach ($assets as $symbol => $name) {
            echo '<option value="' . esc_attr($symbol) . '">' . esc_html($symbol . ' — ' . $name) . '</option>';
        }
        echo '</select></div></div>';
    }

    private function render_configured_routes($routes, $states)
    {
        echo '<section class="jiuliu-route-section" aria-labelledby="jiuliu-configured-routes-title"><div class="jiuliu-route-section-heading"><div><h3 id="jiuliu-configured-routes-title">' . esc_html__('已配置路线', 'jiuliu-crypto-payment') . '</h3><p class="description">' . esc_html__('这里同时保留已启用和已停用的完整路线。快捷启停会立即保存，编辑配置只会打开对应面板。', 'jiuliu-crypto-payment') . '</p></div></div>';
        if (!$routes) {
            echo '<div class="jiuliu-route-empty"><p>' . esc_html__('当前没有配置完整的支付路线。', 'jiuliu-crypto-payment') . '</p><button type="button" class="button button-primary" data-route-toggle-available aria-controls="jiuliu-route-available" aria-expanded="false">' . esc_html__('添加支付路线', 'jiuliu-crypto-payment') . '</button></div>';
        } else {
            echo '<div class="jiuliu-route-summary-list">';
            foreach ($routes as $route_id => $route) {
                $this->render_route_summary($route_id, $route, 'configured', $states[$route_id]);
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private function render_available_routes($routes, $states)
    {
        $groups = array();
        foreach ($routes as $route_id => $route) {
            $symbol = isset($route['asset_symbol']) ? strtoupper((string) $route['asset_symbol']) : __('其他', 'jiuliu-crypto-payment');
            if (!isset($groups[$symbol])) {
                $groups[$symbol] = array();
            }
            $groups[$symbol][$route_id] = $route;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

        echo '<details id="jiuliu-route-available" class="jiuliu-route-available" data-route-available><summary><span><strong>' . esc_html__('添加支付路线', 'jiuliu-crypto-payment') . '</strong><span>' . esc_html(sprintf(__('浏览 %d 条尚未完成配置的内置路线', 'jiuliu-crypto-payment'), count($routes))) . '</span></span></summary>';
        echo '<div class="jiuliu-route-available-body">';
        if (!$groups) {
            echo '<p class="jiuliu-route-empty">' . esc_html__('所有内置路线都已完成配置。', 'jiuliu-crypto-payment') . '</p>';
        }
        foreach ($groups as $symbol => $group_routes) {
            $first = reset($group_routes);
            $asset_name = $first && isset($first['asset_name']) ? (string) $first['asset_name'] : $symbol;
            echo '<details class="jiuliu-route-asset-group" data-route-group data-route-asset="' . esc_attr($symbol) . '"><summary><strong>' . esc_html($symbol) . '</strong><span>' . esc_html($asset_name) . '</span><span>' . esc_html(sprintf(__('%d 条待配置路线', 'jiuliu-crypto-payment'), count($group_routes))) . '</span></summary><div class="jiuliu-route-summary-list">';
            foreach ($group_routes as $route_id => $route) {
                $this->render_route_summary($route_id, $route, 'available', $states[$route_id]);
            }
            echo '</div></details>';
        }
        echo '<p class="jiuliu-route-no-results" hidden data-route-no-results>' . esc_html__('没有符合当前搜索和筛选条件的路线。', 'jiuliu-crypto-payment') . '</p>';
        echo '</div></details>';
    }

    private function render_route_summary($route_id, $route, $context, $configuration_state)
    {
        $dom_id = $this->route_dom_id($route_id);
        $target_id = 'jiuliu-route-config-' . $dom_id;
        $address_id = 'jiuliu-route-address-' . $dom_id;
        $enabled = !empty($route['enabled']);
        $asset_type = isset($route['asset_type']) ? (string) $route['asset_type'] : '';
        $issuer = isset($route['issuer_label']) ? (string) $route['issuer_label'] : __('内置白名单', 'jiuliu-crypto-payment');
        $type_label = 'custodial_peg' === $asset_type ? __('托管锚定', 'jiuliu-crypto-payment') : __('发行方原生', 'jiuliu-crypto-payment');
        $symbol = isset($route['asset_symbol']) ? strtoupper((string) $route['asset_symbol']) : '';
        $network = isset($route['network_label']) ? (string) $route['network_label'] : '';
        $address = isset($route['receive_address']) ? (string) $route['receive_address'] : '';
        $configuration_label = $this->route_configuration_label($configuration_state);
        $runtime_label = $enabled ? __('已启用', 'jiuliu-crypto-payment') : __('已停用', 'jiuliu-crypto-payment');
        $search = implode(' ', array(
            $route_id,
            $symbol,
            isset($route['asset_name']) ? $route['asset_name'] : '',
            $network,
            isset($route['network']) ? $route['network'] : '',
            isset($route['chain_key']) ? $route['chain_key'] : '',
            isset($route['chain_id']) ? $route['chain_id'] : '',
            $issuer,
            $asset_type,
            $configuration_label,
            $runtime_label,
        ));

        echo '<article class="jiuliu-route-summary" data-route-summary data-route-id="' . esc_attr($route_id) . '" data-route-status="' . esc_attr($enabled ? 'enabled' : 'disabled') . '" data-route-runtime-state="' . esc_attr($enabled ? 'enabled' : 'disabled') . '" data-route-config-state="' . esc_attr($configuration_state) . '" data-route-asset="' . esc_attr($symbol) . '" data-route-search="' . esc_attr($search) . '">';
        echo '<div class="jiuliu-route-summary-main"><div class="jiuliu-route-title"><strong>' . esc_html($symbol) . '</strong><span>' . esc_html($network) . '</span></div><div class="jiuliu-route-summary-states">';
        echo '<span class="jiuliu-route-config-state is-' . esc_attr($configuration_state) . '"><span>' . esc_html__('配置状态：', 'jiuliu-crypto-payment') . '</span><strong data-route-config-label>' . esc_html($configuration_label) . '</strong></span>';
        echo '<span class="jiuliu-route-runtime-state ' . ($enabled ? 'is-enabled' : 'is-disabled') . '"><span>' . esc_html__('运行状态：', 'jiuliu-crypto-payment') . '</span><strong data-route-runtime-label data-route-saved-label="' . esc_attr($runtime_label) . '">' . esc_html($runtime_label) . '</strong></span>';
        echo '</div></div>';
        echo '<div class="jiuliu-route-meta"><span>' . esc_html($issuer . ' · ' . $type_label) . '</span><span><span>' . esc_html__('收款地址：', 'jiuliu-crypto-payment') . '</span><code title="' . esc_attr($address) . '">' . esc_html($this->shorten_address($address, isset($route['adapter']) ? $route['adapter'] : '')) . '</code></span></div>';
        echo '<div class="jiuliu-route-summary-actions">';
        echo '<button type="button" class="button button-small" data-copy-source="' . esc_attr($address_id) . '"' . ('' === $address ? ' disabled' : '') . '>' . esc_html__('复制地址', 'jiuliu-crypto-payment') . '</button> ';
        if ('configured' === $context) {
            echo '<button type="button" class="button button-small ' . ($enabled ? 'button-link-delete' : 'button-primary') . '" data-route-toggle="' . ($enabled ? '0' : '1') . '" data-route-toggle-label="' . esc_attr($enabled ? __('停用', 'jiuliu-crypto-payment') : __('启用', 'jiuliu-crypto-payment')) . '" data-route-id="' . esc_attr($route_id) . '">' . esc_html($enabled ? __('停用', 'jiuliu-crypto-payment') : __('启用', 'jiuliu-crypto-payment')) . '</button> ';
            echo '<button type="button" class="button button-small" data-route-open="' . esc_attr($target_id) . '" aria-controls="' . esc_attr($target_id) . '" aria-expanded="false">' . esc_html__('编辑配置', 'jiuliu-crypto-payment') . '</button>';
        } else {
            echo '<button type="button" class="button button-primary button-small" data-route-open="' . esc_attr($target_id) . '" aria-controls="' . esc_attr($target_id) . '" aria-expanded="false">' . esc_html__('开始配置', 'jiuliu-crypto-payment') . '</button>';
        }
        echo '</div></article>';
    }

    private function render_route_config($route_id, $route, $configuration_state)
    {
        $dom_id = $this->route_dom_id($route_id);
        $config_id = 'jiuliu-route-config-' . $dom_id;
        $enabled_id = 'jiuliu-route-enabled-' . $dom_id;
        $address_id = 'jiuliu-route-address-' . $dom_id;
        $rpc_id = 'jiuliu-route-rpc-' . $dom_id;
        $rpc_headers_id = 'jiuliu-route-rpc-headers-' . $dom_id;
        $api_key_id = 'jiuliu-route-api-key-' . $dom_id;
        $confirmations_id = 'jiuliu-route-confirmations-' . $dom_id;
        $rate_id = 'jiuliu-route-rate-' . $dom_id;
        $base = 'settings[payment_routes][' . $route_id . ']';
        $display_route = $route;
        $draft = $this->load_settings_draft();
        $is_failed_route = !empty($draft['route_id']) && $route_id === $draft['route_id'];
        if ($is_failed_route && !empty($draft['route']) && is_array($draft['route'])) {
            foreach (array('receive_address', 'rpc_url', 'required_confirmations', 'rate_cny') as $field) {
                if (array_key_exists($field, $draft['route'])) {
                    $display_route[$field] = $draft['route'][$field];
                }
            }
        }
        $symbol = isset($route['asset_symbol']) ? (string) $route['asset_symbol'] : '';
        $network = isset($route['network_label']) ? (string) $route['network_label'] : '';
        $enabled = !empty($route['enabled']);
        $configuration_label = $this->route_configuration_label($configuration_state);
        $runtime_label = $enabled ? __('已启用', 'jiuliu-crypto-payment') : __('已停用', 'jiuliu-crypto-payment');
        $address_error = $this->route_field_error($route_id, 'receive_address');
        $rpc_error = $this->route_field_error($route_id, 'rpc_url');
        $headers_error = $this->route_field_error($route_id, 'rpc_headers_json');
        $confirmations_error = $this->route_field_error($route_id, 'required_confirmations');
        $rate_error = $this->route_field_error($route_id, 'rate_cny');

        echo '<details id="' . esc_attr($config_id) . '" class="jiuliu-route-config jiuliu-crypto-route-card" data-route-config data-route-id="' . esc_attr($route_id) . '" data-route-adapter="' . esc_attr(isset($route['adapter']) ? $route['adapter'] : '') . '" data-route-configured="' . ('configured' === $configuration_state ? '1' : '0') . '" data-route-config-state="' . esc_attr($configuration_state) . '" data-route-enabled="' . ($enabled ? '1' : '0') . '" data-route-runtime-state="' . ($enabled ? 'enabled' : 'disabled') . '"' . ($is_failed_route ? ' open' : '') . '>';
        echo '<summary><span><strong>' . esc_html($symbol . ' · ' . $network) . '</strong><span>' . esc_html(sprintf(__('配置状态：%1$s · 运行状态：%2$s', 'jiuliu-crypto-payment'), $configuration_label, $runtime_label)) . '</span></span><span class="jiuliu-route-config-hint">' . esc_html__('点击展开或收起配置', 'jiuliu-crypto-payment') . '</span></summary>';
        echo '<div class="jiuliu-crypto-route-body">';
        $this->render_route_hidden_fields($base, $route);
        echo '<div class="jiuliu-route-enable-control"><label for="' . esc_attr($enabled_id) . '"><input id="' . esc_attr($enabled_id) . '" type="checkbox" name="' . esc_attr($base . '[enabled]') . '" value="1" data-route-enabled-input ' . checked($enabled, true, false) . '> <strong>' . esc_html__('启用此路线', 'jiuliu-crypto-payment') . '</strong></label><span class="description" data-route-pending-status></span><span class="jiuliu-route-dirty-status" data-route-dirty-status aria-live="polite"></span></div>';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="' . esc_attr($address_id) . '">' . esc_html__('收款地址', 'jiuliu-crypto-payment') . '</label></th><td><div class="jiuliu-route-input-action"><input id="' . esc_attr($address_id) . '" class="large-text code" type="text" name="' . esc_attr($base . '[receive_address]') . '" value="' . esc_attr(isset($display_route['receive_address']) ? $display_route['receive_address'] : '') . '" placeholder="' . esc_attr('tron' === $route['adapter'] ? 'T...' : '0x...') . '"' . ($address_error ? ' aria-invalid="true" aria-describedby="' . esc_attr($address_id . '-server-error') . '"' : '') . '><button type="button" class="button" data-copy-source="' . esc_attr($address_id) . '">' . esc_html__('复制', 'jiuliu-crypto-payment') . '</button></div><p class="description">' . esc_html__('填写该网络上的公开收款地址；不要填写私钥或助记词。', 'jiuliu-crypto-payment') . '</p>' . $this->route_field_error_html($address_id, $address_error) . '</td></tr>';
        if ('evm' === $route['adapter']) {
            echo '<tr><th><label for="' . esc_attr($rpc_id) . '">' . esc_html__('HTTPS JSON-RPC', 'jiuliu-crypto-payment') . '</label></th><td><input id="' . esc_attr($rpc_id) . '" class="large-text code" type="url" name="' . esc_attr($base . '[rpc_url]') . '" value="' . esc_attr(isset($display_route['rpc_url']) ? $display_route['rpc_url'] : '') . '" placeholder="https://..."' . ($rpc_error ? ' aria-invalid="true" aria-describedby="' . esc_attr($rpc_id . '-server-error') . '"' : '') . '><p class="description">' . esc_html__('必须支持 eth_getLogs；公共 RPC 可能限流，生产环境建议使用自己的只读节点服务。', 'jiuliu-crypto-payment') . '</p>' . $this->route_field_error_html($rpc_id, $rpc_error) . '</td></tr>';
            $header_count = isset($route['rpc_headers']) && is_array($route['rpc_headers']) ? count($route['rpc_headers']) : 0;
            echo '<tr><th><label for="' . esc_attr($rpc_headers_id) . '">' . esc_html__('RPC 请求头 JSON', 'jiuliu-crypto-payment') . '</label></th><td><input id="' . esc_attr($rpc_headers_id) . '" class="large-text code" type="password" autocomplete="new-password" name="' . esc_attr($base . '[rpc_headers_json]') . '" value="" placeholder="' . esc_attr($header_count ? sprintf(__('已配置 %d 个，留空保留', 'jiuliu-crypto-payment'), $header_count) : '{"Authorization":"Bearer ..."}') . '"' . ($headers_error ? ' aria-invalid="true" aria-describedby="' . esc_attr($rpc_headers_id . '-server-error') . '"' : '') . '><label class="jiuliu-clear-secret"><input type="checkbox" name="' . esc_attr($base . '[clear_rpc_headers]') . '" value="1"> ' . esc_html__('清除已保存请求头', 'jiuliu-crypto-payment') . '</label><p class="description">' . esc_html__('可选；仅用于需要 Authorization 等只读凭据的 RPC。留空保留已配置值。', 'jiuliu-crypto-payment') . '</p>' . $this->route_field_error_html($rpc_headers_id, $headers_error) . '</td></tr>';
            echo '<input type="hidden" name="' . esc_attr($base . '[api_key]') . '" value="">';
        } else {
            echo '<input type="hidden" name="' . esc_attr($base . '[rpc_url]') . '" value="">';
            echo '<tr><th><label for="' . esc_attr($api_key_id) . '">' . esc_html__('TronGrid API Key', 'jiuliu-crypto-payment') . '</label></th><td><input id="' . esc_attr($api_key_id) . '" class="large-text code" type="password" autocomplete="new-password" name="' . esc_attr($base . '[api_key]') . '" value="" placeholder="' . esc_attr(!empty($route['api_key']) ? __('已配置，留空保留', 'jiuliu-crypto-payment') : __('尚未配置（可选）', 'jiuliu-crypto-payment')) . '"><label class="jiuliu-clear-secret"><input type="checkbox" name="' . esc_attr($base . '[clear_api_key]') . '" value="1"> ' . esc_html__('清除已保存值', 'jiuliu-crypto-payment') . '</label><p class="description">' . esc_html__('可选；留空会保留已保存的 API Key。生产环境建议配置只读 TronGrid Key 以降低限流风险。', 'jiuliu-crypto-payment') . '</p></td></tr>';
        }
        if ('evm' === $route['adapter']) {
            echo '<tr><th><label for="' . esc_attr($confirmations_id) . '">' . esc_html__('确认数', 'jiuliu-crypto-payment') . '</label></th><td><input id="' . esc_attr($confirmations_id) . '" type="number" min="1" max="1000" name="' . esc_attr($base . '[required_confirmations]') . '" value="' . esc_attr(isset($display_route['required_confirmations']) ? $display_route['required_confirmations'] : '') . '"' . ($confirmations_error ? ' aria-invalid="true" aria-describedby="' . esc_attr($confirmations_id . '-server-error') . '"' : '') . '>' . $this->route_field_error_html($confirmations_id, $confirmations_error) . '</td></tr>';
        } else {
            echo '<input type="hidden" name="' . esc_attr($base . '[required_confirmations]') . '" value="1">';
            echo '<tr><th>' . esc_html__('最终性', 'jiuliu-crypto-payment') . '</th><td>' . esc_html__('TronGrid walletsolidity 已固化区块（固定）', 'jiuliu-crypto-payment') . '</td></tr>';
        }
        $route_rate_max = 'EURC' === strtoupper($symbol) ? 30 : 20;
        echo '<tr><th><label for="' . esc_attr($rate_id) . '">' . esc_html__('CNY 固定/备用汇率', 'jiuliu-crypto-payment') . '</label></th><td><input id="' . esc_attr($rate_id) . '" type="number" min="1" max="' . esc_attr($route_rate_max) . '" step="0.00000001" name="' . esc_attr($base . '[rate_cny]') . '" value="' . esc_attr(isset($display_route['rate_cny']) ? $display_route['rate_cny'] : '') . '"' . ($rate_error ? ' aria-invalid="true" aria-describedby="' . esc_attr($rate_id . '-server-error') . '"' : '') . '><p class="description">1 ' . esc_html($symbol) . ' = ? CNY。' . esc_html__('请仔细核对；固定汇率会直接决定付款方应付数量，超出该稳定币可信锚点会拒绝保存或报价。', 'jiuliu-crypto-payment') . '</p>' . $this->route_field_error_html($rate_id, $rate_error) . '</td></tr>';
        echo '</tbody></table>';
        $this->render_route_advanced_info($route_id, $route);
        echo '<div class="jiuliu-route-config-actions"><button type="button" class="button" data-route-cancel>' . esc_html__('取消', 'jiuliu-crypto-payment') . '</button> ';
        echo '<button type="button" class="button" data-route-save="save_route">' . esc_html__('保存配置', 'jiuliu-crypto-payment') . '</button> ';
        if ($enabled) {
            echo '<button type="button" class="button button-secondary" data-route-save="disable_route">' . esc_html__('停用并保存', 'jiuliu-crypto-payment') . '</button>';
        } else {
            echo '<button type="button" class="button button-primary" data-route-save="save_and_enable_route">' . esc_html__('保存并启用此路线', 'jiuliu-crypto-payment') . '</button>';
        }
        echo '</div></div></details>';
    }

    private function route_configuration_label($state)
    {
        if ('configured' === $state) {
            return __('已配置', 'jiuliu-crypto-payment');
        }
        if ('incomplete' === $state) {
            return __('配置不完整', 'jiuliu-crypto-payment');
        }
        return __('尚未配置', 'jiuliu-crypto-payment');
    }

    private function route_field_error_html($field_id, $message)
    {
        if ('' === (string) $message) {
            return '';
        }
        return '<p id="' . esc_attr($field_id . '-server-error') . '" class="jiuliu-route-field-error" data-route-field-error="server" role="alert">' . esc_html($message) . '</p>';
    }

    private function render_route_hidden_fields($base, $route)
    {
        $fields = array('id', 'asset_id', 'asset_symbol', 'asset_name', 'asset_decimals', 'display_decimals', 'rate_provider_id', 'issuer_label', 'asset_type', 'adapter', 'network_label', 'chain_key', 'chain_id', 'contract_address', 'fee_symbol', 'scan_block_chunk', 'scan_max_blocks', 'scan_max_results', 'rpc_timeout');
        foreach ($fields as $field) {
            if (isset($route[$field]) && !is_array($route[$field])) {
                echo '<input type="hidden" name="' . esc_attr($base . '[' . $field . ']') . '" value="' . esc_attr($route[$field]) . '">';
            }
        }
    }

    private function render_route_advanced_info($route_id, $route)
    {
        $dom_id = $this->route_dom_id($route_id);
        $contract_id = 'jiuliu-route-contract-' . $dom_id;
        $asset_type = isset($route['asset_type']) ? (string) $route['asset_type'] : '';
        $issuer = isset($route['issuer_label']) ? (string) $route['issuer_label'] : __('内置白名单', 'jiuliu-crypto-payment');
        $type_label = 'custodial_peg' === $asset_type ? __('托管锚定资产', 'jiuliu-crypto-payment') : __('发行方原生资产', 'jiuliu-crypto-payment');
        $contract = isset($route['contract_address']) ? (string) $route['contract_address'] : '';
        $items = array(
            __('路线 ID', 'jiuliu-crypto-payment') => $route_id,
            __('资产 ID / 报价源', 'jiuliu-crypto-payment') => (isset($route['asset_id']) ? $route['asset_id'] : '') . ' / ' . (isset($route['rate_provider_id']) ? $route['rate_provider_id'] : ''),
            __('链标识', 'jiuliu-crypto-payment') => (isset($route['chain_key']) ? $route['chain_key'] : '') . ' / chain_id ' . (isset($route['chain_id']) ? $route['chain_id'] : ''),
            __('适配器', 'jiuliu-crypto-payment') => isset($route['adapter']) ? $route['adapter'] : '',
            __('精度', 'jiuliu-crypto-payment') => (isset($route['asset_decimals']) ? $route['asset_decimals'] : '') . ' / display ' . (isset($route['display_decimals']) ? $route['display_decimals'] : ''),
            __('手续费币种', 'jiuliu-crypto-payment') => isset($route['fee_symbol']) ? $route['fee_symbol'] : '',
            __('扫描参数', 'jiuliu-crypto-payment') => sprintf('chunk %s / blocks %s / results %s / timeout %ss', isset($route['scan_block_chunk']) ? $route['scan_block_chunk'] : '', isset($route['scan_max_blocks']) ? $route['scan_max_blocks'] : '', isset($route['scan_max_results']) ? $route['scan_max_results'] : '', isset($route['rpc_timeout']) ? $route['rpc_timeout'] : ''),
        );

        echo '<details class="jiuliu-route-advanced"><summary>' . esc_html__('高级与安全核验信息', 'jiuliu-crypto-payment') . '</summary><div class="jiuliu-route-advanced-body">';
        echo '<p><strong>' . esc_html__('资产来源：', 'jiuliu-crypto-payment') . '</strong> ' . esc_html($issuer . ' · ' . $type_label) . '</p>';
        if ('custodial_peg' === $asset_type) {
            echo '<p class="notice notice-warning inline"><strong>' . esc_html__('该路线是托管锚定版本，不等同于代币发行方在此链原生发行。', 'jiuliu-crypto-payment') . '</strong></p>';
        }
        echo '<div class="jiuliu-route-contract"><strong>' . esc_html__('白名单核验合约', 'jiuliu-crypto-payment') . '</strong><div class="jiuliu-route-code-line"><code id="' . esc_attr($contract_id) . '">' . esc_html($contract ?: '—') . '</code><button type="button" class="button button-small" data-copy-source="' . esc_attr($contract_id) . '"' . ('' === $contract ? ' disabled' : '') . '>' . esc_html__('复制合约', 'jiuliu-crypto-payment') . '</button></div></div>';
        echo '<dl class="jiuliu-route-metadata">';
        foreach ($items as $label => $value) {
            echo '<div><dt>' . esc_html($label) . '</dt><dd><code>' . esc_html($value) . '</code></dd></div>';
        }
        echo '</dl></div></details>';
    }

    private function shorten_address($address, $adapter = '')
    {
        $address = trim((string) $address);
        if ('' === $address) {
            return __('未配置地址', 'jiuliu-crypto-payment');
        }
        if (strlen($address) <= 20) {
            return $address;
        }
        $prefix_length = 'tron' === $adapter ? 8 : 10;
        return substr($address, 0, $prefix_length) . '…' . substr($address, -8);
    }

    private function route_dom_id($route_id)
    {
        $safe = sanitize_html_class((string) $route_id);
        return '' !== $safe ? $safe : md5((string) $route_id);
    }

    private function checkbox_row($key, $label, $checked, $description)
    {
        $id = 'jiuliu-' . $key;
        $marker = 'enabled' === $key ? ' data-gateway-enabled' : '';
        echo '<tr><th><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label></th><td><label><input id="' . esc_attr($id) . '" type="checkbox" name="settings[' . esc_attr($key) . ']" value="1"' . $marker . ' ' . checked($checked, 1, false) . '> ' . esc_html__('启用', 'jiuliu-crypto-payment') . '</label>';
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
