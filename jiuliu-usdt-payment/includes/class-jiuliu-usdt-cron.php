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

    public function __construct(JIULIU_USDT_Settings $settings, JIULIU_USDT_DB $db, JIULIU_USDT_Invoices $invoices)
    {
        $this->settings = $settings;
        $this->db = $db;
        $this->invoices = $invoices;

        add_filter('cron_schedules', array(__CLASS__, 'add_schedule'));
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
        add_filter('cron_schedules', array(__CLASS__, 'add_schedule'));
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
        if (!$this->settings->is_enabled()) {
            return array('disabled' => true);
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
            $this->db->recover_stale_processing(5);
            $stats = $this->invoices->scan_pending();
            $this->db->release_old_active_keys($this->settings->get('late_grace_hours', 24));

            if (0 === ((int) gmdate('H')) && (int) gmdate('i') < 2) {
                $this->db->delete_old_logs($this->settings->get('log_retention_days', 90));
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
            'methods'             => array(WP_REST_Server::READABLE, WP_REST_Server::CREATABLE),
            'callback'            => array($this, 'rest_run'),
            'permission_callback' => array($this, 'rest_permission'),
        ));
    }

    public function rest_permission(WP_REST_Request $request)
    {
        $expected = (string) $this->settings->get('cron_token');
        $provided = (string) $request->get_header('x-jiuliu-cron-token');
        if (!$provided) {
            $provided = (string) $request->get_param('token');
        }
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
        return rest_ensure_response(array(
            'success' => true,
            'result'  => $this->run(),
            'time'    => gmdate('c'),
        ));
    }
}
