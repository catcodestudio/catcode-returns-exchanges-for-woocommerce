<?php
/**
 * Request statuses.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

defined( 'ABSPATH' ) || exit;

class Statuses {

	public const NEW        = 'new';
	public const APPROVED   = 'approved';
	public const REJECTED   = 'rejected';
	public const RECEIVED   = 'received';
	public const REFUNDED   = 'refunded';
	public const EXCHANGED  = 'exchanged';
	public const REPAIRED   = 'repaired';
	public const DISCOUNTED = 'discounted';
	public const CLOSED     = 'closed';

	/** @return array<string,string> code => label */
	public static function all(): array {
		return array(
			self::NEW        => __( 'New', 'catcode-returns-exchanges-for-woocommerce' ),
			self::APPROVED   => __( 'Approved — awaiting the item', 'catcode-returns-exchanges-for-woocommerce' ),
			self::RECEIVED   => __( 'Item received', 'catcode-returns-exchanges-for-woocommerce' ),
			self::REFUNDED   => __( 'Money refunded', 'catcode-returns-exchanges-for-woocommerce' ),
			self::EXCHANGED  => __( 'Replacement sent', 'catcode-returns-exchanges-for-woocommerce' ),
			self::REPAIRED   => __( 'Repaired — item sent back', 'catcode-returns-exchanges-for-woocommerce' ),
			self::DISCOUNTED => __( 'Price reduction granted', 'catcode-returns-exchanges-for-woocommerce' ),
			self::REJECTED   => __( 'Rejected', 'catcode-returns-exchanges-for-woocommerce' ),
			self::CLOSED     => __( 'Closed', 'catcode-returns-exchanges-for-woocommerce' ),
		);
	}

	public static function label( string $code ): string {
		$all = self::all();
		return isset( $all[ $code ] ) ? $all[ $code ] : $code;
	}

	public static function is_valid( string $code ): bool {
		return array_key_exists( $code, self::all() );
	}

	/**
	 * Statuses that still hold the items: a rejected request frees its quantity
	 * for a new request, every other one keeps it reserved.
	 *
	 * @return array<int,string>
	 */
	public static function holding(): array {
		return array( self::NEW, self::APPROVED, self::RECEIVED, self::REFUNDED, self::EXCHANGED, self::REPAIRED, self::DISCOUNTED, self::CLOSED );
	}

	/** @return array<int,string> Statuses that end a request: the date of the first one is the date it was resolved. */
	public static function resolved(): array {
		return array( self::REFUNDED, self::EXCHANGED, self::REPAIRED, self::DISCOUNTED, self::REJECTED, self::CLOSED );
	}

	/** @return array<int,string> Statuses that need the shop's action. */
	public static function open(): array {
		return array( self::NEW, self::APPROVED, self::RECEIVED );
	}
}
