<?php
/**
 * Settings repository — one option holds the whole configuration.
 *
 * Translatable defaults (reasons, policy text) are never written to the
 * database: an empty stored value means "use the translated default", so a shop
 * switching the admin language does not end up with English text frozen in.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

defined( 'ABSPATH' ) || exit;

class Settings {

	public const OPTION = 'catcode_rma_settings';

	/** @var array<string,mixed>|null */
	private static $cache = null;

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'enabled'            => 'yes',
			'request_type'       => 'returns',     // returns | claims (claims for defects, e.g. Czech reklamace).
			'window_days'        => 14,            // Returns: 0 = no time limit.
			'claim_months'       => 24,            // Claims: months after the order is completed, 0 = no time limit.
			'resolve_days'       => 30,            // Claims: days to resolve, counted from the day of the claim, 0 = not shown.
			'order_statuses'     => array( 'wc-completed' ),
			'resolutions'        => array( 'refund', 'exchange' ),
			'reasons'            => '',            // One per line; empty = translated defaults.
			'policy_text'        => '',            // Shown above the form; empty = translated default.
			'guest_page_id'      => 0,             // Page holding the [catcode_returns_form] shortcode.
			'admin_email'        => '',            // Empty = the site admin e-mail.
			'notify_admin'       => 'yes',
			'notify_customer'    => 'yes',
			'require_comment'    => 'no',
		);
	}

	/** @return array<string,mixed> */
	public static function all(): array {
		if ( null !== self::$cache ) {
			return self::$cache;
		}
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		self::$cache = wp_parse_args( $raw, self::defaults() );
		return self::$cache;
	}

	/**
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	public static function is_on( string $key ): bool {
		return 'yes' === self::get( $key, 'no' );
	}

	/**
	 * Merge a few keys into the stored option. Keys the caller does not pass keep
	 * their stored value, so a form only ever changes the fields it shows.
	 *
	 * @param array<string,mixed> $patch Keys to overwrite.
	 */
	public static function update( array $patch ): void {
		// Read the option itself, not the static cache: the cache may predate a
		// write made earlier in this request.
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		update_option( self::OPTION, array_merge( self::defaults(), $raw, $patch ), false );
		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	/** @return array<int,string> Return reasons shown to the customer. */
	public static function reasons(): array {
		$stored = trim( (string) self::get( 'reasons', '' ) );
		if ( '' === $stored ) {
			return self::default_reasons();
		}
		$lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $stored ) ) );
		return array_values( $lines ? $lines : self::default_reasons() );
	}

	/** @return array<int,string> */
	public static function default_reasons(): array {
		if ( self::is_claims() ) {
			return array(
				__( 'The item stopped working', 'catcode-returns-exchanges-for-woocommerce' ),
				__( 'Visible defect or damage', 'catcode-returns-exchanges-for-woocommerce' ),
				__( 'Does not match the description', 'catcode-returns-exchanges-for-woocommerce' ),
				__( 'Missing parts or accessories', 'catcode-returns-exchanges-for-woocommerce' ),
				__( 'Other', 'catcode-returns-exchanges-for-woocommerce' ),
			);
		}
		return array(
			__( 'Did not fit (size / shape)', 'catcode-returns-exchanges-for-woocommerce' ),
			__( 'Changed my mind', 'catcode-returns-exchanges-for-woocommerce' ),
			__( 'Defective or damaged item', 'catcode-returns-exchanges-for-woocommerce' ),
			__( 'Wrong item delivered', 'catcode-returns-exchanges-for-woocommerce' ),
			__( 'Does not match the description', 'catcode-returns-exchanges-for-woocommerce' ),
			__( 'Other', 'catcode-returns-exchanges-for-woocommerce' ),
		);
	}

	/** The form takes claims for defects instead of returns. */
	public static function is_claims(): bool {
		return 'claims' === self::get( 'request_type', 'returns' );
	}

	/** Days the shop has to resolve a claim, 0 when no deadline is kept. */
	public static function resolve_days(): int {
		return self::is_claims() ? max( 0, (int) self::get( 'resolve_days', 30 ) ) : 0;
	}

	public static function policy_text(): string {
		$stored = trim( (string) self::get( 'policy_text', '' ) );
		if ( '' !== $stored ) {
			return $stored;
		}
		if ( self::is_claims() ) {
			return self::claims_policy_text();
		}
		$days = (int) self::get( 'window_days', 14 );
		if ( $days <= 0 ) {
			return __( 'Choose the items you want to return or exchange. The item must keep its original look, packaging and labels.', 'catcode-returns-exchanges-for-woocommerce' );
		}
		return sprintf(
			/* translators: %d: number of days for a return. */
			_n(
				'You can return or exchange an item within %d day of receiving it. The item must keep its original look, packaging and labels.',
				'You can return or exchange an item within %d days of receiving it. The item must keep its original look, packaging and labels.',
				$days,
				'catcode-returns-exchanges-for-woocommerce'
			),
			$days
		);
	}

	private static function claims_policy_text(): string {
		$months = max( 0, (int) self::get( 'claim_months', 24 ) );
		$days   = self::resolve_days();
		$text   = $months > 0
			? sprintf(
				/* translators: %d: number of months for a claim. */
				_n(
					'You can file a claim for a defect within %d month of receiving the goods.',
					'You can file a claim for a defect within %d months of receiving the goods.',
					$months,
					'catcode-returns-exchanges-for-woocommerce'
				),
				$months
			)
			: __( 'You can file a claim for a defect of the goods you received.', 'catcode-returns-exchanges-for-woocommerce' );
		if ( $days > 0 ) {
			$text .= ' ' . sprintf(
				/* translators: %d: number of days to resolve a claim. */
				_n(
					'We will resolve it within %d day of receiving the claim.',
					'We will resolve it within %d days of receiving the claim.',
					$days,
					'catcode-returns-exchanges-for-woocommerce'
				),
				$days
			);
		}
		return $text;
	}

	public static function admin_email(): string {
		$email = trim( (string) self::get( 'admin_email', '' ) );
		return is_email( $email ) ? $email : (string) get_option( 'admin_email' );
	}

	/**
	 * Resolutions the customer may pick.
	 *
	 * @return array<string,string> code => label
	 */
	public static function resolutions(): array {
		$labels  = self::resolution_labels();
		$allowed = (array) self::get( 'resolutions', array( 'refund', 'exchange' ) );
		$out     = array();
		foreach ( $labels as $code => $label ) {
			if ( ! in_array( $code, $allowed, true ) ) {
				continue;
			}
			$out[ $code ] = $label;
		}
		if ( ! $out ) {
			$out['refund'] = $labels['refund'];
		}
		return $out;
	}

	/** @return array<string,string> */
	public static function resolution_labels(): array {
		$labels = array(
			'refund'   => __( 'Refund', 'catcode-returns-exchanges-for-woocommerce' ),
			'exchange' => __( 'Exchange', 'catcode-returns-exchanges-for-woocommerce' ),
			'repair'   => __( 'Repair', 'catcode-returns-exchanges-for-woocommerce' ),
			'discount' => __( 'Price reduction', 'catcode-returns-exchanges-for-woocommerce' ),
		);
		return $labels;
	}
}
