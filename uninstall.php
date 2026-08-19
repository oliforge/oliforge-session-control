<?php
/**
 * Uninstall cleanup.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** Remove data for the current site. */
function oliforge_session_control_uninstall_site(): void {
	delete_option( 'oliforge_session_control_settings' );
	delete_option( 'oliforge_session_control_db_version' );
	delete_metadata( 'user', 0, '_oliforge_session_last_activity', '', true ); // Legacy 2.1.x cleanup.
	wp_clear_scheduled_hook( 'oliforge_session_control_cleanup' );

	global $wpdb;
	$table = $wpdb->prefix . 'oliforge_session_log';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
}

/** Remove data for every site on a multisite network. */
function oliforge_session_control_uninstall_network(): void {
	$site_ids = get_sites( array( 'fields' => 'ids', 'number' => 0 ) );
	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		oliforge_session_control_uninstall_site();
		restore_current_blog();
	}
}

if ( is_multisite() ) {
	oliforge_session_control_uninstall_network();
} else {
	oliforge_session_control_uninstall_site();
}
