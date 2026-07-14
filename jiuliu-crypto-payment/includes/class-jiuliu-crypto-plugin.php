<?php

if (!defined('ABSPATH')) {
    exit;
}

final class JIULIU_CRYPTO_Plugin
{
    private static $instance;

    public $settings;
    public $routes;
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
        $this->settings = new JIULIU_CRYPTO_Settings();
        $this->routes   = new JIULIU_CRYPTO_Routes((array) $this->settings->get('payment_routes', array()));
        $this->db       = new JIULIU_CRYPTO_DB();
        $this->rate     = new JIULIU_CRYPTO_Rate($this->settings);
        $this->trongrid = new JIULIU_CRYPTO_Trongrid($this->settings);
        $this->invoices = new JIULIU_CRYPTO_Invoices($this->settings, $this->db, $this->rate, $this->trongrid, $this->routes);
        $this->zibll    = new JIULIU_CRYPTO_Zibll($this->settings, $this->db, $this->invoices, $this->routes);
        $this->ajax     = new JIULIU_CRYPTO_Ajax($this->settings, $this->db, $this->invoices);
        $this->cron     = new JIULIU_CRYPTO_Cron($this->settings, $this->db, $this->invoices);
        $this->admin    = new JIULIU_CRYPTO_Admin($this->settings, $this->db, $this->rate, $this->trongrid, $this->invoices, $this->routes);

        add_action('init', array($this, 'load_textdomain'));
        add_action('after_setup_theme', array($this->zibll, 'register'), 99);
    }

    public function load_textdomain()
    {
        load_plugin_textdomain(
            'jiuliu-crypto-payment',
            false,
            dirname(plugin_basename(JIULIU_CRYPTO_FILE)) . '/languages'
        );
    }

    public static function activate()
    {
        if (version_compare(PHP_VERSION, '7.0', '<') || PHP_INT_SIZE < 8) {
            deactivate_plugins(plugin_basename(JIULIU_CRYPTO_FILE));
            wp_die(esc_html__('九流网络多链支付需要 PHP 7.0 或更高版本，并且 PHP 必须为 64 位构建。', 'jiuliu-crypto-payment'));
        }

        $settings = new JIULIU_CRYPTO_Settings();
        $settings->install_defaults();

        $db = new JIULIU_CRYPTO_DB();
        $installed = $db->install();
        if (is_wp_error($installed)) {
            deactivate_plugins(plugin_basename(JIULIU_CRYPTO_FILE));
            wp_die(esc_html($installed->get_error_message()));
        }

        JIULIU_CRYPTO_Cron::schedule();
    }

    public static function deactivate()
    {
        JIULIU_CRYPTO_Cron::unschedule();
        delete_transient('jiuliu_crypto_scan_lock');
    }
}
