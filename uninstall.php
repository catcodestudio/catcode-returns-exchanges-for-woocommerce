<?php
/**
 * Uninstall — removes the request tables, the settings, photos and scheduled events.
 *
 * Requests hold personal data (names, phones, addresses of returns), so nothing is left behind.
 *
 * @package CatCodeReturns
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// Photos attached to requests (Pro). Cleaned in every build: a shop that
// downgraded to the free version must not keep customers' photos behind.
$catcode_rma_photos = get_posts(
	array(
		'post_type'      => 'attachment',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'meta_key'       => '_catcode_rma_request', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_query_meta_key -- one-off uninstall.
	)
);
foreach ( $catcode_rma_photos as $catcode_rma_photo ) {
	wp_delete_attachment( (int) $catcode_rma_photo, true );
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off uninstall cleanup.
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'catcode_rma_items' ) );
$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'catcode_rma_requests' ) );
// phpcs:enable

delete_option( 'catcode_rma_settings' );
delete_option( 'catcode_rma_version' );

wp_clear_scheduled_hook( 'catcode_rma_license_check' );
wp_clear_scheduled_hook( 'catcode_rma_tracking' );
