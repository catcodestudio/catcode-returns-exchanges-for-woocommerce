<?php
/**
 * The customer-facing pieces shared by My Account and the guest page:
 * the request form, the request view, and the POST handler behind both.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Front;

use CatCode\Returns\Core\Eligibility;
use CatCode\Returns\Core\Repository;
use CatCode\Returns\Core\Requests;
use CatCode\Returns\Core\Settings;
use CatCode\Returns\Core\Statuses;

defined( 'ABSPATH' ) || exit;

class Form {

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'handle_post' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets(): void {
		wp_register_style( 'catcode-rma-front', CATCODE_RMA_URL . 'assets/css/front.css', array(), CATCODE_RMA_VERSION );
	}

	/** May the current visitor act on this order? */
	public static function can_access_order( \WC_Order $order, string $token ): bool {
		$user = get_current_user_id();
		if ( $user > 0 && (int) $order->get_customer_id() === $user ) {
			return true;
		}
		return '' !== $token && hash_equals( Requests::guest_token( $order ), $token );
	}

	/**
	 * May the current visitor see this request?
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function can_access_request( array $request, string $key ): bool {
		$user = get_current_user_id();
		if ( $user > 0 && (int) $request['customer_id'] === $user ) {
			return true;
		}
		return '' !== $key && '' !== (string) $request['access_key'] && hash_equals( (string) $request['access_key'], $key );
	}

	/**
	 * A guest has no WooCommerce session cookie yet, and without one the notice
	 * added before a redirect is lost. Start the session so it survives.
	 */
	public static function keep_notices(): void {
		if ( function_exists( 'WC' ) && WC()->session && ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}
	}

	private static function post_str( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- every caller verifies its own nonce first.
		return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';
	}

	/** One entry point for every front-end POST of this plugin. */
	public function handle_post(): void {
		// sanitize_key() lower-cases, so compare against 'post'.
		if ( 'post' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}
		$action = strtolower( self::post_str( 'catcode_rma_action' ) );
		if ( 'create' === $action ) {
			$this->handle_create();
		} elseif ( 'waybill' === $action ) {
			$this->handle_waybill();
		} elseif ( 'lookup' === $action ) {
			GuestForm::handle_lookup();
		}
	}

	private function handle_create(): void {
		$order_id = absint( self::post_str( 'catcode_rma_order' ) );
		if ( ! wp_verify_nonce( self::post_str( '_catcode_rma_nonce' ), 'catcode_rma_create_' . $order_id ) ) {
			wc_add_notice( __( 'The form has expired. Please reload the page and try again.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! self::can_access_order( $order, self::post_str( 'catcode_rma_token' ) ) ) {
			wc_add_notice( __( 'This order was not found.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}

		$items = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$raw = isset( $_POST['catcode_rma_qty'] ) && is_array( $_POST['catcode_rma_qty'] ) ? wp_unslash( $_POST['catcode_rma_qty'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int below.
		foreach ( $raw as $item_id => $qty ) {
			$items[ absint( $item_id ) ] = absint( $qty );
		}

		$photos = array();

		$result = Requests::create(
			$order,
			array(
				'items'      => $items,
				'reason'     => self::post_str( 'catcode_rma_reason' ),
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
				'comment'    => isset( $_POST['catcode_rma_comment'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['catcode_rma_comment'] ) ) : '',
				'resolution' => sanitize_key( self::post_str( 'catcode_rma_resolution' ) ),
				'photos'     => $photos,
			)
		);

		if ( is_wp_error( $result ) ) {
			wc_add_notice( $result->get_error_message(), 'error' );
			return;
		}


		$request = Repository::get( (int) $result );
		wc_add_notice(
			sprintf(
				Settings::is_claims()
					/* translators: %s: claim number. */
					? __( 'Claim %s has been filed. We have e-mailed you a confirmation.', 'catcode-returns-exchanges-for-woocommerce' )
					/* translators: %s: request number. */
					: __( 'Request %s has been sent. We have e-mailed you a copy and will reply soon.', 'catcode-returns-exchanges-for-woocommerce' ),
				Repository::number( (int) $result )
			)
		);
		$url = $request ? Requests::customer_url( $request ) : '';
		self::keep_notices();
		wp_safe_redirect( '' !== $url ? $url : home_url( '/' ) );
		exit;
	}

	private function handle_waybill(): void {
		$id = absint( self::post_str( 'catcode_rma_request' ) );
		if ( ! wp_verify_nonce( self::post_str( '_catcode_rma_nonce' ), 'catcode_rma_waybill_' . $id ) ) {
			wc_add_notice( __( 'The form has expired. Please reload the page and try again.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}
		$request = Repository::get( $id );
		if ( ! $request || ! self::can_access_request( $request, self::post_str( 'catcode_rma_key' ) ) || ! Requests::waybill_editable( $request ) ) {
			wc_add_notice( __( 'This request was not found.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}
		if ( ! Requests::set_waybill( $request, sanitize_key( self::post_str( 'catcode_rma_carrier' ) ), self::post_str( 'catcode_rma_ttn' ), 'customer' ) ) {
			wc_add_notice( __( 'Enter the waybill number.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			return;
		}
		wc_add_notice( __( 'Waybill saved. We will let you know when the parcel arrives.', 'catcode-returns-exchanges-for-woocommerce' ) );
		$url = Requests::customer_url( $request );
		self::keep_notices();
		wp_safe_redirect( '' !== $url ? $url : home_url( '/' ) );
		exit;
	}

	/**
	 * The request form for one order.
	 */
	public static function render_create( \WC_Order $order, string $token = '' ): void {
		wp_enqueue_style( 'catcode-rma-front' );

		$block = Eligibility::order_block_reason( $order );
		if ( '' !== $block ) {
			echo '<p class="catcode-rma-note">' . esc_html( $block ) . '</p>';
			return;
		}

		$items       = Eligibility::returnable_items( $order );
		$resolutions = Settings::resolutions();
		$photos      = false;

		$claims = Settings::is_claims();
		echo '<div class="catcode-rma">';
		echo '<h3>' . esc_html(
			sprintf(
				$claims
					/* translators: %s: order number. */
					? __( 'Claim — order #%s', 'catcode-returns-exchanges-for-woocommerce' )
					/* translators: %s: order number. */
					: __( 'Return or exchange — order #%s', 'catcode-returns-exchanges-for-woocommerce' ),
				$order->get_order_number()
			)
		) . '</h3>';
		echo '<p class="catcode-rma-policy">' . nl2br( esc_html( Settings::policy_text() ) ) . '</p>';

		echo '<form method="post" class="catcode-rma-form"' . ( $photos ? ' enctype="multipart/form-data"' : '' ) . '>';
		echo '<input type="hidden" name="catcode_rma_action" value="create">';
		echo '<input type="hidden" name="catcode_rma_order" value="' . esc_attr( (string) $order->get_id() ) . '">';
		echo '<input type="hidden" name="catcode_rma_token" value="' . esc_attr( $token ) . '">';
		wp_nonce_field( 'catcode_rma_create_' . $order->get_id(), '_catcode_rma_nonce', false );

		echo '<table class="catcode-rma-items shop_table"><thead><tr><th>' . esc_html__( 'Item', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Price', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html( $claims ? __( 'Qty with a defect', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Return qty', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item_id => $row ) {
			/** @var \WC_Order_Item_Product $item */
			$item  = $row['item'];
			$field = 'catcode-rma-qty-' . $item_id;
			echo '<tr><td><label for="' . esc_attr( $field ) . '">' . esc_html( wp_strip_all_tags( $item->get_name() ) ) . '</label>';
			if ( $row['deadline'] > 0 ) {
				echo '<br><small>' . esc_html(
					sprintf(
						$claims
							/* translators: %s: date. */
							? __( 'Claim until %s', 'catcode-returns-exchanges-for-woocommerce' )
							/* translators: %s: date. */
							: __( 'Return until %s', 'catcode-returns-exchanges-for-woocommerce' ),
						wp_date( get_option( 'date_format' ), $row['deadline'] )
					)
				) . '</small>';
			}
			echo '</td><td>' . wp_kses_post( wc_price( $row['unit'], array( 'currency' => $order->get_currency() ) ) ) . '</td><td>';
			echo '<select id="' . esc_attr( $field ) . '" name="catcode_rma_qty[' . esc_attr( (string) $item_id ) . ']">';
			echo '<option value="0">—</option>';
			for ( $i = 1; $i <= $row['max']; $i++ ) {
				echo '<option value="' . esc_attr( (string) $i ) . '"' . selected( 1 === count( $items ) && 1 === $row['max'] && 1 === $i, true, false ) . '>' . esc_html( (string) $i ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';

		echo '<p class="form-row"><label for="catcode-rma-reason">' . esc_html( $claims ? __( 'What is wrong with the item?', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Reason', 'catcode-returns-exchanges-for-woocommerce' ) ) . ' <abbr class="required">*</abbr></label>';
		echo '<select id="catcode-rma-reason" name="catcode_rma_reason" required><option value="">' . esc_html( $claims ? __( 'Choose the defect', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Choose a reason', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</option>';
		foreach ( Settings::reasons() as $reason ) {
			echo '<option value="' . esc_attr( $reason ) . '">' . esc_html( $reason ) . '</option>';
		}
		echo '</select></p>';

		echo '<fieldset class="form-row catcode-rma-resolution"><legend>' . esc_html( $claims ? __( 'How should the claim be resolved?', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'What would you like?', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</legend>';
		$first = true;
		foreach ( $resolutions as $code => $label ) {
			echo '<label><input type="radio" name="catcode_rma_resolution" value="' . esc_attr( $code ) . '"' . checked( $first, true, false ) . '> ' . esc_html( $label ) . '</label> ';
			$first = false;
		}
		echo '</fieldset>';

		$required = Settings::is_on( 'require_comment' );
		echo '<p class="form-row"><label for="catcode-rma-comment">' . esc_html__( 'Comment', 'catcode-returns-exchanges-for-woocommerce' ) . ( $required ? ' <abbr class="required">*</abbr>' : '' ) . '</label>';
		echo '<textarea id="catcode-rma-comment" name="catcode_rma_comment" rows="4" maxlength="5000" placeholder="' . esc_attr( $claims ? __( 'Describe the defect: what happens and since when.', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Describe the problem; for an exchange, say which size or colour you need.', 'catcode-returns-exchanges-for-woocommerce' ) ) . '"' . ( $required ? ' required' : '' ) . '></textarea></p>';


		echo '<p><button type="submit" class="button">' . esc_html( $claims ? __( 'File the claim', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Send request', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</button></p>';
		echo '</form></div>';
	}

	/**
	 * One request, as the customer sees it.
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function render_view( array $request, string $key = '' ): void {
		wp_enqueue_style( 'catcode-rma-front' );
		$number = Repository::number( (int) $request['id'] );
		$labels = Settings::resolution_labels();
		$order  = wc_get_order( (int) $request['order_id'] );

		echo '<div class="catcode-rma catcode-rma-view">';
		echo '<h3>' . esc_html( $number ) . ' <mark class="catcode-rma-status catcode-rma-status--' . esc_attr( (string) $request['status'] ) . '">' . esc_html( Statuses::label( (string) $request['status'] ) ) . '</mark></h3>';

		echo '<table class="shop_table catcode-rma-summary"><tbody>';
		self::row( __( 'Order', 'catcode-returns-exchanges-for-woocommerce' ), '#' . ( $order ? $order->get_order_number() : (int) $request['order_id'] ) );
		$claims = Settings::is_claims();
		self::row( $claims ? __( 'Filed on', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Created', 'catcode-returns-exchanges-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( $request['created_at'] . ' UTC' ) ) );
		$resolved = Requests::resolved_at( $request );
		$due      = Requests::due( $request );
		if ( $resolved > 0 && $claims ) {
			self::row( __( 'Resolved on', 'catcode-returns-exchanges-for-woocommerce' ), wp_date( get_option( 'date_format' ), $resolved ) );
		} elseif ( $due > 0 && $resolved <= 0 ) {
			self::row( __( 'We will resolve it by', 'catcode-returns-exchanges-for-woocommerce' ), wp_date( get_option( 'date_format' ), $due ) );
		}
		self::row( $claims ? __( 'Requested resolution', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Request', 'catcode-returns-exchanges-for-woocommerce' ), isset( $labels[ $request['resolution'] ] ) ? $labels[ $request['resolution'] ] : (string) $request['resolution'] );
		self::row( $claims ? __( 'Defect', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Reason', 'catcode-returns-exchanges-for-woocommerce' ), (string) $request['reason'] );
		if ( '' !== trim( (string) $request['comment'] ) ) {
			self::row( __( 'Comment', 'catcode-returns-exchanges-for-woocommerce' ), (string) $request['comment'] );
		}
		if ( '' !== trim( (string) $request['customer_message'] ) ) {
			self::row( __( 'Message from the store', 'catcode-returns-exchanges-for-woocommerce' ), (string) $request['customer_message'] );
		}
		echo '</tbody></table>';

		echo '<table class="shop_table catcode-rma-items"><thead><tr><th>' . esc_html__( 'Item', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Qty', 'catcode-returns-exchanges-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( Repository::items( (int) $request['id'] ) as $item ) {
			echo '<tr><td>' . esc_html( (string) $item['name'] ) . '</td><td>' . (int) $item['qty'] . '</td></tr>';
		}
		echo '</tbody></table>';

		$ttn = (string) $request['return_ttn'];
		if ( '' !== $ttn ) {
			$url = Requests::tracking_url( (string) $request['return_carrier'], $ttn );
			echo '<p>' . esc_html__( 'Return waybill:', 'catcode-returns-exchanges-for-woocommerce' ) . ' <strong>' . esc_html( Requests::carrier_label( (string) $request['return_carrier'] ) . ' ' . $ttn ) . '</strong>';
			if ( '' !== $url ) {
				echo ' — <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'track', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>';
			}
			if ( '' !== (string) $request['tracking_state'] ) {
				echo '<br><small>' . esc_html( (string) $request['tracking_state'] ) . '</small>';
			}
			echo '</p>';
		}

		if ( Requests::waybill_editable( $request ) ) {
			echo '<form method="post" class="catcode-rma-waybill">';
			echo '<h4>' . esc_html( '' === $ttn ? __( 'Sent the item back? Enter the waybill number', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Change the waybill number', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</h4>';
			echo '<input type="hidden" name="catcode_rma_action" value="waybill">';
			echo '<input type="hidden" name="catcode_rma_request" value="' . esc_attr( (string) $request['id'] ) . '">';
			echo '<input type="hidden" name="catcode_rma_key" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'catcode_rma_waybill_' . (int) $request['id'], '_catcode_rma_nonce', false );
			echo '<select name="catcode_rma_carrier" aria-label="' . esc_attr__( 'Carrier', 'catcode-returns-exchanges-for-woocommerce' ) . '">';
			foreach ( Requests::carriers() as $code => $label ) {
				echo '<option value="' . esc_attr( $code ) . '"' . selected( (string) $request['return_carrier'], $code, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select> ';
			echo '<input type="text" name="catcode_rma_ttn" value="' . esc_attr( $ttn ) . '" maxlength="40" inputmode="numeric" placeholder="' . esc_attr__( 'Waybill number', 'catcode-returns-exchanges-for-woocommerce' ) . '" aria-label="' . esc_attr__( 'Waybill number', 'catcode-returns-exchanges-for-woocommerce' ) . '"> ';
			echo '<button type="submit" class="button">' . esc_html__( 'Save', 'catcode-returns-exchanges-for-woocommerce' ) . '</button>';
			echo '</form>';
		}
		echo '</div>';
	}

	private static function row( string $label, string $value ): void {
		echo '<tr><th>' . esc_html( $label ) . '</th><td>' . nl2br( esc_html( $value ) ) . '</td></tr>';
	}

	/**
	 * Compact list of requests with links.
	 *
	 * @param array<int,array<string,mixed>> $requests Rows.
	 * @param callable                       $link     fn(array $row): string URL.
	 */
	public static function render_list( array $requests, callable $link ): void {
		if ( ! $requests ) {
			return;
		}
		wp_enqueue_style( 'catcode-rma-front' );
		echo '<table class="shop_table catcode-rma-list"><thead><tr><th>' . esc_html__( 'Request', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Date', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Status', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $requests as $row ) {
			$url = (string) $link( $row );
			echo '<tr><td>' . esc_html( Repository::number( (int) $row['id'] ) ) . '</td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ), (int) strtotime( $row['created_at'] . ' UTC' ) ) ) . '</td>';
			echo '<td>' . esc_html( Statuses::label( (string) $row['status'] ) ) . '</td>';
			echo '<td>' . ( '' !== $url ? '<a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'View', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>' : '' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
}
