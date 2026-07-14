<?php
/**
 * Plugin Name: 九流网络 USDT 支付
 * Plugin URI:  https://blog.jiuliu.org/
 * Update URI:  https://github.com/nljie1103/wp-zibll-pay-usdt
 * Description: 为子比（Zibll）主题提供 USDT-TRC20 原生收银台支付、链上自动核验与订单自动交付。
 * Version:     1.0.2
 * Author:      九流网络
 * Text Domain: jiuliu-usdt-payment
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.0
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('JIULIU_USDT_VERSION', '1.0.2');
define('JIULIU_USDT_DB_VERSION', '1.0.3');
define('JIULIU_USDT_FILE', __FILE__);
define('JIULIU_USDT_DIR', plugin_dir_path(__FILE__));
define('JIULIU_USDT_URL', plugin_dir_url(__FILE__));

require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-util.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-settings.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-db.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-rate.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-trongrid.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-invoices.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-zibll.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-ajax.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-cron.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-admin.php';
require_once JIULIU_USDT_DIR . 'includes/class-jiuliu-usdt-plugin.php';

register_activation_hook(__FILE__, array('JIULIU_USDT_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('JIULIU_USDT_Plugin', 'deactivate'));

function jiuliu_usdt_payment()
{
    return JIULIU_USDT_Plugin::instance();
}

add_action('plugins_loaded', 'jiuliu_usdt_payment', 20);
