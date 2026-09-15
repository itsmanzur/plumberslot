<?php
/**
 * Zoom, via a server-to-server OAuth app.
 *
 * Server-to-server OAuth issues an access token for the whole account; no
 * per-technician grant is needed. Meetings use waiting room + auto-passcode so a
 * booked estimate call cannot be a bare open room.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Meetings;

use PlumberSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ZoomProvider implements ProviderInterface {

	private const TOKEN_URL   = 'https://zoom.us/oauth/token';
	private const MEETING_URL = 'https://api.zoom.us/v2/users/me/meetings';

	public function id(): string {
		return 'zoom';
	}

	public function label(): string {
		return __( 'Zoom', 'plumberslot' );
	}

	public function is_connected( int $technician_id ): bool {
		return '' !== Settings::string( 'zoom_account_id' )
			&& '' !== Settings::string( 'zoom_client_id' )
			&& '' !== Settings::string( 'zoom_client_secret' );
	}

	/**
	 * Create a Zoom meeting and return the meeting id.
	 *
	 * @return string|WP_Error Meeting id on success.
	 */
	public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
		$access_token = $this->access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$start = new \DateTimeImmutable( $start_utc, new \DateTimeZone( 'UTC' ) );

		$body = array(
			'topic'      => $title,
			'type'       => 2, // Scheduled meeting.
			'start_time' => $start->format( 'Y-m-d\TH:i:s\Z' ),
			'duration'   => $duration_min,
			'timezone'   => 'UTC',
			'settings'   => array(
				'waiting_room'      => true,
				'auto_recording'    => 'none',
				'join_before_host'  => false,
				'mute_upon_entry'   => true,
				'participant_video' => true,
				'use_pmi'           => false,
			),
		);

		$response = wp_remote_post(
			self::MEETING_URL,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'plumberslot_zoom_http', __( 'Could not contact Zoom.', 'plumberslot' ), array( 'status' => 502 ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'plumberslot_zoom_api', __( 'Zoom could not create the meeting.', 'plumberslot' ), array( 'status' => $code ) );
		}

		if ( empty( $payload['id'] ) ) {
			return new WP_Error( 'plumberslot_zoom_response', 'Zoom did not return a meeting id.' );
		}

		return (string) $payload['id'];
	}

	/**
	 * Delete the Zoom meeting.
	 */
	public function cancel( string $reference ): bool|WP_Error {
		$access_token = $this->access_token();

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$response = wp_remote_request(
			'https://api.zoom.us/v2/meetings/' . rawurlencode( $reference ),
			array(
				'method'  => 'DELETE',
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'plumberslot_zoom_http', __( 'Could not contact Zoom.', 'plumberslot' ), array( 'status' => 502 ) );
		}

		$code = wp_remote_retrieve_response_code( $response );

		// 204 = deleted, 404 = already gone — both are success for cleanup purposes.
		return in_array( $code, array( 204, 404 ), true );
	}

	/**
	 * Fetch the join URL for a meeting id.
	 */
	public function join_url( string $reference ): ?string {
		$access_token = $this->access_token();

		if ( is_wp_error( $access_token ) ) {
			return null;
		}

		$response = wp_remote_get(
			'https://api.zoom.us/v2/meetings/' . rawurlencode( $reference ),
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $payload ) ? ( $payload['join_url'] ?? null ) : null;
	}

	// -----------------------------------------------------------------------
	// OAuth helpers
	// -----------------------------------------------------------------------

	/**
	 * Obtain a server-to-server access token, cached for its lifetime.
	 *
	 * @return string|WP_Error
	 */
	private function access_token(): string|WP_Error {
		$transient = 'plumberslot_zoom_access_token';
		$cached    = get_transient( $transient );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$account_id    = Settings::string( 'zoom_account_id' );
		$client_id     = Settings::string( 'zoom_client_id' );
		$client_secret = Settings::string( 'zoom_client_secret' );

		if ( '' === $account_id || '' === $client_id || '' === $client_secret ) {
			return new WP_Error( 'plumberslot_zoom_not_configured', __( 'Zoom credentials are not configured.', 'plumberslot' ) );
		}

		$response = wp_remote_post(
			add_query_arg(
				array(
					'grant_type' => 'account_credentials',
					'account_id' => $account_id,
				),
				self::TOKEN_URL
			),
			array(
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth Basic authentication requires RFC 7617 encoding.
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'plumberslot_zoom_http', __( 'Could not contact Zoom.', 'plumberslot' ), array( 'status' => 502 ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $payload['access_token'] ) ) {
			return new WP_Error( 'plumberslot_zoom_token', __( 'Could not obtain a Zoom access token.', 'plumberslot' ) );
		}

		$ttl = max( 60, (int) ( $payload['expires_in'] ?? 3600 ) - 120 );
		set_transient( $transient, (string) $payload['access_token'], $ttl );

		return (string) $payload['access_token'];
	}
}
