<?php
/**
 * Per-session idle timeout handler.
 *
 * @package OliForge_Session_Control
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OliForge_Idle_Timeout {
	public static function is_api_request(): bool {
		return wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
	}

	public static function expire_current_request(): void {
		wp_logout();
		nocache_headers();

		if ( self::is_api_request() ) {
			wp_send_json_error(
				array( 'message' => __( 'Your session expired due to inactivity.', 'oliforge-session-control' ) ),
				401
			);
		}

		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$redirect_to = wp_validate_redirect( home_url( $request_uri ), home_url( '/' ) );
		wp_safe_redirect( wp_login_url( add_query_arg( 'oliforge_session_expired', '1', $redirect_to ) ) );
		exit;
	}
}
