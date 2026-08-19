<?php
/**
 * WordPress session-token adapter.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides verifier-based access to WordPress sessions without persisting raw tokens.
 */
final class OliForge_Session_Store {

	/**
	 * Hashes a raw WordPress session token using the same algorithm as core.
	 */
	public function hash_token( string $token ): string {
		return hash( 'sha256', $token );
	}

	/**
	 * Returns the verifier for the current request, or an empty string.
	 */
	public function current_session_hash(): string {
		$token = wp_get_session_token();

		return '' !== $token ? $this->hash_token( $token ) : '';
	}

	/**
	 * Returns core's session map keyed by SHA-256 verifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function get_sessions( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$sessions = get_user_meta( $user_id, 'session_tokens', true );

		return is_array( $sessions ) ? $sessions : array();
	}

	/**
	 * Whether a verifier maps to a non-expired WordPress session.
	 */
	public function is_active( int $user_id, string $session_hash ): bool {
		if ( $user_id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $session_hash ) ) {
			return false;
		}

		$sessions = $this->get_sessions( $user_id );

		return isset( $sessions[ $session_hash ] )
			&& ! empty( $sessions[ $session_hash ]['expiration'] )
			&& (int) $sessions[ $session_hash ]['expiration'] >= time();
	}

	/**
	 * Removes one session by verifier while preserving every other session.
	 */
	public function destroy_by_hash( int $user_id, string $session_hash ): bool {
		if ( $user_id <= 0 || ! preg_match( '/^[a-f0-9]{64}$/', $session_hash ) ) {
			return false;
		}

		$sessions = $this->get_sessions( $user_id );
		if ( ! isset( $sessions[ $session_hash ] ) ) {
			return false;
		}

		unset( $sessions[ $session_hash ] );

		if ( $sessions ) {
			update_user_meta( $user_id, 'session_tokens', $sessions );
		} else {
			delete_user_meta( $user_id, 'session_tokens' );
		}

		return true;
	}
}
