<?php
/**
 * Which orders and items may be returned, and how many of each.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

defined( 'ABSPATH' ) || exit;

class Eligibility {

	/**
	 * The moment the return window starts counting: completion, else payment,
	 * else creation. The law counts from receipt; "completed" is the closest
	 * thing WooCommerce records.
	 */
	public static function start_date( \WC_Order $order ): int {
		foreach ( array( $order->get_date_completed(), $order->get_date_paid(), $order->get_date_created() ) as $date ) {
			if ( $date instanceof \WC_DateTime ) {
				return $date->getTimestamp();
			}
		}
		return time();
	}

	/**
	 * Timestamp when the order-wide window closes; 0 = never. Without a number of
	 * days the store's own period applies: days for returns, months for claims.
	 */
	public static function deadline( \WC_Order $order, int $days = -1 ): int {
		if ( $days < 0 && Settings::is_claims() ) {
			return self::add_months( self::start_date( $order ), (int) Settings::get( 'claim_months', 24 ) );
		}
		if ( $days < 0 ) {
			$days = (int) Settings::get( 'window_days', 14 );
		}
		if ( $days <= 0 ) {
			return 0;
		}
		return self::start_date( $order ) + $days * DAY_IN_SECONDS;
	}

	/** A calendar month later in the store's time zone; 0 months = no limit. */
	public static function add_months( int $from, int $months ): int {
		if ( $months <= 0 ) {
			return 0;
		}
		$date = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() );
		$day  = (int) $date->format( 'j' );
		$next = $date->setDate( (int) $date->format( 'Y' ), (int) $date->format( 'n' ) + $months, 1 );
		// The same day of the month, or the last day of a shorter month.
		$next = $next->setDate( (int) $next->format( 'Y' ), (int) $next->format( 'n' ), min( $day, (int) $next->format( 't' ) ) );
		return $next->getTimestamp();
	}

	/**
	 * Can this order get a new request at all? Returns '' when it can, or a
	 * customer-facing explanation why not.
	 */
	public static function order_block_reason( \WC_Order $order ): string {
		$claims = Settings::is_claims();
		if ( ! Settings::is_on( 'enabled' ) ) {
			return $claims
				? __( 'Online claims are switched off in this store.', 'catcode-returns-exchanges-for-woocommerce' )
				: __( 'Online return requests are switched off in this store.', 'catcode-returns-exchanges-for-woocommerce' );
		}
		$statuses = (array) Settings::get( 'order_statuses', array( 'wc-completed' ) );
		if ( ! in_array( 'wc-' . $order->get_status(), $statuses, true ) ) {
			return $claims
				? __( 'A claim can be filed once the order has been delivered.', 'catcode-returns-exchanges-for-woocommerce' )
				: __( 'A return can be requested once the order has been delivered.', 'catcode-returns-exchanges-for-woocommerce' );
		}
		if ( ! self::returnable_items( $order ) ) {
			$ended = self::window_ended( $order );
			if ( $ended > 0 ) {
				return sprintf(
					$claims
						/* translators: %s: date when the claim period ended. */
						? __( 'The period for claims on this order ended on %s.', 'catcode-returns-exchanges-for-woocommerce' )
						/* translators: %s: date when the return period ended. */
						: __( 'The return period for this order ended on %s.', 'catcode-returns-exchanges-for-woocommerce' ),
					date_i18n( wc_date_format(), $ended )
				);
			}
			return $claims
				? __( 'There are no items left in this order that a claim can be filed for.', 'catcode-returns-exchanges-for-woocommerce' )
				: __( 'There are no items left in this order that can be returned.', 'catcode-returns-exchanges-for-woocommerce' );
		}
		return '';
	}

	/**
	 * When the last still-unreturned item ran out of time, or 0 when something
	 * other than the deadline (a request already filed, a refund, a
	 * non-returnable category) is what keeps the order closed.
	 */
	private static function window_ended( \WC_Order $order ): int {
		$reserved = Repository::reserved_qty( $order->get_id() );
		$latest   = 0;
		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$left = (int) $item->get_quantity() - absint( $order->get_qty_refunded_for_item( $item_id ) ) - ( isset( $reserved[ $item_id ] ) ? $reserved[ $item_id ] : 0 );
			if ( $left <= 0 ) {
				continue;
			}
			$deadline = self::item_deadline( $order, $item );
			if ( false === $deadline ) {
				continue; // Non-returnable category: not a matter of time.
			}
			if ( 0 === $deadline || $deadline >= time() ) {
				return 0;
			}
			$latest = max( $latest, (int) $deadline );
		}
		return $latest;
	}

	public static function order_is_eligible( \WC_Order $order ): bool {
		return '' === self::order_block_reason( $order );
	}

	/**
	 * Items that can still go into a request, with the quantity left for each.
	 *
	 * @return array<int,array{item:\WC_Order_Item_Product,max:int,unit:float,deadline:int}> keyed by order item id
	 */
	public static function returnable_items( \WC_Order $order, int $exclude_request = 0 ): array {
		$reserved = Repository::reserved_qty( $order->get_id(), $exclude_request );
		$out      = array();

		foreach ( $order->get_items( 'line_item' ) as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$qty      = (int) $item->get_quantity();
			$refunded = absint( $order->get_qty_refunded_for_item( $item_id ) );
			$left     = $qty - $refunded - ( isset( $reserved[ $item_id ] ) ? $reserved[ $item_id ] : 0 );
			if ( $left <= 0 ) {
				continue;
			}

			$deadline = self::item_deadline( $order, $item );
			if ( false === $deadline ) {
				continue; // Non-returnable product.
			}
			if ( $deadline > 0 && time() > $deadline ) {
				continue;
			}

			$out[ (int) $item_id ] = array(
				'item'     => $item,
				'max'      => $left,
				'unit'     => $qty > 0 ? (float) $item->get_total() / $qty : 0.0,
				'deadline' => $deadline,
			);
		}

		return $out;
	}

	/**
	 * Deadline for one item.
	 *
	 * @return int|false Timestamp, 0 = no limit, false = this item cannot be returned.
	 */
	public static function item_deadline( \WC_Order $order, \WC_Order_Item_Product $item ) {
		$days = -1;
		return self::deadline( $order, $days );
	}

}
