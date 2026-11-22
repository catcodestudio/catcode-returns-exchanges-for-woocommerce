<?php
/**
 * Database access for requests and their items.
 *
 * @package CatCodeReturns
 */

namespace CatCode\Returns\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom tables owned by this plugin.

class Repository {

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'catcode_rma_requests';
	}

	public static function items_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'catcode_rma_items';
	}

	/** Human request number, e.g. RMA-000042. */
	public static function number( int $id ): string {
		return 'RMA-' . str_pad( (string) $id, 6, '0', STR_PAD_LEFT );
	}

	/**
	 * @param array<string,mixed>            $data  Request columns.
	 * @param array<int,array<string,mixed>> $items Item rows.
	 */
	public static function insert( array $data, array $items ): int {
		global $wpdb;
		$now                = current_time( 'mysql', true );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$data['access_key'] = wp_generate_password( 32, false, false );

		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;

		foreach ( $items as $item ) {
			$item['request_id'] = $id;
			$wpdb->insert( self::items_table(), $item );
		}

		return $id;
	}

	/** @param array<string,mixed> $data Columns to change. */
	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql', true );
		return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
	}

	/** @return array<string,mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function items( int $request_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE request_id = %d ORDER BY id ASC', self::items_table(), $request_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_order( int $order_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE order_id = %d ORDER BY id DESC', self::table(), $order_id ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/** @return array<int,array<string,mixed>> */
	public static function for_customer( int $customer_id, int $limit = 50 ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d ORDER BY id DESC LIMIT %d', self::table(), $customer_id, $limit ), ARRAY_A );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Quantity of each order item already held by requests that were not rejected.
	 *
	 * @return array<int,int> order_item_id => qty
	 */
	public static function reserved_qty( int $order_id, int $exclude_request = 0 ): array {
		global $wpdb;
		$holding = Statuses::holding();
		$in      = implode( ',', array_fill( 0, count( $holding ), '%s' ) );
		$args    = array_merge( array( self::items_table(), self::table(), $order_id, $exclude_request ), $holding );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $in is a list of %s placeholders.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT i.order_item_id, SUM(i.qty) AS qty FROM %i i INNER JOIN %i r ON r.id = i.request_id
				WHERE r.order_id = %d AND r.id <> %d AND r.status IN ($in) GROUP BY i.order_item_id",
				$args
			),
			ARRAY_A
		);
		// phpcs:enable
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['order_item_id'] ] = (int) $row['qty'];
		}
		return $out;
	}

	/**
	 * Admin list query.
	 *
	 * @param array<string,mixed> $args status, search, per_page, page, orderby, order.
	 * @return array{rows:array<int,array<string,mixed>>,total:int}
	 */
	public static function query( array $args ): array {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = (string) $args['status'];
		}
		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			$ids     = array_map( 'intval', $args['ids'] );
			$where[] = 'id IN (' . implode( ',', array_fill( 0, count( $ids ), '%d' ) ) . ')';
			$params  = array_merge( $params, $ids );
		}
		if ( ! empty( $args['search'] ) ) {
			$search = trim( (string) $args['search'] );
			$num    = (int) preg_replace( '/\D+/', '', $search );
			$like   = '%' . $wpdb->esc_like( $search ) . '%';
			$where[]  = '(id = %d OR order_id = %d OR email LIKE %s OR phone LIKE %s OR customer_name LIKE %s OR return_ttn LIKE %s)';
			$params   = array_merge( $params, array( $num, $num, $like, $like, $like, $like ) );
		}

		$orderby  = in_array( $args['orderby'] ?? '', array( 'id', 'order_id', 'status', 'created_at', 'amount' ), true ) ? $args['orderby'] : 'id';
		$order    = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per_page = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
		$offset   = ( $page - 1 ) * $per_page;

		$sql_where = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $sql_where only holds placeholders; orderby/order are whitelisted above.
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$sql_where}", array_merge( array( self::table() ), $params ) ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM %i WHERE {$sql_where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				array_merge( array( self::table() ), $params, array( $per_page, $offset ) )
			),
			ARRAY_A
		);
		// phpcs:enable

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/** @return array<string,int> status => count */
	public static function counts(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS n FROM %i GROUP BY status', self::table() ), ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (string) $row['status'] ] = (int) $row['n'];
		}
		return $out;
	}

	/**
	 * Append a line to the request history.
	 */
	public static function log( int $id, string $text, string $who = '' ): void {
		$row = self::get( $id );
		if ( ! $row ) {
			return;
		}
		$history   = self::history( $row );
		$history[] = array(
			'at'   => current_time( 'mysql', true ),
			'who'  => $who,
			'text' => $text,
		);
		self::update( $id, array( 'history' => (string) wp_json_encode( $history, JSON_UNESCAPED_UNICODE ) ) );
	}

	/**
	 * @param array<string,mixed> $row Request row.
	 * @return array<int,array{at:string,who:string,text:string}>
	 */
	public static function history( array $row ): array {
		$decoded = json_decode( (string) ( $row['history'] ?? '' ), true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
