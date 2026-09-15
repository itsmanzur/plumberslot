<?php
/**
 * /plumberslot/v1/meetings — provider status, OAuth connect/disconnect.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Meetings\GoogleMeetProvider;
use PlumberSlot\Meetings\ProviderRegistry;
use PlumberSlot\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class MeetingsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly ProviderRegistry $providers
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		// List provider status for the current tutor.
		register_rest_route(
			self::NAMESPACE,
			'/meetings/providers',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'can_act' ),
			)
		);

		// Redirect the browser to Google's consent screen.
		register_rest_route(
			self::NAMESPACE,
			'/meetings/google/connect',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'google_connect' ),
				'permission_callback' => array( $this, 'can_act' ),
			)
		);

		// Google redirects here after consent.
		register_rest_route(
			self::NAMESPACE,
			'/meetings/google/callback',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'google_callback' ),
				'permission_callback' => '__return_true',
			)
		);

		// Disconnect Google.
		register_rest_route(
			self::NAMESPACE,
			'/meetings/google/disconnect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'google_disconnect' ),
				'permission_callback' => array( $this, 'can_act' ),
			)
		);
	}

	public function status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$tutor_user_id = get_current_user_id();
		$out           = array();

		foreach ( $this->providers->all() as $provider ) {
			$out[] = array(
				'id'          => $provider->id(),
				'label'       => $provider->label(),
				'connected'   => $provider->is_connected( $tutor_user_id ),
				'connect_url' => 'google_meet' === $provider->id()
					? rest_url( self::NAMESPACE . '/meetings/google/connect' )
					: null,
			);
		}

		return $this->ok( $out );
	}

	public function google_connect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( '' === \PlumberSlot\Support\Settings::string( 'google_client_id' ) ) {
			return new WP_Error(
				'plumberslot_google_not_configured',
				__( 'Google client id is not set in PlumberSlot settings.', 'plumberslot' ),
				array( 'status' => 400 )
			);
		}

		$url = GoogleMeetProvider::authorization_url( get_current_user_id() );

		return $this->ok( array( 'redirect' => $url ) );
	}

	/**
	 * Google redirects here; store refresh token and redirect to admin.
	 */
	public function google_callback( WP_REST_Request $request ): void {
		$code  = sanitize_text_field( (string) $request->get_param( 'code' ) );
		$state = sanitize_text_field( (string) $request->get_param( 'state' ) );
		$error = sanitize_text_field( (string) $request->get_param( 'error' ) );

		$redirect = admin_url( 'admin.php?page=plumberslot-settings&tab=connections' );

		if ( $error || ! $code ) {
			wp_safe_redirect( add_query_arg( 'plumberslot_google', 'cancelled', $redirect ) );
			exit;
		}

		$result = GoogleMeetProvider::handle_callback( $code, $state );

		if ( is_wp_error( $result ) ) {
			wp_safe_redirect( add_query_arg( 'plumberslot_google', 'error', $redirect ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'plumberslot_google', 'connected', $redirect ) );
		exit;
	}

	public function google_disconnect( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		GoogleMeetProvider::disconnect( get_current_user_id() );

		return $this->ok( array( 'disconnected' => true ) );
	}

	public function can_act(): bool|WP_Error {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'plumberslot_login_required', __( 'Sign in to continue.', 'plumberslot' ), array( 'status' => 401 ) );
		}

		return current_user_can( Capabilities::MANAGE_OWN );
	}
}
