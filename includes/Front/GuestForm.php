<?php
/**
 * [catcode_returns_form] — returns for guests (and anyone not logged in).
 *
 * Step 1: order number + the e-mail or phone from that order.
 * Step 2: a signed link to the request form for that order.
 * A created request is shown through its own secret key, which also travels
 * in every e-mail about it.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Front;

use CatCode\Returns\Core\Repository;
use CatCode\Returns\Core\Requests;
use CatCode\Returns\Core\Settings;

defined( 'ABSPATH' ) || exit;

class GuestForm {

	public const SHORTCODE = 'catcode_returns_form';

	/** Lookups per IP per 15 minutes before we stop answering. */
	private const MAX_ATTEMPTS = 10;

	public function register(): void {
		add_shortcode( self::SHORTCODE, array( $this, 'render' ) );
	}

	/**
	 * @param array<string,string>|string $atts Shortcode attributes (unused).
	 */
	public function render( $atts = array() ): string {
		unset( $atts );
		ob_start();
		wp_enqueue_style( 'catcode-rma-front' );

		if ( function_exists( 'wc_print_notices' ) && function_exists( 'WC' ) && WC()->session ) {
			wc_print_notices();
		}

		if ( ! Settings::is_on( 'enabled' ) ) {
			echo '<p>' . esc_html( Settings::is_claims() ? __( 'Online claims are switched off in this store.', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Online return requests are switched off in this store.', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</p>';
			return (string) ob_get_clean();
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- access is granted by the signed token / secret key, not by a nonce.
		$request_id = isset( $_GET['rma_request'] ) ? absint( $_GET['rma_request'] ) : 0;
		$key        = isset( $_GET['rma_key'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['rma_key'] ) ) : '';
		$order_id   = isset( $_GET['rma_order'] ) ? absint( $_GET['rma_order'] ) : 0;
		$token      = isset( $_GET['rma_token'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['rma_token'] ) ) : '';
		// phpcs:enable

		if ( $request_id > 0 ) {
			$request = Repository::get( $request_id );
			if ( $request && Form::can_access_request( $request, $key ) ) {
				Form::render_view( $request, $key );
			} else {
				echo '<p>' . esc_html__( 'This request was not found.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
			}
			return (string) ob_get_clean();
		}

		if ( $order_id > 0 ) {
			$order = wc_get_order( $order_id );
			if ( $order instanceof \WC_Order && Form::can_access_order( $order, $token ) ) {
				$existing = Repository::for_order( $order->get_id() );
				if ( $existing ) {
					echo '<h3>' . esc_html__( 'Requests for this order', 'catcode-returns-exchanges-for-woocommerce' ) . '</h3>';
					Form::render_list(
						$existing,
						static function ( $row ) {
							return Requests::customer_url( $row );
						}
					);
				}
				Form::render_create( $order, $token );
			} else {
				echo '<p>' . esc_html__( 'This link is no longer valid. Look the order up again.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
				self::lookup_form();
			}
			return (string) ob_get_clean();
		}

		self::lookup_form();
		return (string) ob_get_clean();
	}

	private static function lookup_form(): void {
		echo '<form method="post" class="catcode-rma catcode-rma-lookup">';
		echo '<p>' . esc_html__( 'Enter your order number and the e-mail or phone number you used when ordering.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
		echo '<input type="hidden" name="catcode_rma_action" value="lookup">';
		wp_nonce_field( 'catcode_rma_lookup', '_catcode_rma_nonce', false );
		echo '<p class="form-row"><label for="catcode-rma-order-number">' . esc_html__( 'Order number', 'catcode-returns-exchanges-for-woocommerce' ) . '</label>';
		echo '<input type="text" id="catcode-rma-order-number" name="catcode_rma_order_number" required inputmode="numeric" autocomplete="off"></p>';
		echo '<p class="form-row"><label for="catcode-rma-contact">' . esc_html__( 'E-mail or phone', 'catcode-returns-exchanges-for-woocommerce' ) . '</label>';
		echo '<input type="text" id="catcode-rma-contact" name="catcode_rma_contact" required autocomplete="email"></p>';
		echo '<p><button type="submit" class="button">' . esc_html__( 'Find the order', 'catcode-returns-exchanges-for-woocommerce' ) . '</button></p>';
		echo '</form>';
	}

	/** POST handler for the lookup step (called from Form::handle_post). */
	public static function handle_lookup(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- this line reads the nonce itself.
		$nonce = isset( $_POST['_catcode_rma_nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['_catcode_rma_nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'catcode_rma_lookup' ) ) {
			wc_add_notice( __( 'The form has expired. Please reload the page and try again.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}

		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['REMOTE_ADDR'] ) ) : '';
		$bucket  = 'catcode_rma_lookup_' . md5( $ip );
		$tries   = (int) get_transient( $bucket );
		if ( $tries >= self::MAX_ATTEMPTS ) {
			wc_add_notice( __( 'Too many attempts. Please try again in 15 minutes.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}
		set_transient( $bucket, $tries + 1, 15 * MINUTE_IN_SECONDS );

		$number  = isset( $_POST['catcode_rma_order_number'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['catcode_rma_order_number'] ) ) : '';
		$contact = isset( $_POST['catcode_rma_contact'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['catcode_rma_contact'] ) ) : '';
		$number  = ltrim( trim( $number ), '#' );

		// Same filter the WooCommerce order-tracking shortcode uses, so plugins
		// with custom order numbers map them back to the real id.
		$order_id = absint( apply_filters( 'woocommerce_shortcode_order_tracking_order_id', $number ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook.
		$order    = $order_id > 0 ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! self::contact_matches( $order, $contact ) ) {
			wc_add_notice( __( 'No order matches this number and e-mail / phone.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}

		delete_transient( $bucket );
		// The form posts to the page it sits on, so that page is where we go back to.
		// (wp_get_referer() is empty here: it ignores a referer equal to the current URL.)
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) : '/';
		$host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( (string) $_SERVER['HTTP_HOST'] ) ) : (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$base = remove_query_arg( array( 'rma_order', 'rma_token', 'rma_request', 'rma_key' ), set_url_scheme( 'http://' . $host . $uri ) );
		$url  = add_query_arg(
			array(
				'rma_order' => $order->get_id(),
				'rma_token' => Requests::guest_token( $order ),
			),
			$base
		);
		Form::keep_notices();
		wp_safe_redirect( $url );
		exit;
	}

	private static function contact_matches( \WC_Order $order, string $contact ): bool {
		$contact = trim( $contact );
		if ( '' === $contact ) {
			return false;
		}
		if ( false !== strpos( $contact, '@' ) ) {
			return 0 === strcasecmp( $contact, (string) $order->get_billing_email() );
		}
		// Phones are compared by their last 9 digits: +380 67…, 067…, 67… are one number.
		$a = substr( preg_replace( '/\D+/', '', $contact ), -9 );
		$b = substr( preg_replace( '/\D+/', '', (string) $order->get_billing_phone() ), -9 );
		return strlen( $a ) >= 7 && $a === $b;
	}
}
