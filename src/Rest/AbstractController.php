<?php
/**
 * Base for every REST controller.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Support\SecretMasker;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

abstract class AbstractController {

	public const NAMESPACE = 'plumberslot/v1';

	public function __construct( protected readonly Guard $guard ) {}

	/**
	 * Register this controller's routes.
	 */
	abstract public function register_routes(): void;

	/**
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 */
	protected function ok( mixed $data, int $status = 200 ): WP_REST_Response {
		return new WP_REST_Response( SecretMasker::redact( $data ), $status );
	}

	/**
	 * Blanket rejection for anonymous writes.
	 */
	protected function require_login(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error(
				'plumberslot_login_required',
				__( 'Sign in to continue.', 'plumberslot' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Auth for cookie sessions and Application Passwords.
	 *
	 * Cookie-authenticated browser requests must send X-WP-Nonce. Requests
	 * authenticated without a logged-in cookie (Application Passwords, basic
	 * auth plugins) skip the nonce — WordPress already proved the credential.
	 */
	protected function verify_nonce( WP_REST_Request $request ): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return true;
		}

		$cookie_name = defined( 'LOGGED_IN_COOKIE' ) ? (string) constant( 'LOGGED_IN_COOKIE' ) : '';
		$uses_cookie = '' !== $cookie_name && isset( $_COOKIE[ $cookie_name ] ); // phpcs:ignore WordPressVIPMinimum.Variables.RestrictedVariables.cache_constraints___COOKIE -- auth transport detection only.

		if ( ! $uses_cookie ) {
			return true;
		}

		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'plumberslot_bad_nonce',
				__( 'Your session expired. Reload the page and try again.', 'plumberslot' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
