<?php
/**
 * Plugin Name: 九流网络多链加密货币支付
 * Plugin URI:  https://blog.jiuliu.org/
 * Update URI:  https://github.com/nljie1103/wp-zibll-pay-usdt
 * Description: 为子比（Zibll）主题提供多币种、多链收银台、严格链上核验与订单自动交付。
 * Version:     2.1.2
 * Author:      九流网络
 * Text Domain: jiuliu-crypto-payment
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.0
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

define('JIULIU_CRYPTO_VERSION', '2.1.2');
define('JIULIU_CRYPTO_FILE', __FILE__);
define('JIULIU_CRYPTO_DIR', plugin_dir_path(__FILE__));
define('JIULIU_CRYPTO_URL', plugin_dir_url(__FILE__));

require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-util.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-routes.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-settings.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-db.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-rate.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-trongrid.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-evm.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-invoices.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-zibll.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-ajax.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-cron.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-admin.php';
require_once JIULIU_CRYPTO_DIR . 'includes/class-jiuliu-crypto-plugin.php';

register_activation_hook(__FILE__, array('JIULIU_CRYPTO_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('JIULIU_CRYPTO_Plugin', 'deactivate'));

function jiuliu_crypto_payment()
{
    return JIULIU_CRYPTO_Plugin::instance();
}

add_action('plugins_loaded', 'jiuliu_crypto_payment', 20);
