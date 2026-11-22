<?php
/**
 * My Account: a "Returns" tab, a button in each eligible order.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Front;

use CatCode\Returns\Core\Eligibility;
use CatCode\Returns\Core\Repository;
use CatCode\Returns\Core\Requests;
use CatCode\Returns\Core\Settings;

defined( 'ABSPATH' ) || exit;

class Account {

	public const ENDPOINT = 'returns';

	/**
	 * Orders whose "Return / exchange" action WooCommerce has already been handed
	 * on this page load. The order-details template (WC 9+) prints those actions
	 * in its own "Actions" row, so the section below must not repeat the button.
	 *
	 * @var array<int,bool>
	 */
	private static $action_given = array();

	public static function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function register(): void {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'content' ) );
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'order_action' ), 10, 2 );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_details' ) );
	}

	/**
	 * @param array<string,string> $vars Query vars.
	 * @return array<string,string>
	 */
	public function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * @param array<string,string> $items Menu items.
	 * @return array<string,string>
	 */
	public function menu( $items ) {
		if ( ! Settings::is_on( 'enabled' ) || ! is_array( $items ) ) {
			return $items;
		}
		$name = Settings::is_claims() ? __( 'Claims', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Returns', 'catcode-returns-exchanges-for-woocommerce' );
		$out  = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'orders' === $key ) {
				$out[ self::ENDPOINT ] = $name;
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out[ self::ENDPOINT ] = $name;
		}
		return $out;
	}

	public function title(): string {
		return Settings::is_claims() ? __( 'Claims', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Returns and exchanges', 'catcode-returns-exchanges-for-woocommerce' );
	}

	/** Label of the button that opens the form for an order. */
	private static function button_label(): string {
		return Settings::is_claims() ? __( 'File a claim', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Return / exchange', 'catcode-returns-exchanges-for-woocommerce' );
	}

	private static function url_for_order( int $order_id ): string {
		return add_query_arg( 'rma_order', $order_id, wc_get_account_endpoint_url( self::ENDPOINT ) );
	}

	public function content(): void {
		$user = get_current_user_id();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only navigation; ownership is checked below.
		$view  = isset( $_GET['rma_view'] ) ? absint( $_GET['rma_view'] ) : 0;
		$order = isset( $_GET['rma_order'] ) ? absint( $_GET['rma_order'] ) : 0;
		// phpcs:enable

		if ( $view > 0 ) {
			$request = Repository::get( $view );
			if ( ! $request || (int) $request['customer_id'] !== $user ) {
				echo '<p>' . esc_html__( 'This request was not found.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
				return;
			}
			echo '<p><a href="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '">&larr; ' . esc_html__( 'All requests', 'catcode-returns-exchanges-for-woocommerce' ) . '</a></p>';
			Form::render_view( $request );
			return;
		}

		if ( $order > 0 ) {
			$wc_order = wc_get_order( $order );
			if ( ! $wc_order instanceof \WC_Order || (int) $wc_order->get_customer_id() !== $user ) {
				echo '<p>' . esc_html__( 'This order was not found.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
				return;
			}
			Form::render_create( $wc_order );
			return;
		}

		$requests = Repository::for_customer( $user );
		if ( $requests ) {
			echo '<h3>' . esc_html__( 'Your requests', 'catcode-returns-exchanges-for-woocommerce' ) . '</h3>';
			Form::render_list(
				$requests,
				static function ( $row ) {
					return add_query_arg( 'rma_view', (int) $row['id'], wc_get_account_endpoint_url( self::ENDPOINT ) );
				}
			);
		}

		$eligible = array();
		if ( Settings::is_on( 'enabled' ) ) {
			$orders = wc_get_orders(
				array(
					'customer_id' => $user,
					'status'      => array_map(
						static function ( $s ) {
							return 0 === strpos( $s, 'wc-' ) ? substr( $s, 3 ) : $s;
						},
						(array) Settings::get( 'order_statuses', array( 'wc-completed' ) )
					),
					'limit'       => 20,
					'orderby'     => 'date',
					'order'       => 'DESC',
				)
			);
			foreach ( $orders as $o ) {
				if ( $o instanceof \WC_Order && Eligibility::order_is_eligible( $o ) ) {
					$eligible[] = $o;
				}
			}
		}

		if ( $eligible ) {
			echo '<h3>' . esc_html( Settings::is_claims() ? __( 'Orders you can file a claim for', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Orders you can return', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</h3>';
			echo '<table class="shop_table"><tbody>';
			foreach ( $eligible as $o ) {
				echo '<tr><td>#' . esc_html( (string) $o->get_order_number() ) . '</td><td>' . esc_html( wc_format_datetime( $o->get_date_created() ) ) . '</td><td>' . wp_kses_post( $o->get_formatted_order_total() ) . '</td>';
				echo '<td><a class="button" href="' . esc_url( self::url_for_order( $o->get_id() ) ) . '">' . esc_html( self::button_label() ) . '</a></td></tr>';
			}
			echo '</tbody></table>';
		} elseif ( ! $requests ) {
			echo '<p>' . esc_html( Settings::is_claims() ? __( 'You have no claims yet. A claim can be filed for a delivered order within the claim period.', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'You have no return requests yet. A return can be requested for a delivered order within the return period.', 'catcode-returns-exchanges-for-woocommerce' ) ) . '</p>';
		}
	}

	/**
	 * "Return / exchange" button in the orders list.
	 *
	 * @param array<string,array{url:string,name:string}> $actions Actions.
	 * @param \WC_Order                                   $order   Order.
	 * @return array<string,array{url:string,name:string}>
	 */
	public function order_action( $actions, $order ) {
		if ( $order instanceof \WC_Order && Eligibility::order_is_eligible( $order ) ) {
			self::$action_given[ $order->get_id() ] = true;
			$actions['catcode_rma'] = array(
				'url'  => self::url_for_order( $order->get_id() ),
				'name' => self::button_label(),
			);
		}
		return $actions;
	}

	/**
	 * On the single order page: existing requests and the button.
	 *
	 * @param \WC_Order $order Order.
	 */
	public function order_details( $order ): void {
		if ( ! $order instanceof \WC_Order || ! is_account_page() ) {
			return;
		}
		$user = get_current_user_id();
		if ( $user <= 0 || (int) $order->get_customer_id() !== $user ) {
			return;
		}
		$requests = Repository::for_order( $order->get_id() );
		$eligible = Eligibility::order_is_eligible( $order );
		if ( ! $requests && ! $eligible ) {
			return;
		}
		echo '<section class="catcode-rma-order"><h2>' . esc_html( $this->title() ) . '</h2>';
		Form::render_list(
			$requests,
			static function ( $row ) {
				return Requests::customer_url( $row );
			}
		);
		if ( $eligible && empty( self::$action_given[ $order->get_id() ] ) ) {
			echo '<p><a class="button" href="' . esc_url( self::url_for_order( $order->get_id() ) ) . '">' . esc_html( self::button_label() ) . '</a></p>';
		}
		echo '</section>';
	}
}
