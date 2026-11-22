<?php
/**
 * Plugin Name: CatCode Returns & Exchanges Manager for WooCommerce
 * Plugin URI: https://catcode.com.ua/modules/catcode-returns-exchanges-for-woocommerce/
 * Description: Return, exchange and warranty claim requests from the customer account or a guest form: item and quantity picker, reasons, a return or claim period, a deadline to resolve claims, request statuses, return waybill tracking and e-mail notifications.
 * Version: 1.1.1
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: CatCode
 * Author URI: https://catcode.com.ua
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: catcode-returns-exchanges-for-woocommerce
 * Domain Path: /languages
 * WC requires at least: 7.0
 * WC tested up to: 11.3
 *
 * @package CatCodeReturns
 */

defined( 'ABSPATH' ) || exit;


defined( 'CATCODE_RMA_VERSION' ) || define( 'CATCODE_RMA_VERSION', '1.1.1' );
defined( 'CATCODE_RMA_FILE' ) || define( 'CATCODE_RMA_FILE', __FILE__ );
defined( 'CATCODE_RMA_DIR' ) || define( 'CATCODE_RMA_DIR', plugin_dir_path( __FILE__ ) );
defined( 'CATCODE_RMA_URL' ) || define( 'CATCODE_RMA_URL', plugin_dir_url( __FILE__ ) );
defined( 'CATCODE_RMA_BASENAME' ) || define( 'CATCODE_RMA_BASENAME', plugin_basename( __FILE__ ) );

foreach (
	array(
		'includes/Core/Settings.php',
		'includes/Core/Statuses.php',
		'includes/Core/Repository.php',
		'includes/Core/Installer.php',
		'includes/Core/Eligibility.php',
		'includes/Core/Mailer.php',
		'includes/Core/Requests.php',
		'includes/Core/Cron.php',
		'includes/Front/Form.php',
		'includes/Front/Account.php',
		'includes/Front/GuestForm.php',
		'includes/Admin/RequestsTable.php',
		'includes/Admin/RequestsPage.php',
		'includes/Admin/SettingsPage.php',
		'includes/Core/Plugin.php',
	) as $catcode_rma_inc
) {
	require_once CATCODE_RMA_DIR . $catcode_rma_inc;
}
unset( $catcode_rma_inc );


register_activation_hook(
	__FILE__,
	static function () {
		\CatCode\Returns\Core\Installer::activate();
	}
);
register_deactivation_hook(
	__FILE__,
	static function () {
		\CatCode\Returns\Core\Installer::deactivate();
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'CatCode Returns & Exchanges Manager requires an active WooCommerce installation.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}
		\CatCode\Returns\Core\Plugin::instance()->boot();
	}
);
