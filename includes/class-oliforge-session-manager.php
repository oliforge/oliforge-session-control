<?php
/**
 * Session-domain service.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OliForge_Session_Manager {
	public function __construct(
		private OliForge_Session_Store $store,
		private OliForge_Session_Repository $repository
	) {}

	public function current_hash(): string {
		return $this->store->current_session_hash();
	}

	public function is_active( int $user_id, string $session_hash ): bool {
		return $this->store->is_active( $user_id, $session_hash );
	}

	public function terminate( int $user_id, string $session_hash ): bool {
		return $this->store->destroy_by_hash( $user_id, $session_hash );
	}

	public function last_seen( int $user_id, string $session_hash ): int {
		return $this->repository->last_seen( $user_id, $session_hash );
	}

	public function touch( int $user_id, string $session_hash, int $timestamp ): void {
		$this->repository->touch( $user_id, $session_hash, $timestamp );
	}

	public function log_raw_token( int $user_id, string $raw_token, string $ip, string $ua, int $timestamp ): void {
		if ( '' === $raw_token ) {
			return;
		}
		$this->repository->insert_or_update( $user_id, $this->store->hash_token( $raw_token ), $ip, $ua, $timestamp, $timestamp );
	}

	/** Backfills only the current user's current session without a network-wide scan. */
	public function ensure_current_session_logged( int $user_id ): void {
		$hash = $this->current_hash();
		if ( '' === $hash || $this->repository->last_seen( $user_id, $hash ) > 0 ) {
			return;
		}
		$sessions = $this->store->get_sessions( $user_id );
		if ( ! isset( $sessions[ $hash ] ) ) {
			return;
		}
		$session = $sessions[ $hash ];
		$now = time();
		$login = isset( $session['login'] ) ? (int) $session['login'] : $now;
		$ip = isset( $session['ip'] ) ? sanitize_text_field( (string) $session['ip'] ) : '';
		$ua = isset( $session['ua'] ) ? sanitize_text_field( (string) $session['ua'] ) : '';
		$this->repository->insert_or_update( $user_id, $hash, $ip, $ua, $login, $login );
	}

	/**
	 * @return int Number of inserted rows.
	 */
	public function sync_all_live_sessions(): int {
		global $wpdb;
		$table = OliForge_Session_Repository::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- table name via %i; one-off backfill scan, not a candidate for object caching.
		$existing = $wpdb->get_results( $wpdb->prepare( 'SELECT user_id, session_hash FROM %i', $table ) );
		$known = array();
		foreach ( $existing as $row ) {
			$known[ (int) $row->user_id . ':' . (string) $row->session_hash ] = true;
		}
		$users = get_users( array( 'meta_query' => array( array( 'key' => 'session_tokens', 'compare' => 'EXISTS' ) ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		$now = time();
		$inserted = 0;
		foreach ( $users as $user ) {
			foreach ( $this->store->get_sessions( (int) $user->ID ) as $hash => $session ) {
				if ( empty( $session['expiration'] ) || (int) $session['expiration'] < $now || isset( $known[ $user->ID . ':' . $hash ] ) ) {
					continue;
				}
				$login = isset( $session['login'] ) ? (int) $session['login'] : $now;
				$ip = isset( $session['ip'] ) ? sanitize_text_field( (string) $session['ip'] ) : '';
				$ua = isset( $session['ua'] ) ? sanitize_text_field( (string) $session['ua'] ) : '';
				$this->repository->insert_or_update( (int) $user->ID, (string) $hash, $ip, $ua, $login, $login );
				$known[ $user->ID . ':' . $hash ] = true;
				++$inserted;
			}
		}
		return $inserted;
	}
}
