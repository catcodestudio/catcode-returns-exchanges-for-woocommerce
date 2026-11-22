<?php
/**
 * WooCommerce → Returns settings.
 *
 * "Save settings" writes only the keys this form owns; Settings::update()
 * merges them into the stored option instead of replacing it.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Admin;

use CatCode\Returns\Core\Settings;
use CatCode\Returns\Front\GuestForm;

defined( 'ABSPATH' ) || exit;

class SettingsPage {

	public const SLUG = 'catcode-rma-settings';

	/** @var string */
	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 21 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_catcode_rma_save', array( $this, 'handle_save' ) );
		add_action( 'admin_post_catcode_rma_create_page', array( $this, 'handle_create_page' ) );
	}

	public function menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'Returns settings', 'catcode-returns-exchanges-for-woocommerce' ),
			__( 'Returns settings', 'catcode-returns-exchanges-for-woocommerce' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function assets( $hook ): void {
		if ( '' === $this->hook_suffix || $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'catcode-rma-admin', CATCODE_RMA_URL . 'assets/css/admin.css', array(), CATCODE_RMA_VERSION );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$cfg    = Settings::all();
		$is_pro = false;

		echo '<div class="wrap catcode-rma-admin">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Returns settings', 'catcode-returns-exchanges-for-woocommerce' ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=' . RequestsPage::SLUG ) ) . '">' . esc_html__( 'Requests', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>';
		echo '<hr class="wp-header-end">';

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- flash message only.
		if ( isset( $_GET['catcode_rma_msg'] ) ) {
			$type = isset( $_GET['catcode_rma_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['catcode_rma_type'] ) ) : 'ok';
			echo '<div class="notice ' . ( 'error' === $type ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( (string) $_GET['catcode_rma_msg'] ) ) ) . '</p></div>';
		}
		// phpcs:enable

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="catcode_rma_save">';
		wp_nonce_field( 'catcode_rma_save' );

		$this->section_general( $cfg );
		$this->section_notifications( $cfg );

		submit_button( __( 'Save settings', 'catcode-returns-exchanges-for-woocommerce' ) );
		echo '</form></div>';
	}

	/** @param array<string,mixed> $cfg Settings. */
	private function section_general( array $cfg ): void {
		echo '<div class="catcode-rma-card"><h2>' . esc_html__( 'Return requests', 'catcode-returns-exchanges-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';

		echo '<tr><th scope="row">' . esc_html__( 'Enable', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="enabled" value="yes"' . checked( 'yes', $cfg['enabled'], false ) . '> ' . esc_html__( 'Customers can request a return or exchange online', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></td></tr>';

		$claims = 'claims' === (string) $cfg['request_type'];
		echo '<tr><th scope="row">' . esc_html__( 'Requests are', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><fieldset>';
		echo '<label><input type="radio" name="request_type" value="returns"' . checked( $claims, false, false ) . '> ' . esc_html__( 'Returns and exchanges within a return period', 'catcode-returns-exchanges-for-woocommerce' ) . '</label><br>';
		echo '<label><input type="radio" name="request_type" value="claims"' . checked( $claims, true, false ) . '> ' . esc_html__( 'Claims for defects (warranty claims, Czech reklamace): a claim period in months and a deadline to resolve each claim', 'catcode-returns-exchanges-for-woocommerce' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'With claims, the forms, the My Account tab and the e-mails speak of claims, and the confirmation e-mails carry the date of the claim, the requested resolution and, once resolved, the date and the way it was resolved.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></fieldset></td></tr>';
		echo '<tr><th scope="row"><label for="catcode-rma-window">' . esc_html__( 'Return period', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></th><td><input type="number" min="0" max="3650" class="small-text" id="catcode-rma-window" name="window_days" value="' . esc_attr( (string) (int) $cfg['window_days'] ) . '"> ' . esc_html__( 'days after the order is completed', 'catcode-returns-exchanges-for-woocommerce' );
		echo '<p class="description">' . esc_html__( '14 days is the minimum under Ukrainian consumer law and the EU withdrawal right. 0 = no limit. Used for returns.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="catcode-rma-claim-months">' . esc_html__( 'Claim period', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></th><td><input type="number" min="0" max="600" class="small-text" id="catcode-rma-claim-months" name="claim_months" value="' . esc_attr( (string) (int) $cfg['claim_months'] ) . '"> ' . esc_html__( 'months after the order is completed', 'catcode-returns-exchanges-for-woocommerce' );
		echo '<p class="description">' . esc_html__( 'In the EU a consumer can claim a defect that shows within two years of receiving the goods (§ 2165 of the Czech Civil Code). 0 = no limit. Used for claims.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th scope="row"><label for="catcode-rma-resolve-days">' . esc_html__( 'Deadline to resolve', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></th><td><input type="number" min="0" max="365" class="small-text" id="catcode-rma-resolve-days" name="resolve_days" value="' . esc_attr( (string) (int) $cfg['resolve_days'] ) . '"> ' . esc_html__( 'days from the day the claim is filed', 'catcode-returns-exchanges-for-woocommerce' );
		echo '<p class="description">' . esc_html__( 'Czech law: a claim has to be resolved and the consumer told within 30 days of the claim, unless a longer period is agreed with the consumer (§ 19 of the Consumer Protection Act). The date is shown in the admin, in My Account and in the e-mails. 0 = no deadline. Used for claims.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Order statuses', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><fieldset>';
		foreach ( wc_get_order_statuses() as $code => $label ) {
			if ( in_array( $code, array( 'wc-pending', 'wc-failed', 'wc-cancelled', 'wc-checkout-draft' ), true ) ) {
				continue;
			}
			echo '<label style="margin-right:14px"><input type="checkbox" name="order_statuses[]" value="' . esc_attr( $code ) . '"' . checked( in_array( $code, (array) $cfg['order_statuses'], true ), true, false ) . '> ' . esc_html( $label ) . '</label> ';
		}
		echo '<p class="description">' . esc_html__( 'A request can be sent only for orders in these statuses.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></fieldset></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Customer can ask for', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><fieldset>';
		foreach ( Settings::resolution_labels() as $code => $label ) {
			$suffix = '';
			echo '<label style="margin-right:14px"><input type="checkbox" name="resolutions[]" value="' . esc_attr( $code ) . '"' . checked( in_array( $code, (array) $cfg['resolutions'], true ), true, false ) . '> ' . esc_html( $label . $suffix ) . '</label> ';
		}
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="catcode-rma-reasons">' . esc_html__( 'Reasons', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></th><td><textarea id="catcode-rma-reasons" name="reasons" rows="6" class="large-text" placeholder="' . esc_attr( implode( "\n", Settings::default_reasons() ) ) . '">' . esc_textarea( (string) $cfg['reasons'] ) . '</textarea><p class="description">' . esc_html__( 'One per line. Leave empty to use the default list in the site language.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row"><label for="catcode-rma-policy">' . esc_html__( 'Text above the form', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></th><td><textarea id="catcode-rma-policy" name="policy_text" rows="3" class="large-text" placeholder="' . esc_attr( Settings::policy_text() ) . '">' . esc_textarea( (string) $cfg['policy_text'] ) . '</textarea><p class="description">' . esc_html__( 'Your return conditions. Leave empty for the default text.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Comment', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="require_comment" value="yes"' . checked( 'yes', $cfg['require_comment'], false ) . '> ' . esc_html__( 'Make the comment required', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></td></tr>';

		echo '<tr><th scope="row"><label for="catcode-rma-guest-page">' . esc_html__( 'Page for guests', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => 'guest_page_id',
				'id'                => 'catcode-rma-guest-page',
				'selected'          => (int) $cfg['guest_page_id'],
				'show_option_none'  => esc_html__( '— not set —', 'catcode-returns-exchanges-for-woocommerce' ),
				'option_none_value' => '0',
			)
		);
		if ( (int) $cfg['guest_page_id'] <= 0 ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=catcode_rma_create_page' ), 'catcode_rma_create_page' ) ) . '">' . esc_html__( 'Create the page', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>';
		}
		/* translators: %s: shortcode. */
		echo '<p class="description">' . esc_html( sprintf( __( 'A page with the %s shortcode: customers without an account find their order by number and e-mail / phone. Registered customers use the "Returns" tab in My Account.', 'catcode-returns-exchanges-for-woocommerce' ), '[' . GuestForm::SHORTCODE . ']' ) ) . '</p></td></tr>';

		echo '</table></div>';
	}

	/** @param array<string,mixed> $cfg Settings. */
	private function section_notifications( array $cfg ): void {
		echo '<div class="catcode-rma-card"><h2>' . esc_html__( 'E-mails', 'catcode-returns-exchanges-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Customer', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="notify_customer" value="yes"' . checked( 'yes', $cfg['notify_customer'], false ) . '> ' . esc_html__( 'Confirm the request and report every status change', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Store', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><td><label><input type="checkbox" name="notify_admin" value="yes"' . checked( 'yes', $cfg['notify_admin'], false ) . '> ' . esc_html__( 'E-mail about every new request', 'catcode-returns-exchanges-for-woocommerce' ) . '</label><br>';
		echo '<input type="email" class="regular-text" name="admin_email" value="' . esc_attr( (string) $cfg['admin_email'] ) . '" placeholder="' . esc_attr( (string) get_option( 'admin_email' ) ) . '" aria-label="' . esc_attr__( 'Store e-mail', 'catcode-returns-exchanges-for-woocommerce' ) . '"></td></tr>';
		echo '</table></div>';
	}


	public function handle_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'catcode-returns-exchanges-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'catcode_rma_save' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by check_admin_referer() above.

		$statuses = array();
		foreach ( isset( $_POST['order_statuses'] ) ? (array) wp_unslash( $_POST['order_statuses'] ) : array() as $status ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted below.
			$status = sanitize_text_field( (string) $status );
			if ( array_key_exists( $status, wc_get_order_statuses() ) ) {
				$statuses[] = $status;
			}
		}
		$resolutions = array();
		foreach ( isset( $_POST['resolutions'] ) ? (array) wp_unslash( $_POST['resolutions'] ) : array() as $code ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- whitelisted below.
			$code = sanitize_key( (string) $code );
			if ( array_key_exists( $code, Settings::resolution_labels() ) ) {
				$resolutions[] = $code;
			}
		}

		// Only the keys this form owns; Settings::update() merges, so every other
		// stored key keeps its value.
		$values = array(
			'enabled'         => isset( $_POST['enabled'] ) ? 'yes' : 'no',
			'request_type'    => isset( $_POST['request_type'] ) && 'claims' === $_POST['request_type'] ? 'claims' : 'returns',
			'window_days'     => isset( $_POST['window_days'] ) ? max( 0, min( 3650, absint( $_POST['window_days'] ) ) ) : 14,
			'claim_months'    => isset( $_POST['claim_months'] ) ? max( 0, min( 600, absint( $_POST['claim_months'] ) ) ) : 24,
			'resolve_days'    => isset( $_POST['resolve_days'] ) ? max( 0, min( 365, absint( $_POST['resolve_days'] ) ) ) : 30,
			'order_statuses'  => $statuses ? $statuses : array( 'wc-completed' ),
			'resolutions'     => $resolutions ? $resolutions : array( 'refund' ),
			'reasons'         => isset( $_POST['reasons'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['reasons'] ) ) : '',
			'policy_text'     => isset( $_POST['policy_text'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['policy_text'] ) ) : '',
			'require_comment' => isset( $_POST['require_comment'] ) ? 'yes' : 'no',
			'guest_page_id'   => isset( $_POST['guest_page_id'] ) ? absint( $_POST['guest_page_id'] ) : 0,
			'notify_customer' => isset( $_POST['notify_customer'] ) ? 'yes' : 'no',
			'notify_admin'    => isset( $_POST['notify_admin'] ) ? 'yes' : 'no',
			'admin_email'     => isset( $_POST['admin_email'] ) ? sanitize_email( wp_unslash( (string) $_POST['admin_email'] ) ) : '',
		);

		// phpcs:enable

		Settings::update( $values );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'            => self::SLUG,
					'catcode_rma_msg' => rawurlencode( __( 'Settings saved.', 'catcode-returns-exchanges-for-woocommerce' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/** One click: a published "Returns" page with the shortcode, selected in the settings. */
	public function handle_create_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'catcode-returns-exchanges-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'catcode_rma_create_page' );
		$id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => Settings::is_claims() ? __( 'Claims', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Returns and exchanges', 'catcode-returns-exchanges-for-woocommerce' ),
				'post_content' => '<!-- wp:shortcode -->[' . GuestForm::SHORTCODE . ']<!-- /wp:shortcode -->',
			),
			true
		);
		$ok = ! is_wp_error( $id ) && $id > 0;
		if ( $ok ) {
			Settings::update( array( 'guest_page_id' => (int) $id ) );
		}
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'             => self::SLUG,
					'catcode_rma_type' => $ok ? 'ok' : 'error',
					'catcode_rma_msg'  => rawurlencode( $ok ? __( 'The page has been created and selected.', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'The page could not be created.', 'catcode-returns-exchanges-for-woocommerce' ) ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
