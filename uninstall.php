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

delete_metadata(
	'user',
	0,
	'_oliforge_session_last_activity',
	'',
	true
);
