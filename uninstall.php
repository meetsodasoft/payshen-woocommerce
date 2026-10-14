<?php
/**
 * Removes the plugin's settings when it is deleted. Orders and their Payshen
 * details are kept: they are the store's records.
 *
 * @package Payshen_For_WooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_payshen_settings' );

global $wpdb;

// Order locks and remembered webhook event ids (transients).
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall.
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'payshen_lock_' ) . '%',
		$wpdb->esc_like( '_transient_payshen_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_payshen_' ) . '%',
		$wpdb->esc_like( '_site_transient_payshen_' ) . '%',
		$wpdb->esc_like( '_site_transient_timeout_payshen_' ) . '%'
	)
);

// Scheduled checks.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
	try {
		as_unschedule_all_actions( '', array(), 'payshen' );
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Nothing left to clean if the scheduler is not ready.
	}
}
