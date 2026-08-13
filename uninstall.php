<?php
/**
 * Uninstall cleanup.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'oliforge_session_control_settings' );
delete_option( 'oliforge_session_control_db_version' );

delete_metadata(
	'user',
	0,
	'_oliforge_session_last_activity',
	'',
	true
);

global $wpdb;
$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'oliforge_session_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
