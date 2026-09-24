<?php
/**
 * Plugin Name: Ranau 5Post for WooCommerce
 * Plugin URI: https://ranau.uk/wordpress/ranau-5post-for-woocommerce/
 * Description: Быстрый выбор пункта выдачи 5Post на карте в WooCommerce checkout.
 * Version: 0.1.32
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author: Ranau
 * Author URI: https://ranau.uk/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ranau-5post-for-woocommerce
 * Requires Plugins: woocommerce
 * WC requires at least: 8.9
 * WC tested up to: 10.8
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('RANAU_FIVEPOST_FILE', __FILE__);
define('RANAU_FIVEPOST_DIR', plugin_dir_path(__FILE__));
define('RANAU_FIVEPOST_URL', plugin_dir_url(__FILE__));
define('RANAU_FIVEPOST_VERSION', '0.1.32');

require_once RANAU_FIVEPOST_DIR . 'includes/class-package.php';
require_once RANAU_FIVEPOST_DIR . 'includes/internal/ProviderStateStore.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-delivery-state.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-pickup-point-query.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-plugin.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-installer.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-settings.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-pickup-point-repository.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-package-content-resolver.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-cart-package-resolver.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-delivery-estimate-service.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-free-shipping-policy.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-fivepost-client.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-sync-service.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-rest-controller.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-assets.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-classic-checkout-adapter.php';
require_once RANAU_FIVEPOST_DIR . 'includes/class-blocks-checkout-adapter.php';

register_activation_hook(__FILE__, array('Ranau\\FivePost\\Installer', 'activate'));

add_action('before_woocommerce_init', static function (): void {
    $features = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';
    if (class_exists($features)) {
        $features::declare_compatibility('custom_order_tables', RANAU_FIVEPOST_FILE, true);
        $features::declare_compatibility('cart_checkout_blocks', RANAU_FIVEPOST_FILE, true);
    }
});

add_action('plugins_loaded', static function (): void {
    \Ranau\FivePost\Plugin::instance()->init();
});
