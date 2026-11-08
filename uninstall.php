<?php
/**
 * Uninstall WP Menu Cache.
 * Purges all cached menus. The plugin stores no options of its own.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$like = $wpdb->esc_like( '_transient_wmc_menu_' ) . '%';
$keys = $wpdb->get_col(
	$wpdb->prepare(
		"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
		$like
	)
);
foreach ( $keys as $option_name ) {
	delete_transient( substr( $option_name, strlen( '_transient_' ) ) );
}
