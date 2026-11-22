<?php
/**
 * Admin list of requests.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Admin;

use CatCode\Returns\Core\Repository;
use CatCode\Returns\Core\Settings;
use CatCode\Returns\Core\Statuses;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class RequestsTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'catcode-rma-request',
				'plural'   => 'catcode-rma-requests',
				'ajax'     => false,
			)
		);
	}

	/** @return array<string,string> */
	public function get_columns() {
		$claims  = Settings::is_claims();
		$columns = array(
			'id'         => $claims ? __( 'Claim', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Request', 'catcode-returns-exchanges-for-woocommerce' ),
			'order_id'   => __( 'Order', 'catcode-returns-exchanges-for-woocommerce' ),
			'customer'   => __( 'Customer', 'catcode-returns-exchanges-for-woocommerce' ),
			'resolution' => $claims ? __( 'Requested resolution', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Request', 'catcode-returns-exchanges-for-woocommerce' ),
			'reason'     => $claims ? __( 'Defect', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Reason', 'catcode-returns-exchanges-for-woocommerce' ),
			'amount'     => __( 'Amount', 'catcode-returns-exchanges-for-woocommerce' ),
			'status'     => __( 'Status', 'catcode-returns-exchanges-for-woocommerce' ),
			'created_at' => __( 'Date', 'catcode-returns-exchanges-for-woocommerce' ),
		);
		if ( Settings::resolve_days() > 0 ) {
			$columns['due'] = __( 'Resolve by', 'catcode-returns-exchanges-for-woocommerce' );
		}
		return $columns;
	}

	/** @return array<string,array{0:string,1:bool}> */
	protected function get_sortable_columns() {
		return array(
			'id'         => array( 'id', true ),
			'order_id'   => array( 'order_id', false ),
			'status'     => array( 'status', false ),
			'amount'     => array( 'amount', false ),
			'created_at' => array( 'created_at', false ),
		);
	}

	/** @return array<string,string> */
	protected function get_views() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$current = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$counts  = Repository::counts();
		$base    = admin_url( 'admin.php?page=' . RequestsPage::SLUG );
		$views   = array(
			'all' => '<a href="' . esc_url( $base ) . '"' . ( '' === $current ? ' class="current"' : '' ) . '>' . esc_html__( 'All', 'catcode-returns-exchanges-for-woocommerce' ) . ' <span class="count">(' . (int) array_sum( $counts ) . ')</span></a>',
		);
		foreach ( Statuses::all() as $code => $label ) {
			if ( empty( $counts[ $code ] ) ) {
				continue;
			}
			$views[ $code ] = '<a href="' . esc_url( add_query_arg( 'status', $code, $base ) ) . '"' . ( $current === $code ? ' class="current"' : '' ) . '>' . esc_html( $label ) . ' <span class="count">(' . (int) $counts[ $code ] . ')</span></a>';
		}
		return $views;
	}

	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$status  = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( (string) $_GET['status'] ) ) : '';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( (string) $_GET['orderby'] ) ) : 'id';
		$order   = isset( $_GET['order'] ) ? sanitize_key( wp_unslash( (string) $_GET['order'] ) ) : 'desc';
		// phpcs:enable
		$per_page = 20;
		$result   = Repository::query(
			array(
				'status'   => Statuses::is_valid( $status ) ? $status : '',
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		$this->items           = $result['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * @param array<string,mixed> $item Row.
	 * @param string              $column_name Column.
	 */
	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'order_id':
				$order = wc_get_order( (int) $item['order_id'] );
				if ( ! $order ) {
					return '#' . (int) $item['order_id'];
				}
				return '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a>';
			case 'customer':
				return esc_html( (string) $item['customer_name'] ) . '<br><small>' . esc_html( (string) $item['email'] ) . '</small>';
			case 'resolution':
				$labels = Settings::resolution_labels();
				return esc_html( isset( $labels[ $item['resolution'] ] ) ? $labels[ $item['resolution'] ] : (string) $item['resolution'] );
			case 'reason':
				return esc_html( (string) $item['reason'] );
			case 'amount':
				return wp_kses_post( wc_price( (float) $item['amount'], array( 'currency' => (string) $item['currency'] ) ) );
			case 'status':
				return '<mark class="catcode-rma-badge catcode-rma-badge--' . esc_attr( (string) $item['status'] ) . '">' . esc_html( Statuses::label( (string) $item['status'] ) ) . '</mark>';
			case 'created_at':
				return esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) strtotime( $item['created_at'] . ' UTC' ) ) );
			case 'due':
				$deadline = RequestsPage::deadline_text( $item );
				return '' === $deadline['text'] ? '—' : '<span class="catcode-rma-due' . ( $deadline['late'] ? ' catcode-rma-due--late' : '' ) . '">' . esc_html( $deadline['text'] ) . '</span>';
		}
		return '';
	}

	/** @param array<string,mixed> $item Row. */
	protected function column_id( $item ) {
		$url = RequestsPage::view_url( (int) $item['id'] );
		return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( Repository::number( (int) $item['id'] ) ) . '</a></strong>';
	}

	public function no_items() {
		esc_html_e( 'No return requests yet.', 'catcode-returns-exchanges-for-woocommerce' );
	}
}
