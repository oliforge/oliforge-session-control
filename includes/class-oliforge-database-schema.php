<?php
/**
 * Database schema lifecycle.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OliForge_Database_Schema {
	public const VERSION = '2.0';
	public const VERSION_OPTION = 'oliforge_session_control_db_version';

	public function install(): void {
		OliForge_Session_Repository::install();
	}

	public function needs_upgrade(): bool {
		return get_option( self::VERSION_OPTION ) !== self::VERSION;
	}
}
