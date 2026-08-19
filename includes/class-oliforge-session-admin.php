<?php
/**
 * Admin hook router.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OliForge_Session_Admin {
	public function register( object $controller ): void {
		add_action( 'admin_menu', array( $controller, 'register_settings_page' ) );
		add_action( 'admin_init', array( $controller, 'register_settings' ) );
		add_action( 'admin_init', array( $controller, 'maybe_upgrade_log_table' ) );
		add_action( 'admin_enqueue_scripts', array( $controller, 'enqueue_admin_assets' ) );

		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $controller, 'register_network_page' ) );
			add_action( 'network_admin_enqueue_scripts', array( $controller, 'enqueue_admin_assets' ) );
		}
	}
}
