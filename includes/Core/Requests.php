<?php
/**
 * Request lifecycle: create, change status, customer links.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

use CatCode\Returns\Front\Account;

defined( 'ABSPATH' ) || exit;

class Requests {

	/**
	 * Create a request after validating everything the customer sent.
	 *
	 * @param array<string,mixed> $input items (order_item_id => qty), reason, comment, resolution, photos (attachment ids).
	 * @return int|\WP_Error Request id.
	 */
	public static function create( \WC_Order $order, array $input ) {
		$block = Eligibility::order_block_reason( $order );
		if ( '' !== $block ) {
			return new \WP_Error( 'not_eligible', $block );
		}

		$returnable = Eligibility::returnable_items( $order );
		$items      = array();
		$amount     = 0.0;

		foreach ( (array) ( $input['items'] ?? array() ) as $item_id => $qty ) {
			$item_id = (int) $item_id;
			$qty     = (int) $qty;
			if ( $qty <= 0 ) {
				continue;
			}
			if ( ! isset( $returnable[ $item_id ] ) ) {
				return new \WP_Error( 'bad_item', __( 'One of the selected items can no longer be returned. Refresh the page and try again.', 'catcode-returns-exchanges-for-woocommerce' ) );
			}
			$row = $returnable[ $item_id ];
			if ( $qty > $row['max'] ) {
				return new \WP_Error( 'bad_qty', __( 'The quantity is larger than what can be returned for this item.', 'catcode-returns-exchanges-for-woocommerce' ) );
			}
			/** @var \WC_Order_Item_Product $product_item */
			$product_item = $row['item'];
			$items[]      = array(
				'order_item_id' => $item_id,
				'product_id'    => (int) $product_item->get_product_id(),
				'variation_id'  => (int) $product_item->get_variation_id(),
				'name'          => mb_substr( wp_strip_all_tags( $product_item->get_name() ), 0, 255 ),
				'qty'           => $qty,
				'unit_total'    => wc_format_decimal( $row['unit'], 4 ),
			);
			$amount += $row['unit'] * $qty;
		}

		if ( ! $items ) {
			return new \WP_Error( 'no_items', __( 'Choose at least one item and its quantity.', 'catcode-returns-exchanges-for-woocommerce' ) );
		}

		$reason = trim( (string) ( $input['reason'] ?? '' ) );
		if ( ! in_array( $reason, Settings::reasons(), true ) ) {
			return new \WP_Error( 'bad_reason', Settings::is_claims() ? __( 'Choose what is wrong with the item.', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Choose a reason for the return.', 'catcode-returns-exchanges-for-woocommerce' ) );
		}

		$resolution = (string) ( $input['resolution'] ?? '' );
		if ( ! array_key_exists( $resolution, Settings::resolutions() ) ) {
			return new \WP_Error( 'bad_resolution', __( 'Choose how you would like the request to be resolved.', 'catcode-returns-exchanges-for-woocommerce' ) );
		}

		$comment = trim( (string) ( $input['comment'] ?? '' ) );
		if ( Settings::is_on( 'require_comment' ) && '' === $comment ) {
			return new \WP_Error( 'no_comment', __( 'Please describe the problem in the comment.', 'catcode-returns-exchanges-for-woocommerce' ) );
		}

		$data = array(
			'order_id'      => $order->get_id(),
			'customer_id'   => (int) $order->get_customer_id(),
			'email'         => (string) $order->get_billing_email(),
			'phone'         => (string) $order->get_billing_phone(),
			'customer_name' => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
			'resolution'    => $resolution,
			'reason'        => mb_substr( $reason, 0, 255 ),
			'comment'       => mb_substr( $comment, 0, 5000 ),
			'status'        => Statuses::NEW,
			'amount'        => wc_format_decimal( $amount, 4 ),
			'currency'      => (string) $order->get_currency(),
			'photos'        => (string) wp_json_encode( array_values( array_map( 'intval', (array) ( $input['photos'] ?? array() ) ) ) ),
		);
		// The deadline is fixed on the day of the claim: a later change of the setting
		// does not move it for claims already filed.
		$days = Settings::resolve_days();
		if ( $days > 0 ) {
			$data['due_at'] = gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS );
		}
		$id = Repository::insert( $data, $items );

		if ( $id <= 0 ) {
			return new \WP_Error( 'db', __( 'The request could not be saved. Please try again later.', 'catcode-returns-exchanges-for-woocommerce' ) );
		}

		Repository::log( $id, __( 'Request created by the customer.', 'catcode-returns-exchanges-for-woocommerce' ), 'customer' );

		$order->add_order_note(
			sprintf(
				Settings::is_claims()
					/* translators: 1: claim number, 2: requested resolution. */
					? __( 'Claim %1$s filed by the customer (%2$s).', 'catcode-returns-exchanges-for-woocommerce' )
					/* translators: 1: request number, 2: resolution. */
					: __( 'Return request %1$s created by the customer (%2$s).', 'catcode-returns-exchanges-for-woocommerce' ),
				Repository::number( $id ),
				Settings::resolution_labels()[ $resolution ] ?? $resolution
			)
		);

		$request = Repository::get( $id );
		if ( $request ) {
			Mailer::customer_created( $request );
			Mailer::admin_created( $request );
			/**
			 * A new return request was created.
			 *
			 * @param array<string,mixed> $request Request row.
			 */
			do_action( 'catcode_rma_created', $request );
		}

		return $id;
	}

	/**
	 * Change the status and/or send a message to the customer.
	 */
	public static function update_status( int $id, string $status, string $message = '', bool $notify = true, string $who = 'admin' ): bool {
		$request = Repository::get( $id );
		if ( ! $request || ! Statuses::is_valid( $status ) ) {
			return false;
		}
		$old     = (string) $request['status'];
		$changed = $old !== $status;
		$patch   = array();
		if ( $changed ) {
			$patch['status'] = $status;
			$resolved        = in_array( $status, Statuses::resolved(), true );
			if ( $resolved && empty( $request['resolved_at'] ) ) {
				$patch['resolved_at'] = current_time( 'mysql', true );
			} elseif ( ! $resolved ) {
				// Opened again: it is not resolved until it reaches an end status once more.
				$patch['resolved_at'] = null;
			}
		}
		if ( '' !== trim( $message ) ) {
			$patch['customer_message'] = $message;
		}
		if ( $patch ) {
			Repository::update( $id, $patch );
		}

		if ( $changed ) {
			Repository::log(
				$id,
				sprintf(
					/* translators: 1: old status, 2: new status. */
					__( 'Status: %1$s → %2$s', 'catcode-returns-exchanges-for-woocommerce' ),
					Statuses::label( $old ),
					Statuses::label( $status )
				),
				$who
			);
			$order = wc_get_order( (int) $request['order_id'] );
			if ( $order ) {
				$order->add_order_note(
					sprintf(
						Settings::is_claims()
							/* translators: 1: claim number, 2: new status. */
							? __( 'Claim %1$s: %2$s.', 'catcode-returns-exchanges-for-woocommerce' )
							/* translators: 1: request number, 2: new status. */
							: __( 'Return request %1$s: %2$s.', 'catcode-returns-exchanges-for-woocommerce' ),
						Repository::number( $id ),
						Statuses::label( $status )
					)
				);
			}
		}
		if ( '' !== trim( $message ) ) {
			/* translators: %s: message text. */
			Repository::log( $id, sprintf( __( 'Message to the customer: %s', 'catcode-returns-exchanges-for-woocommerce' ), $message ), $who );
		}

		$fresh = Repository::get( $id );
		if ( $fresh && $notify && ( $changed || '' !== trim( $message ) ) ) {
			Mailer::customer_updated( $fresh, $changed, $message );
		}
		if ( $fresh && $changed ) {
			/**
			 * A request changed its status.
			 *
			 * @param array<string,mixed> $request Request row after the change.
			 * @param string              $old     Previous status.
			 */
			do_action( 'catcode_rma_status_changed', $fresh, $old );
		}
		return true;
	}

	/**
	 * Where the customer sees the request: My Account for registered customers,
	 * the guest page with a secret key for everyone else.
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function customer_url( array $request ): string {
		if ( (int) $request['customer_id'] > 0 ) {
			return add_query_arg( 'rma_view', (int) $request['id'], wc_get_account_endpoint_url( Account::ENDPOINT ) );
		}
		$page = (int) Settings::get( 'guest_page_id', 0 );
		if ( $page <= 0 || 'publish' !== get_post_status( $page ) ) {
			return '';
		}
		return add_query_arg(
			array(
				'rma_request' => (int) $request['id'],
				'rma_key'     => (string) $request['access_key'],
			),
			get_permalink( $page )
		);
	}

	/**
	 * When the shop has to resolve the claim, as a timestamp; 0 when no deadline is kept.
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function due( array $request ): int {
		if ( ! empty( $request['due_at'] ) ) {
			return (int) strtotime( (string) $request['due_at'] . ' UTC' );
		}
		$days = Settings::resolve_days();
		if ( $days <= 0 || empty( $request['created_at'] ) ) {
			return 0;
		}
		return (int) strtotime( (string) $request['created_at'] . ' UTC' ) + $days * DAY_IN_SECONDS;
	}

	/**
	 * When the request reached an end status; 0 while it is open.
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function resolved_at( array $request ): int {
		if ( ! in_array( (string) $request['status'], Statuses::resolved(), true ) ) {
			return 0;
		}
		$at = ! empty( $request['resolved_at'] ) ? (string) $request['resolved_at'] : (string) $request['updated_at'];
		return (int) strtotime( $at . ' UTC' );
	}

	/**
	 * Whole days left to the deadline, negative when it has passed; null when there is
	 * no deadline or the request is resolved.
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function days_left( array $request ): ?int {
		$due = self::due( $request );
		if ( $due <= 0 || self::resolved_at( $request ) > 0 ) {
			return null;
		}
		$today = ( new \DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0 );
		$end   = ( new \DateTimeImmutable( '@' . $due ) )->setTimezone( wp_timezone() )->setTime( 0, 0 );
		return (int) round( ( $end->getTimestamp() - $today->getTimestamp() ) / DAY_IN_SECONDS );
	}

	/** Signed token a guest gets after proving they own an order. */
	public static function guest_token( \WC_Order $order ): string {
		return substr( hash_hmac( 'sha256', $order->get_id() . '|' . strtolower( (string) $order->get_billing_email() ) . '|' . $order->get_order_key(), wp_salt( 'nonce' ) ), 0, 32 );
	}

	/** Customer may enter or change the return waybill while the item is on its way. */
	public static function waybill_editable( array $request ): bool {
		return in_array( (string) $request['status'], array( Statuses::NEW, Statuses::APPROVED ), true );
	}

	/**
	 * Save the return waybill the customer entered.
	 *
	 * @param array<string,mixed> $request Request row.
	 */
	public static function set_waybill( array $request, string $carrier, string $ttn, string $who ): bool {
		$ttn     = preg_replace( '/[^0-9A-Za-z]/', '', $ttn );
		$carrier = array_key_exists( $carrier, self::carriers() ) || array_key_exists( $carrier, self::known_carriers() ) ? $carrier : 'other';
		if ( '' === $ttn || strlen( $ttn ) > 40 ) {
			return false;
		}
		if ( (string) $request['return_ttn'] === $ttn && (string) $request['return_carrier'] === $carrier ) {
			return true; // Same waybill sent again (reload, double click): nothing to log.
		}
		Repository::update(
			(int) $request['id'],
			array(
				'return_carrier' => $carrier,
				'return_ttn'     => $ttn,
				'tracking_state' => '',
			)
		);
		/* translators: 1: carrier name, 2: waybill number. */
		Repository::log( (int) $request['id'], sprintf( __( 'Return waybill: %1$s %2$s', 'catcode-returns-exchanges-for-woocommerce' ), self::carrier_label( $carrier ), $ttn ), $who );
		return true;
	}

	/** @return array<string,string> Every carrier this plugin knows, code => name. */
	public static function known_carriers(): array {
		return array(
			'np'        => __( 'Nova Poshta', 'catcode-returns-exchanges-for-woocommerce' ),
			'ukrposhta' => __( 'Ukrposhta', 'catcode-returns-exchanges-for-woocommerce' ),
			'packeta'   => 'Zásilkovna (Packeta)',
			'ppl'       => 'PPL',
			'cpost'     => 'Česká pošta',
			'dpd'       => 'DPD',
			'other'     => __( 'Other carrier', 'catcode-returns-exchanges-for-woocommerce' ),
		);
	}

	/**
	 * Carriers offered for a return waybill: Ukrainian ones for a store based in
	 * Ukraine, Czech and Slovak ones for a store there.
	 *
	 * @return array<string,string>
	 */
	public static function carriers(): array {
		$all     = self::known_carriers();
		$country = function_exists( 'WC' ) && WC()->countries ? (string) WC()->countries->get_base_country() : '';
		$codes   = in_array( $country, array( 'CZ', 'SK' ), true ) ? array( 'packeta', 'ppl', 'cpost', 'dpd', 'other' ) : array( 'np', 'ukrposhta', 'other' );
		$out     = array();
		foreach ( $codes as $code ) {
			$out[ $code ] = $all[ $code ];
		}
		/**
		 * Filters the carriers a return waybill can be entered for.
		 *
		 * @param array<string,string> $out     Code => name.
		 * @param string               $country Base country of the store.
		 */
		return (array) apply_filters( 'catcode_rma_carriers', $out, $country );
	}

	public static function carrier_label( string $code ): string {
		$all = array_merge( self::known_carriers(), self::carriers() );
		return isset( $all[ $code ] ) ? $all[ $code ] : $code;
	}

	/** Public tracking page for a waybill, '' when the carrier has none. */
	public static function tracking_url( string $carrier, string $ttn ): string {
		if ( '' === $ttn ) {
			return '';
		}
		if ( 'np' === $carrier ) {
			return 'https://novaposhta.ua/tracking/?cargo_number=' . rawurlencode( $ttn );
		}
		if ( 'ukrposhta' === $carrier ) {
			return 'https://track.ukrposhta.ua/tracking_UA.html?barcode=' . rawurlencode( $ttn );
		}
		return '';
	}
}
