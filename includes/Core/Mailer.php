<?php
/**
 * E-mails to the customer and to the shop, wrapped in the WooCommerce e-mail template.
 *
 * Customer e-mails go out in the language of the order (the locale saved at
 * checkout, if any), not in the language of whoever clicked "Save" in the admin.
 *
 * For claims the two customer e-mails carry what a confirmation of a claim has to
 * say (§ 19 of the Czech Consumer Protection Act): when the claim was filed, what
 * it is about, which resolution the customer asks for and the contact for the
 * answer; and once resolved, the date and the way it was resolved.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

defined( 'ABSPATH' ) || exit;

class Mailer {

	/** @param array<string,mixed> $request Request row. */
	public static function customer_created( array $request ): void {
		if ( ! Settings::is_on( 'notify_customer' ) || ! is_email( (string) $request['email'] ) ) {
			return;
		}
		self::with_order_locale(
			(int) $request['order_id'],
			static function () use ( $request ) {
				$number = Repository::number( (int) $request['id'] );
				$store  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				if ( Settings::is_claims() ) {
					$subject = sprintf(
						/* translators: 1: claim number, 2: store name. */
						__( 'Claim %1$s received — %2$s', 'catcode-returns-exchanges-for-woocommerce' ),
						$number,
						$store
					);
					$intro = sprintf(
						/* translators: %s: claim number. */
						__( 'We have received your claim %s. This e-mail confirms when you filed it and what it is about.', 'catcode-returns-exchanges-for-woocommerce' ),
						$number
					);
				} else {
					$subject = sprintf(
						/* translators: 1: request number, 2: store name. */
						__( 'Return request %1$s received — %2$s', 'catcode-returns-exchanges-for-woocommerce' ),
						$number,
						$store
					);
					$intro = sprintf(
						/* translators: %s: request number. */
						__( 'We have received your request %s. We will review it and let you know what happens next.', 'catcode-returns-exchanges-for-woocommerce' ),
						$number
					);
				}
				$body  = '<p>' . esc_html( $intro ) . '</p>';
				$body .= self::summary( $request, true );
				$due   = Requests::due( $request );
				if ( $due > 0 ) {
					$body .= '<p>' . esc_html(
						sprintf(
							/* translators: %s: date. */
							__( 'We will resolve the claim and let you know by %s at the latest.', 'catcode-returns-exchanges-for-woocommerce' ),
							self::date( $due )
						)
					) . '</p>';
				}
				$body .= self::view_link( $request );
				self::send( (string) $request['email'], $subject, $body );
			}
		);
	}

	/**
	 * Status change and/or a message from the shop.
	 *
	 * @param array<string,mixed> $request Request row (already updated).
	 */
	public static function customer_updated( array $request, bool $status_changed, string $message ): void {
		if ( ! Settings::is_on( 'notify_customer' ) || ! is_email( (string) $request['email'] ) ) {
			return;
		}
		self::with_order_locale(
			(int) $request['order_id'],
			static function () use ( $request, $status_changed, $message ) {
				$number  = Repository::number( (int) $request['id'] );
				$status  = Statuses::label( (string) $request['status'] );
				$claims  = Settings::is_claims();
				$subject = sprintf(
					$claims
						/* translators: 1: claim number, 2: new status. */
						? __( 'Claim %1$s: %2$s', 'catcode-returns-exchanges-for-woocommerce' )
						/* translators: 1: request number, 2: new status. */
						: __( 'Return request %1$s: %2$s', 'catcode-returns-exchanges-for-woocommerce' ),
					$number,
					$status
				);
				$body = '';
				if ( $status_changed ) {
					$body .= '<p>' . esc_html(
						sprintf(
							/* translators: 1: request number, 2: new status. */
							__( 'The status of your request %1$s has changed: %2$s.', 'catcode-returns-exchanges-for-woocommerce' ),
							$number,
							$status
						)
					) . '</p>';
				}
				$resolved = Requests::resolved_at( $request );
				if ( $claims && $status_changed && $resolved > 0 ) {
					$body .= '<p><strong>' . esc_html__( 'Resolved on:', 'catcode-returns-exchanges-for-woocommerce' ) . '</strong> ' . esc_html( self::date( $resolved ) ) . '<br>';
					$body .= '<strong>' . esc_html__( 'How it was resolved:', 'catcode-returns-exchanges-for-woocommerce' ) . '</strong> ' . esc_html( $status ) . '</p>';
					if ( Statuses::REJECTED === (string) $request['status'] && '' === trim( $message ) && '' !== trim( (string) $request['customer_message'] ) ) {
						// A rejection always carries its reason, also when it was written earlier.
						$message = (string) $request['customer_message'];
					}
				}
				if ( '' !== trim( $message ) ) {
					$label = $claims && Statuses::REJECTED === (string) $request['status']
						? __( 'Why the claim was rejected:', 'catcode-returns-exchanges-for-woocommerce' )
						: __( 'Message from the store:', 'catcode-returns-exchanges-for-woocommerce' );
					$body .= '<p><strong>' . esc_html( $label ) . '</strong><br>' . nl2br( esc_html( $message ) ) . '</p>';
				}
				if ( Statuses::APPROVED === (string) $request['status'] ) {
					$body .= '<p>' . esc_html__( 'Please send the item back and enter the return waybill number on the request page, so we can track it.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
				}
				$body .= self::summary( $request );
				$body .= self::view_link( $request );
				self::send( (string) $request['email'], $subject, $body );
			}
		);
	}

	/** @param array<string,mixed> $request Request row. */
	public static function admin_created( array $request ): void {
		if ( ! Settings::is_on( 'notify_admin' ) ) {
			return;
		}
		$number  = Repository::number( (int) $request['id'] );
		$subject = sprintf(
			Settings::is_claims()
				/* translators: 1: claim number, 2: order number. */
				? __( 'New claim %1$s for order #%2$s', 'catcode-returns-exchanges-for-woocommerce' )
				/* translators: 1: request number, 2: order number. */
				: __( 'New return request %1$s for order #%2$s', 'catcode-returns-exchanges-for-woocommerce' ),
			$number,
			self::order_number( (int) $request['order_id'] )
		);
		$url  = admin_url( 'admin.php?page=catcode-rma&action=view&id=' . (int) $request['id'] );
		$body = self::summary( $request, true );
		$due  = Requests::due( $request );
		if ( $due > 0 ) {
			/* translators: %s: date. */
			$body .= '<p><strong>' . esc_html( sprintf( __( 'Resolve by %s.', 'catcode-returns-exchanges-for-woocommerce' ), self::date( $due ) ) ) . '</strong></p>';
		}
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Open the request', 'catcode-returns-exchanges-for-woocommerce' ) . '</a></p>';
		self::send( Settings::admin_email(), $subject, $body );
	}

	/**
	 * @param array<string,mixed> $request Request row.
	 * @param bool                $filing  Add when the request was filed and the contact for the answer.
	 */
	public static function summary( array $request, bool $filing = false ): string {
		$items = Repository::items( (int) $request['id'] );
		$html  = '<table cellspacing="0" cellpadding="6" border="1" style="border-collapse:collapse;width:100%;margin:0 0 16px">';
		$html .= '<tr><th style="text-align:left">' . esc_html__( 'Item', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th style="text-align:left">' . esc_html__( 'Qty', 'catcode-returns-exchanges-for-woocommerce' ) . '</th></tr>';
		foreach ( $items as $item ) {
			$html .= '<tr><td>' . esc_html( (string) $item['name'] ) . '</td><td>' . (int) $item['qty'] . '</td></tr>';
		}
		$html .= '</table>';

		$labels = Settings::resolution_labels();
		$rows   = array();
		if ( $filing ) {
			$rows[ __( 'Filed on', 'catcode-returns-exchanges-for-woocommerce' ) ] = self::date( (int) strtotime( (string) $request['created_at'] . ' UTC' ), true );
		}
		$rows[ __( 'Order', 'catcode-returns-exchanges-for-woocommerce' ) ] = '#' . self::order_number( (int) $request['order_id'] );
		$rows[ Settings::is_claims() ? __( 'Requested resolution', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Request', 'catcode-returns-exchanges-for-woocommerce' ) ] = isset( $labels[ $request['resolution'] ] ) ? $labels[ $request['resolution'] ] : (string) $request['resolution'];
		$rows[ Settings::is_claims() ? __( 'Defect', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Reason', 'catcode-returns-exchanges-for-woocommerce' ) ] = (string) $request['reason'];
		$rows[ __( 'Status', 'catcode-returns-exchanges-for-woocommerce' ) ] = Statuses::label( (string) $request['status'] );
		if ( '' !== trim( (string) $request['comment'] ) ) {
			$rows[ __( 'Comment', 'catcode-returns-exchanges-for-woocommerce' ) ] = (string) $request['comment'];
		}
		if ( $filing ) {
			$rows[ __( 'Contact for the answer', 'catcode-returns-exchanges-for-woocommerce' ) ] = trim( (string) $request['email'] . ', ' . (string) $request['phone'], ', ' );
		}
		$html .= '<p>';
		foreach ( $rows as $label => $value ) {
			$html .= '<strong>' . esc_html( $label ) . ':</strong> ' . nl2br( esc_html( $value ) ) . '<br>';
		}
		$html .= '</p>';
		return $html;
	}

	/** A date in the site format, optionally with the time. */
	public static function date( int $timestamp, bool $with_time = false ): string {
		$format = (string) get_option( 'date_format' );
		if ( $with_time ) {
			$format .= ' ' . (string) get_option( 'time_format' );
		}
		return (string) wp_date( $format, $timestamp );
	}

	/** @param array<string,mixed> $request Request row. */
	private static function view_link( array $request ): string {
		$url = Requests::customer_url( $request );
		if ( '' === $url ) {
			return '';
		}
		return '<p><a href="' . esc_url( $url ) . '">' . esc_html__( 'View the request', 'catcode-returns-exchanges-for-woocommerce' ) . '</a></p>';
	}

	private static function order_number( int $order_id ): string {
		$order = wc_get_order( $order_id );
		return $order ? (string) $order->get_order_number() : (string) $order_id;
	}

	private static function send( string $to, string $subject, string $body ): void {
		if ( ! function_exists( 'WC' ) ) {
			wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
			return;
		}
		$mailer = WC()->mailer();
		$mailer->send( $to, $subject, $mailer->wrap_message( $subject, $body ), array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/**
	 * Run a callback in the order's language. The locale comes from the order
	 * meta WPML / Polylang / our own modules save; with none, the site locale.
	 */
	private static function with_order_locale( int $order_id, callable $callback ): void {
		$locale = '';
		$order  = wc_get_order( $order_id );
		if ( $order ) {
			foreach ( array( '_catcode_lang', 'wpml_language', 'pll_lang', '_locale' ) as $key ) {
				$value = (string) $order->get_meta( $key );
				if ( '' !== $value ) {
					$locale = self::normalize_locale( $value );
					break;
				}
			}
		}
		if ( '' === $locale ) {
			// No language saved with the order: the site language, not the one of the
			// admin who clicked "Save" (an admin with another profile language would
			// otherwise send the customer an e-mail the customer cannot read).
			$locale = (string) get_locale();
		}
		$switched = '' !== $locale && function_exists( 'switch_to_locale' ) && switch_to_locale( $locale );
		try {
			$callback();
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	private static function normalize_locale( string $value ): string {
		$map = array(
			'uk' => 'uk',
			'ua' => 'uk',
			'ru' => 'ru_RU',
			'en' => 'en_US',
			'cs' => 'cs_CZ',
			'cz' => 'cs_CZ',
			'sk' => 'sk_SK',
		);
		$key = strtolower( substr( $value, 0, 2 ) );
		if ( strlen( $value ) > 2 ) {
			return $value;
		}
		return isset( $map[ $key ] ) ? $map[ $key ] : '';
	}
}
