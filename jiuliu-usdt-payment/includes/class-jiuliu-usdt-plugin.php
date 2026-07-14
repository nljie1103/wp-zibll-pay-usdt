<?php

if (!defined('ABSPATH')) {
    exit;
}

final class JIULIU_USDT_Plugin
{
    private static $instance;

    public $settings;
    public $db;
    public $rate;
    public $trongrid;
    public $invoices;
    public $zibll;
    public $ajax;
    public $cron;
    public $admin;

    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->settings = new JIULIU_USDT_Settings();
        $this->db       = new JIULIU_USDT_DB();
        $this->rate     = new JIULIU_USDT_Rate($this->settings);
        $this->trongrid = new JIULIU_USDT_Trongrid($this->settings);
        $this->invoices = new JIULIU_USDT_Invoices($this->settings, $this->db, $this->rate, $this->trongrid);
        $this->zibll    = new JIULIU_USDT_Zibll($this->settings, $this->db, $this->invoices);
        $this->ajax     = new JIULIU_USDT_Ajax($this->settings, $this->db, $this->invoices);
        $this->cron     = new JIULIU_USDT_Cron($this->settings, $this->db, $this->invoices);
        $this->admin    = new JIULIU_USDT_Admin($this->settings, $this->db, $this->rate, $this->trongrid, $this->invoices);

        add_action('init', array($this, 'maybe_upgrade'), 1);
        add_action('init', array($this, 'load_textdomain'));
        add_action('after_setup_theme', array($this->zibll, 'register'), 99);
    }

    public function load_textdomain()
    {
        load_plugin_textdomain(
            'jiuliu-usdt-payment',
            false,
            dirname(plugin_basename(JIULIU_USDT_FILE)) . '/languages'
        );
    }

    public function maybe_upgrade()
    {
        if (get_option('jiuliu_usdt_db_version') !== JIULIU_USDT_DB_VERSION) {
            return $this->db->install();
        }

        return true;
    }

    public static function activate()
    {
        if (version_compare(PHP_VERSION, '7.0', '<') || PHP_INT_SIZE < 8) {
            deactivate_plugins(plugin_basename(JIULIU_USDT_FILE));
            wp_die(esc_html__('九流网络 USDT 支付需要 PHP 7.0 或更高版本，并且 PHP 必须为 64 位构建。', 'jiuliu-usdt-payment'));
        }

        $settings = new JIULIU_USDT_Settings();
        $settings->install_defaults();

        $db = new JIULIU_USDT_DB();
        $installed = $db->install();
        if (is_wp_error($installed)) {
            deactivate_plugins(plugin_basename(JIULIU_USDT_FILE));
            wp_die(esc_html($installed->get_error_message()));
        }

        JIULIU_USDT_Cron::schedule();
    }

    public static function deactivate()
    {
        JIULIU_USDT_Cron::unschedule();
        delete_transient('jiuliu_usdt_scan_lock');
        delete_transient(JIULIU_USDT_Trongrid::BACKOFF_TRANSIENT);
        delete_transient(JIULIU_USDT_Trongrid::FAILURE_TRANSIENT);
    }
}
