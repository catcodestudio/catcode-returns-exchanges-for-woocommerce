<?php
/**
 * Plugin singleton — wires every component together.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

use CatCode\Returns\Admin\RequestsPage;
use CatCode\Returns\Admin\SettingsPage;
use CatCode\Returns\Front\Account;
use CatCode\Returns\Front\Form;
use CatCode\Returns\Front\GuestForm;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** @var Plugin|null */
	private static $instance = null;

	/** @var bool */
	private $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'maybe_upgrade' ), 5 );

		( new Cron() )->register();
		( new Account() )->register();
		( new Form() )->register();
		( new GuestForm() )->register();

		if ( is_admin() ) {
			( new RequestsPage() )->register();
			( new SettingsPage() )->register();
			add_filter( 'plugin_action_links_' . CATCODE_RMA_BASENAME, array( $this, 'action_links' ) );
		}
	}


	/** Keep tables, rewrite rules and cron in step after an update. */
	public function maybe_upgrade(): void {
		if ( CATCODE_RMA_VERSION === get_option( Installer::VERSION_OPTION ) ) {
			return;
		}
		Installer::create_tables();
		Account::add_endpoint();
		flush_rewrite_rules( false );
		Cron::schedule();
		update_option( Installer::VERSION_OPTION, CATCODE_RMA_VERSION, false );
	}

	/**
	 * @param array<int,string> $links Plugin row links.
	 * @return array<int,string>
	 */
	public function action_links( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		array_unshift(
			$links,
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">' . esc_html__( 'Settings', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>',
			'<a href="' . esc_url( admin_url( 'admin.php?page=' . RequestsPage::SLUG ) ) . '">' . esc_html__( 'Requests', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>'
		);
		return $links;
	}
}
