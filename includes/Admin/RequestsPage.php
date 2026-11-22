<?php
/**
 * WooCommerce → Returns: the list, a single request, and the actions on it.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Admin;

use CatCode\Returns\Core\Repository;
use CatCode\Returns\Core\Requests;
use CatCode\Returns\Core\Settings;
use CatCode\Returns\Core\Statuses;

defined( 'ABSPATH' ) || exit;

class RequestsPage {

	public const SLUG = 'catcode-rma';

	/** @var string */
	private $hook_suffix = '';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_catcode_rma_update', array( $this, 'handle_update' ) );
		add_action( 'add_meta_boxes', array( $this, 'order_metabox' ), 30 );
	}

	public static function view_url( int $id ): string {
		return admin_url( 'admin.php?page=' . self::SLUG . '&action=view&id=' . $id );
	}

	public function menu(): void {
		$new   = Repository::counts()[ Statuses::NEW ] ?? 0;
		$label = Settings::is_claims() ? __( 'Claims', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Returns', 'catcode-returns-exchanges-for-woocommerce' );
		if ( $new > 0 ) {
			$label .= ' <span class="awaiting-mod count-' . (int) $new . '"><span class="pending-count">' . (int) $new . '</span></span>';
		}
		$this->hook_suffix = (string) add_submenu_page(
			'woocommerce',
			self::title(),
			$label,
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** Heading of the list: claims or returns, as the store is set up. */
	private static function title(): string {
		return Settings::is_claims() ? __( 'Claims', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Returns and exchanges', 'catcode-returns-exchanges-for-woocommerce' );
	}

	/**
	 * The deadline of an open claim in words, or the date it was resolved.
	 *
	 * @param array<string,mixed> $request Request row.
	 * @return array{text:string,late:bool}
	 */
	public static function deadline_text( array $request ): array {
		$resolved = Requests::resolved_at( $request );
		if ( $resolved > 0 ) {
			return array(
				/* translators: %s: date. */
				'text' => sprintf( __( 'Resolved on %s', 'catcode-returns-exchanges-for-woocommerce' ), wp_date( get_option( 'date_format' ), $resolved ) ),
				'late' => false,
			);
		}
		$due  = Requests::due( $request );
		$left = Requests::days_left( $request );
		if ( $due <= 0 || null === $left ) {
			return array(
				'text' => '',
				'late' => false,
			);
		}
		$date = wp_date( get_option( 'date_format' ), $due );
		if ( $left < 0 ) {
			/* translators: 1: date, 2: number of days. */
			$text = sprintf( _n( '%1$s — %2$d day overdue', '%1$s — %2$d days overdue', -$left, 'catcode-returns-exchanges-for-woocommerce' ), $date, -$left );
		} elseif ( 0 === $left ) {
			/* translators: %s: date. */
			$text = sprintf( __( '%s — today', 'catcode-returns-exchanges-for-woocommerce' ), $date );
		} else {
			/* translators: 1: date, 2: number of days. */
			$text = sprintf( _n( '%1$s — %2$d day left', '%1$s — %2$d days left', $left, 'catcode-returns-exchanges-for-woocommerce' ), $date, $left );
		}
		return array(
			'text' => $text,
			'late' => $left <= 3,
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
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation and flash messages only.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( (string) $_GET['action'] ) ) : '';
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$msg    = isset( $_GET['catcode_rma_msg'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['catcode_rma_msg'] ) ) : '';
		$type   = isset( $_GET['catcode_rma_type'] ) ? sanitize_key( wp_unslash( (string) $_GET['catcode_rma_type'] ) ) : 'ok';
		// phpcs:enable

		echo '<div class="wrap catcode-rma-admin">';
		if ( '' !== $msg ) {
			echo '<div class="notice ' . ( 'error' === $type ? 'notice-error' : 'notice-success' ) . ' is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}

		if ( 'view' === $action && $id > 0 ) {
			$request = Repository::get( $id );
			if ( $request ) {
				$this->render_view( $request );
				echo '</div>';
				return;
			}
		}

		echo '<h1 class="wp-heading-inline">' . esc_html( self::title() ) . '</h1> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=' . SettingsPage::SLUG ) ) . '">' . esc_html__( 'Settings', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>';
		echo '<hr class="wp-header-end">';

		$table = new RequestsTable();
		$table->prepare_items();
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
		$table->search_box( __( 'Search requests', 'catcode-returns-exchanges-for-woocommerce' ), 'catcode-rma-search' );
		$table->display();
		echo '</form>';

		$guest = (int) Settings::get( 'guest_page_id', 0 );
		if ( $guest <= 0 ) {
			echo '<p class="description">' . esc_html__( 'Tip: customers without an account can send a request through a page with the [catcode_returns_form] shortcode — choose that page in the settings.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
		}
		echo '</div>';
	}

	/** @param array<string,mixed> $request Request row. */
	private function render_view( array $request ): void {
		$id     = (int) $request['id'];
		$order  = wc_get_order( (int) $request['order_id'] );
		$labels = Settings::resolution_labels();
		$cur    = array( 'currency' => (string) $request['currency'] );

		echo '<h1 class="wp-heading-inline">' . esc_html( Repository::number( $id ) ) . '</h1> ';
		echo '<mark class="catcode-rma-badge catcode-rma-badge--' . esc_attr( (string) $request['status'] ) . '">' . esc_html( Statuses::label( (string) $request['status'] ) ) . '</mark> ';
		echo '<a class="page-title-action" href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'All requests', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>';
		echo '<hr class="wp-header-end">';

		echo '<div class="catcode-rma-grid"><div class="catcode-rma-main">';

		echo '<div class="catcode-rma-card"><table class="widefat striped"><tbody>';
		$this->row( __( 'Order', 'catcode-returns-exchanges-for-woocommerce' ), $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( (string) $order->get_order_number() ) . '</a> — ' . wp_kses_post( $order->get_formatted_order_total() ) . ' (' . esc_html( (string) $order->get_payment_method_title() ) . ')' : '#' . (int) $request['order_id'], true );
		$this->row( __( 'Customer', 'catcode-returns-exchanges-for-woocommerce' ), trim( $request['customer_name'] . ', ' . $request['email'] . ', ' . $request['phone'], ', ' ) );
		$claims = Settings::is_claims();
		$this->row( $claims ? __( 'Filed on', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Created', 'catcode-returns-exchanges-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' H:i', (int) strtotime( $request['created_at'] . ' UTC' ) ) );
		$resolved = Requests::resolved_at( $request );
		if ( $claims && $resolved > 0 ) {
			$this->row( __( 'Resolved on', 'catcode-returns-exchanges-for-woocommerce' ), wp_date( get_option( 'date_format' ) . ' H:i', $resolved ) );
		} else {
			$deadline = self::deadline_text( $request );
			if ( '' !== $deadline['text'] ) {
				$this->row( __( 'Resolve by', 'catcode-returns-exchanges-for-woocommerce' ), '<span class="catcode-rma-due' . ( $deadline['late'] ? ' catcode-rma-due--late' : '' ) . '">' . esc_html( $deadline['text'] ) . '</span>', true );
			}
		}
		$this->row( $claims ? __( 'Requested resolution', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Request', 'catcode-returns-exchanges-for-woocommerce' ), isset( $labels[ $request['resolution'] ] ) ? $labels[ $request['resolution'] ] : (string) $request['resolution'] );
		$this->row( $claims ? __( 'Defect', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Reason', 'catcode-returns-exchanges-for-woocommerce' ), (string) $request['reason'] );
		if ( '' !== trim( (string) $request['comment'] ) ) {
			$this->row( __( 'Comment', 'catcode-returns-exchanges-for-woocommerce' ), (string) $request['comment'] );
		}
		if ( '' !== (string) $request['return_ttn'] ) {
			$url  = Requests::tracking_url( (string) $request['return_carrier'], (string) $request['return_ttn'] );
			$html = esc_html( Requests::carrier_label( (string) $request['return_carrier'] ) . ' ' . $request['return_ttn'] );
			if ( '' !== $url ) {
				$html .= ' — <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'track', 'catcode-returns-exchanges-for-woocommerce' ) . '</a>';
			}
			if ( '' !== (string) $request['tracking_state'] ) {
				$html .= '<br><small>' . esc_html( (string) $request['tracking_state'] ) . '</small>';
			}
			$this->row( __( 'Return waybill', 'catcode-returns-exchanges-for-woocommerce' ), $html, true );
		}
		if ( (int) $request['refund_id'] > 0 ) {
			$this->row( Statuses::DISCOUNTED === (string) $request['status'] ? __( 'Price reduction', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Refund', 'catcode-returns-exchanges-for-woocommerce' ), wp_strip_all_tags( wc_price( (float) $request['amount'], $cur ) ) );
		}
		if ( (int) $request['exchange_order_id'] > 0 ) {
			$ex = wc_get_order( (int) $request['exchange_order_id'] );
			$this->row( __( 'Replacement order', 'catcode-returns-exchanges-for-woocommerce' ), $ex ? '<a href="' . esc_url( $ex->get_edit_order_url() ) . '">#' . esc_html( (string) $ex->get_order_number() ) . '</a>' : '#' . (int) $request['exchange_order_id'], true );
		}
		echo '</tbody></table></div>';

		$items = Repository::items( $id );
		echo '<div class="catcode-rma-card"><h2>' . esc_html__( 'Items', 'catcode-returns-exchanges-for-woocommerce' ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Item', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Qty', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Price', 'catcode-returns-exchanges-for-woocommerce' ) . '</th><th>' . esc_html__( 'Total', 'catcode-returns-exchanges-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $items as $item ) {
			$link = (int) $item['product_id'] > 0 ? get_edit_post_link( (int) $item['product_id'] ) : '';
			echo '<tr><td>' . ( $link ? '<a href="' . esc_url( $link ) . '">' . esc_html( (string) $item['name'] ) . '</a>' : esc_html( (string) $item['name'] ) ) . '</td><td>' . (int) $item['qty'] . '</td><td>' . wp_kses_post( wc_price( (float) $item['unit_total'], $cur ) ) . '</td><td>' . wp_kses_post( wc_price( (float) $item['unit_total'] * (int) $item['qty'], $cur ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';


		$history = Repository::history( $request );
		if ( $history ) {
			echo '<div class="catcode-rma-card"><h2>' . esc_html__( 'History', 'catcode-returns-exchanges-for-woocommerce' ) . '</h2><ul class="catcode-rma-history">';
			foreach ( array_reverse( $history ) as $entry ) {
				echo '<li><time>' . esc_html( get_date_from_gmt( (string) $entry['at'], 'd.m.Y H:i' ) ) . '</time> ' . esc_html( (string) $entry['text'] ) . ( '' !== (string) $entry['who'] ? ' <em>(' . esc_html( (string) $entry['who'] ) . ')</em>' : '' ) . '</li>';
			}
			echo '</ul></div>';
		}

		echo '</div><div class="catcode-rma-side">';
		$this->update_form( $request );
		echo '</div></div>';
	}

	/** @param array<string,mixed> $request Request row. */
	private function update_form( array $request ): void {
		$id = (int) $request['id'];
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="catcode-rma-card">';
		echo '<h2>' . esc_html__( 'Update the request', 'catcode-returns-exchanges-for-woocommerce' ) . '</h2>';
		echo '<input type="hidden" name="action" value="catcode_rma_update"><input type="hidden" name="id" value="' . esc_attr( (string) $id ) . '">';
		wp_nonce_field( 'catcode_rma_update_' . $id );

		echo '<p><label for="catcode-rma-status"><strong>' . esc_html__( 'Status', 'catcode-returns-exchanges-for-woocommerce' ) . '</strong></label><br><select id="catcode-rma-status" name="status">';
		foreach ( Statuses::all() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( (string) $request['status'], $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select></p>';

		echo '<p><label for="catcode-rma-message"><strong>' . esc_html__( 'Message to the customer', 'catcode-returns-exchanges-for-woocommerce' ) . '</strong></label><br><textarea id="catcode-rma-message" name="message" rows="4" class="widefat" placeholder="' . esc_attr__( 'E.g. the address to send the item to, or why the request was rejected.', 'catcode-returns-exchanges-for-woocommerce' ) . '"></textarea></p>';

		echo '<p><label><input type="checkbox" name="notify" value="1" checked> ' . esc_html__( 'E-mail the customer about the change', 'catcode-returns-exchanges-for-woocommerce' ) . '</label></p>';

		echo '<p><label for="catcode-rma-carrier"><strong>' . esc_html__( 'Return waybill', 'catcode-returns-exchanges-for-woocommerce' ) . '</strong></label><br><select id="catcode-rma-carrier" name="carrier">';
		foreach ( Requests::carriers() as $code => $label ) {
			echo '<option value="' . esc_attr( $code ) . '"' . selected( (string) $request['return_carrier'], $code, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> <input type="text" name="ttn" value="' . esc_attr( (string) $request['return_ttn'] ) . '" maxlength="40" aria-label="' . esc_attr__( 'Waybill number', 'catcode-returns-exchanges-for-woocommerce' ) . '"></p>';

		echo '<p><label for="catcode-rma-private"><strong>' . esc_html__( 'Private note (not shown to the customer)', 'catcode-returns-exchanges-for-woocommerce' ) . '</strong></label><br><textarea id="catcode-rma-private" name="private_note" rows="3" class="widefat">' . esc_textarea( (string) $request['private_note'] ) . '</textarea></p>';

		submit_button( __( 'Save', 'catcode-returns-exchanges-for-woocommerce' ), 'primary', 'submit', false );
		echo '</form>';
	}


	private function row( string $label, string $value, bool $is_html = false ): void {
		echo '<tr><th style="width:30%">' . esc_html( $label ) . '</th><td>' . ( $is_html ? wp_kses_post( $value ) : nl2br( esc_html( $value ) ) ) . '</td></tr>';
	}

	private static function back( int $id, string $message, string $type = 'ok' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'catcode_rma_msg'  => rawurlencode( mb_substr( $message, 0, 300 ) ),
					'catcode_rma_type' => $type,
				),
				self::view_url( $id )
			)
		);
		exit;
	}

	public function handle_update(): void {
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked on the next line with this id.
		check_admin_referer( 'catcode_rma_update_' . $id );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'catcode-returns-exchanges-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		$request = Repository::get( $id );
		if ( ! $request ) {
			wp_die( esc_html__( 'This request was not found.', 'catcode-returns-exchanges-for-woocommerce' ) );
		}

		$status  = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( (string) $_POST['status'] ) ) : (string) $request['status'];
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['message'] ) ) : '';
		$private = isset( $_POST['private_note'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['private_note'] ) ) : '';
		$carrier = isset( $_POST['carrier'] ) ? sanitize_key( wp_unslash( (string) $_POST['carrier'] ) ) : '';
		$ttn     = isset( $_POST['ttn'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['ttn'] ) ) : '';
		$notify  = ! empty( $_POST['notify'] );

		if ( $private !== (string) $request['private_note'] ) {
			Repository::update( $id, array( 'private_note' => $private ) );
		}
		if ( '' !== $ttn && ( $ttn !== (string) $request['return_ttn'] || $carrier !== (string) $request['return_carrier'] ) ) {
			Requests::set_waybill( $request, $carrier, $ttn, 'admin' );
		}
		if ( Statuses::is_valid( $status ) ) {
			if ( Settings::is_claims() && Statuses::REJECTED === $status && Statuses::REJECTED !== (string) $request['status'] && '' === trim( $message ) ) {
				// The consumer gets the reason of a rejection in writing.
				self::back( $id, __( 'Write in the message to the customer why the claim is rejected.', 'catcode-returns-exchanges-for-woocommerce' ), 'error' );
			}
			Requests::update_status( $id, $status, $message, $notify, wp_get_current_user()->user_login );
		}
		self::back( $id, __( 'Request updated.', 'catcode-returns-exchanges-for-woocommerce' ) );
	}


	/** Box on the order edit screen (classic and HPOS) listing its requests. */
	public function order_metabox(): void {
		$screens = array( 'shop_order' );
		if ( function_exists( 'wc_get_page_screen_id' ) ) {
			$screens[] = wc_get_page_screen_id( 'shop-order' );
		}
		foreach ( array_unique( $screens ) as $screen ) {
			add_meta_box( 'catcode-rma-order', Settings::is_claims() ? __( 'Claims', 'catcode-returns-exchanges-for-woocommerce' ) : __( 'Returns', 'catcode-returns-exchanges-for-woocommerce' ), array( $this, 'render_metabox' ), $screen, 'side', 'default' );
		}
	}

	/** @param \WP_Post|\WC_Order $post_or_order Current object. */
	public function render_metabox( $post_or_order ): void {
		$order_id = $post_or_order instanceof \WC_Order ? $post_or_order->get_id() : ( $post_or_order instanceof \WP_Post ? (int) $post_or_order->ID : 0 );
		$rows     = $order_id > 0 ? Repository::for_order( $order_id ) : array();
		if ( ! $rows ) {
			echo '<p class="description">' . esc_html__( 'No return requests for this order.', 'catcode-returns-exchanges-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			echo '<li><a href="' . esc_url( self::view_url( (int) $row['id'] ) ) . '">' . esc_html( Repository::number( (int) $row['id'] ) ) . '</a> — ' . esc_html( Statuses::label( (string) $row['status'] ) ) . '</li>';
		}
		echo '</ul>';
	}
}
