<?php
/**
 * Session-log persistence.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OliForge_Session_Repository {

	public const DB_VERSION = '2.0';
	public const DB_VERSION_OPTION = 'oliforge_session_control_db_version';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'oliforge_session_log';
	}

	/**
	 * Creates/upgrades the log. Legacy raw tokens are converted before the
	 * unique verifier index is created, so existing installations migrate safely.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();

		// If this is the 2.1.x schema, add the destination column first.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$legacy_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'token' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$hash_column = $wpdb->get_var( $wpdb->prepare( 'SHOW COLUMNS FROM %i LIKE %s', $table, 'session_hash' ) );

			if ( $legacy_column && ! $hash_column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD COLUMN session_hash CHAR(64) NOT NULL DEFAULT '' AFTER user_id", $table ) );
			}

			if ( $legacy_column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, user_id, token, session_hash FROM %i ORDER BY id ASC', $table ) );
				$seen = array();
				foreach ( $rows as $row ) {
					$hash = (string) $row->session_hash;
					if ( '' === $hash && '' !== (string) $row->token ) {
						$hash = hash( 'sha256', (string) $row->token );
					}
					$key = (int) $row->user_id . ':' . $hash;
					if ( '' === $hash || isset( $seen[ $key ] ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration cleanup during install(); $wpdb->delete() escapes values internally.
						$wpdb->delete( $table, array( 'id' => (int) $row->id ), array( '%d' ) );
						continue;
					}
					$seen[ $key ] = true;
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time migration backfill during install(); $wpdb->update() escapes values internally.
					$wpdb->update( $table, array( 'session_hash' => $hash ), array( 'id' => (int) $row->id ), array( '%s' ), array( '%d' ) );
				}
				// Eliminate the secret-bearing legacy column after conversion.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN token', $table ) );
			}
		}

		$charset_collate = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			session_hash CHAR(64) NOT NULL,
			ip VARCHAR(100) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			login_at BIGINT UNSIGNED NOT NULL,
			last_seen_at BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY user_session (user_id, session_hash),
			KEY user_id (user_id),
			KEY last_seen_at (last_seen_at)
		) {$charset_collate};";
		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	public function insert_or_update( int $user_id, string $session_hash, string $ip, string $ua, int $login_at, int $last_seen_at ): void {
		if ( $user_id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $session_hash ) ) {
			return;
		}
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table passed via %i; the query text is a fixed literal (no interpolated variables), and all values are bound through prepare()'s %d/%s placeholders below. Atomic upsert prevents duplicate rows even when concurrent requests race.
		$wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (user_id, session_hash, ip, user_agent, login_at, last_seen_at) VALUES (%d, %s, %s, %s, %d, %d) '
				. 'ON DUPLICATE KEY UPDATE ip = VALUES(ip), user_agent = VALUES(user_agent), last_seen_at = GREATEST(last_seen_at, VALUES(last_seen_at))',
				$table,
				$user_id,
				$session_hash,
				mb_substr( $ip, 0, 100 ),
				mb_substr( $ua, 0, 255 ),
				$login_at,
				$last_seen_at
			)
		);
	}

	public function touch( int $user_id, string $session_hash, int $timestamp ): void {
		if ( $user_id <= 0 || '' === $session_hash ) {
			return;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- activity timestamp must reflect the latest write on the very next read; $wpdb->update() escapes values internally.
		$wpdb->update( self::table(), array( 'last_seen_at' => $timestamp ), array( 'user_id' => $user_id, 'session_hash' => $session_hash ), array( '%d' ), array( '%d', '%s' ) );
	}

	public function last_seen( int $user_id, string $session_hash ): int {
		if ( $user_id <= 0 || '' === $session_hash ) {
			return 0;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name via %i, values via %d/%s; single-row lookup not worth an object-cache layer.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT last_seen_at FROM %i WHERE user_id = %d AND session_hash = %s LIMIT 1', self::table(), $user_id, $session_hash ) );
	}

	public function cleanup( int $retention_days ): int {
		if ( $retention_days <= 0 ) {
			return 0;
		}
		global $wpdb;
		$cutoff = time() - ( $retention_days * DAY_IN_SECONDS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name via %i, cutoff via %d; a bulk cleanup delete, not a cacheable read.
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen_at < %d', self::table(), $cutoff ) );
	}
}
