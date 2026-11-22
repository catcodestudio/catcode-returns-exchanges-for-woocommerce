<?php
/**
 * Activation / deactivation: tables, My Account endpoint, cron.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

use CatCode\Returns\Front\Account;

defined( 'ABSPATH' ) || exit;

class Installer {

	public const VERSION_OPTION = 'catcode_rma_version';

	public static function activate(): void {
		self::create_tables();

		// Only structural keys are stored here; translatable texts stay empty and
		// are filled from the current locale at runtime.
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}

		Account::add_endpoint();
		flush_rewrite_rules( false );

		Cron::schedule();
		update_option( self::VERSION_OPTION, CATCODE_RMA_VERSION, false );
	}

	public static function deactivate(): void {
		Cron::unschedule();
		flush_rewrite_rules( false );
	}

	public static function create_tables(): void {
		global $wpdb;
		$charset  = $wpdb->get_charset_collate();
		$requests = Repository::table();
		$items    = Repository::items_table();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			"CREATE TABLE {$requests} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			email VARCHAR(190) NOT NULL DEFAULT '',
			phone VARCHAR(40) NOT NULL DEFAULT '',
			customer_name VARCHAR(190) NOT NULL DEFAULT '',
			resolution VARCHAR(20) NOT NULL DEFAULT 'refund',
			reason VARCHAR(255) NOT NULL DEFAULT '',
			comment TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			return_carrier VARCHAR(20) NOT NULL DEFAULT '',
			return_ttn VARCHAR(64) NOT NULL DEFAULT '',
			tracking_state VARCHAR(190) NOT NULL DEFAULT '',
			tracking_checked_at DATETIME NULL DEFAULT NULL,
			amount DECIMAL(18,4) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT '',
			refund_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			exchange_order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			coupon_code VARCHAR(64) NOT NULL DEFAULT '',
			photos TEXT NULL,
			customer_message TEXT NULL,
			private_note TEXT NULL,
			history LONGTEXT NULL,
			access_key VARCHAR(40) NOT NULL DEFAULT '',
			due_at DATETIME NULL DEFAULT NULL,
			resolved_at DATETIME NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY customer_id (customer_id),
			KEY email (email),
			KEY status (status),
			KEY return_ttn (return_ttn)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_id BIGINT UNSIGNED NOT NULL,
			order_item_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			name VARCHAR(255) NOT NULL DEFAULT '',
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			unit_total DECIMAL(18,4) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY request_id (request_id),
			KEY order_item_id (order_item_id)
		) {$charset};"
		);
	}
}
