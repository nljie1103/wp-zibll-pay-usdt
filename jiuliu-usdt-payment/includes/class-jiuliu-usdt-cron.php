<?php

if (!defined('ABSPATH')) {
    exit;
}

class JIULIU_USDT_Cron
{
    const EVENT = 'jiuliu_usdt_scan_event';

    private $settings;
    private $db;
    private $invoices;
    private static $schedule_filter_added = false;

    public function __construct(JIULIU_USDT_Settings $settings, JIULIU_USDT_DB $db, JIULIU_USDT_Invoices $invoices)
    {
        $this->settings = $settings;
        $this->db = $db;
        $this->invoices = $invoices;

        add_action(self::EVENT, array($this, 'run'));
        add_action('rest_api_init', array($this, 'register_rest_route'));

        self::schedule();
    }

    public static function add_schedule($schedules)
    {
        $schedules['jiuliu_every_minute'] = array(
            'interval' => 60,
            'display'  => __('每分钟（九流 USDT）', 'jiuliu-usdt-payment'),
        );
        return $schedules;
    }

    public static function schedule()
    {
        if (!self::$schedule_filter_added) {
            add_filter('cron_schedules', array(__CLASS__, 'add_schedule'));
            self::$schedule_filter_added = true;
        }
        if (!wp_next_scheduled(self::EVENT)) {
            wp_schedule_event(time() + 30, 'jiuliu_every_minute', self::EVENT);
        }
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::EVENT);
    }

    public function run()
    {
        // Disabling the gateway stops new quotes but must not abandon invoices
        // already shown to customers. Only the separate emergency switch may
        // pause monitoring and automatic settlement of existing invoices.
        if ($this->settings->get('pause_monitoring', 0)) {
            return array('paused' => true);
        }

        $schema = $this->db->settlement_tables_are_transactional();
        if (is_wp_error($schema)) {
            return array(
                'error'      => $schema->get_error_message(),
                'error_code' => $schema->get_error_code(),
            );
        }

        if (get_transient('jiuliu_usdt_scan_lock')) {
            return array('busy' => true);
        }

        // A transient alone is a read-then-write race. The MySQL named lock is
        // the atomic cross-request owner; the transient remains a cheap fast
        // path that avoids opening another chain scan during the next 55s.
        if (!$this->db->acquire_scan_lock()) {
            return array('busy' => true);
        }

        set_transient('jiuliu_usdt_scan_lock', 1, 55);
        try {
            if (false === $this->db->recover_stale_processing(5)) {
                throw new RuntimeException(__('无法恢复超时的结算处理中支付单。', 'jiuliu-usdt-payment'));
            }
            $stats = $this->invoices->scan_pending();
            if (!is_array($stats)) {
                throw new RuntimeException(__('链上扫描返回了无效结果。', 'jiuliu-usdt-payment'));
            }
            if (false === $this->db->release_old_active_keys($this->settings->get('late_grace_hours', 24))) {
                throw new RuntimeException(__('无法释放已结束支付单的金额尾数。', 'jiuliu-usdt-payment'));
            }

            if (0 === ((int) gmdate('H')) && (int) gmdate('i') < 2) {
                if (false === $this->db->delete_old_logs($this->settings->get('log_retention_days', 90))) {
                    throw new RuntimeException(__('无法清理过期 USDT 日志。', 'jiuliu-usdt-payment'));
                }
            }

            return $stats;
        } catch (Throwable $e) {
            $this->db->log('cron_exception', $e->getMessage(), 0, 'error');
            return array('error' => $e->getMessage());
        } finally {
            delete_transient('jiuliu_usdt_scan_lock');
            $this->db->release_scan_lock();
        }
    }

    public function register_rest_route()
    {
        register_rest_route('jiuliu-usdt/v1', '/cron', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array($this, 'rest_run'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
    }

    public function rest_permission(WP_REST_Request $request)
    {
        $expected = (string) $this->settings->get('cron_token');
        $provided = (string) $request->get_header('x-jiuliu-cron-token');
        if (!$expected || !$provided || !hash_equals($expected, $provided)) {
            return new WP_Error('rest_forbidden', __('Cron 密钥无效。', 'jiuliu-usdt-payment'), array('status' => 403));
        }

        $allowlist = trim((string) $this->settings->get('cron_ip_allowlist'));
        if ($allowlist) {
            $allowed = preg_split('/[\s,]+/', $allowlist);
            $ip = JIULIU_USDT_Util::client_ip();
            if (!$ip || !in_array($ip, $allowed, true)) {
                return new WP_Error('rest_ip_forbidden', __('当前 IP 不在 Cron 白名单中。', 'jiuliu-usdt-payment'), array('status' => 403));
            }
        }

        return true;
    }

    public function rest_run()
    {
        $result = $this->run();
        $status = !empty($result['error'])
            ? 'error'
            : (!empty($result['paused'])
                ? 'paused'
                : (!empty($result['busy'])
                    ? 'busy'
                    : ((!empty($result['partial']) || !empty($result['errors'])) ? 'partial' : 'ok')));
        $response = rest_ensure_response(array(
            'success' => 'ok' === $status,
            'status'  => $status,
            'result'  => $result,
            'time'    => gmdate('c'),
        ));
        if (is_object($response) && is_callable(array($response, 'set_status'))) {
            if (in_array($status, array('error', 'partial', 'paused'), true)) {
                $response->set_status(503);
            } elseif ('busy' === $status) {
                $response->set_status(409);
            }
        }
        return $response;
    }
}
