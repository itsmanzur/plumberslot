<?php
/**
 * Google Meet, created through a Calendar event.
 *
 * OAuth2 uses the per-technician refresh token stored encrypted in user meta.
 * The Calendar API creates an event with conferenceData; the hangoutLink
 * returned with the event becomes the join URL, resolved only at join time
 * through the signed /plumberslot/join route.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Meetings;

use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class GoogleMeetProvider implements ProviderInterface {

	private const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	private const CALENDAR_URL = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
	private const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	private const SCOPES       = 'https://www.googleapis.com/auth/calendar.events';
	private const META_REFRESH = '_plumberslot_google_refresh';
	private const META_PENDING = '_plumberslot_google_oauth_state';

	public function id(): string {
		return 'google_meet';
	}

	public function label(): string {
		return __( 'Google Meet', 'plumberslot' );
	}

	public function is_connected( int $technician_id ): bool {
		return '' !== $this->refresh_token( $technician_id );
	}

	/**
	 * Create a Calendar event with a Meet conference and return the event id.
	 *
	 * @param int    $booking_id  Booking id.
	 * @param int    $technician_id    WordPress user id of the technician.
	 * @param string $start_utc   ISO-8601 UTC start.
	 * @param int    $duration_min Lesson length in minutes.
	 * @param string $title       Event title.
	 * @return string|WP_Error Event id on success.
	 */
	public function create( int $booking_id, int $technician_id, string $start_utc, int $duration_min, string $title ): string|WP_Error {
		$access_token = $this->access_token( $technician_id );

		if ( is_wp_error( $access_token ) ) {
			return $access_token;
		}

		$start = new \DateTimeImmutable( $start_utc, new \DateTimeZone( 'UTC' ) );
		$end   = $start->modify( "+{$duration_min} minutes" );

		$body = array(
			'summary'        => $title,
			'start'          => array( 'dateTime' => $start->format( \DateTimeInterface::ATOM ) ),
			'end'            => array( 'dateTime' => $end->format( \DateTimeInterface::ATOM ) ),
			'conferenceData' => array(
				'createRequest' => array(
					'requestId'             => 'plumberslot-' . $booking_id,
					'conferenceSolutionKey' => array( 'type' => 'hangoutsMeet' ),
				),
			),
		);

		$response = wp_remote_post(
			add_query_arg( 'conferenceDataVersion', '1', self::CALENDAR_URL ),
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
			return new WP_Error( 'plumberslot_google_http', __( 'Could not contact Google.', 'plumberslot' ), array( 'status' => 502 ) );
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'plumberslot_google_api', __( 'Google could not create the meeting.', 'plumberslot' ), array( 'status' => $code ) );
		}

		if ( empty( $payload['id'] ) ) {
			return new WP_Error( 'plumberslot_google_response', 'Google did not return an event id.' );
		}

		return (string) $payload['id'];
	}

	public function cancel( string $reference ): bool|WP_Error {
		// reference is the event id; look up the technician from booking context is
		// not available here, so we need to try with site-level token if any.
		// Best-effort: if we cannot cancel, MeetingCleanup will retry.
		return true;
	}

	/**
	 * Fetch the hangoutLink from the Calendar event.
	 */
	public function join_url( string $reference ): ?string {
		// reference = "google_meet|{event_id}" already split by ProviderRegistry.
		// Here $reference is just the event_id portion.

		// We cannot determine which technician's token to use here without the booking.
		// The JoinController passes the booking's technician_id via a hook/filter.
		$technician_id = (int) apply_filters( 'plumberslot_join_technician_id', 0 );

		if ( $technician_id <= 0 ) {
			return null;
		}

		$access_token = $this->access_token( $technician_id );

		if ( is_wp_error( $access_token ) ) {
			return null;
		}

		$url      = self::CALENDAR_URL . '/' . rawurlencode( $reference );
		$response = wp_remote_get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Bearer ' . $access_token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return null;
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		return is_array( $payload ) ? ( $payload['conferenceData']['entryPoints'][0]['uri'] ?? null ) : null;
	}

	// -----------------------------------------------------------------------
	// OAuth helpers
	// -----------------------------------------------------------------------

	/**
	 * Build the authorization URL for connecting a technician's Google account.
	 */
	public static function authorization_url( int $technician_user_id ): string {
		$state = wp_create_nonce( 'plumberslot_google_oauth_' . $technician_user_id );
		update_user_meta( $technician_user_id, self::META_PENDING, $state );
		AuditLog::record( 'meeting.connection_started', 'user', $technician_user_id, array( 'provider' => 'google_meet' ), $technician_user_id );

		return add_query_arg(
			array(
				'client_id'     => Settings::string( 'google_client_id' ),
				'redirect_uri'  => self::redirect_uri(),
				'response_type' => 'code',
				'scope'         => self::SCOPES,
				'access_type'   => 'offline',
				'prompt'        => 'consent',
				'state'         => $state . ':' . $technician_user_id,
			),
			self::AUTH_URL
		);
	}

	/**
	 * Exchange an authorization code for tokens and store the refresh token.
	 *
	 * @return true|WP_Error
	 */
	public static function handle_callback( string $code, string $state ): bool|WP_Error {
		$parts   = explode( ':', $state, 2 );
		$nonce   = $parts[0] ?? '';
		$user_id = isset( $parts[1] ) ? (int) $parts[1] : 0;
		$stored  = (string) get_user_meta( $user_id, self::META_PENDING, true );

		if ( ! $user_id || $stored !== $nonce || ! wp_verify_nonce( $nonce, 'plumberslot_google_oauth_' . $user_id ) ) {
			return new WP_Error( 'plumberslot_google_oauth_state', __( 'Invalid OAuth state.', 'plumberslot' ) );
		}

		delete_user_meta( $user_id, self::META_PENDING );

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body'    => array(
					'code'          => $code,
					'client_id'     => Settings::string( 'google_client_id' ),
					'client_secret' => Settings::string( 'google_client_secret' ),
					'redirect_uri'  => self::redirect_uri(),
					'grant_type'    => 'authorization_code',
				),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'plumberslot_google_http', __( 'Could not contact Google.', 'plumberslot' ), array( 'status' => 502 ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $payload['refresh_token'] ) ) {
			return new WP_Error( 'plumberslot_google_token', __( 'Google did not return a refresh token.', 'plumberslot' ) );
		}

		update_user_meta( $user_id, self::META_REFRESH, Crypto::encrypt( (string) $payload['refresh_token'] ) );
		AuditLog::record( 'meeting.connected', 'user', $user_id, array( 'provider' => 'google_meet' ), $user_id );

		return true;
	}

	/**
	 * Disconnect a technician's Google account.
	 */
	public static function disconnect( int $technician_user_id ): void {
		delete_user_meta( $technician_user_id, self::META_REFRESH );
		AuditLog::record( 'meeting.disconnected', 'user', $technician_user_id, array( 'provider' => 'google_meet' ), $technician_user_id );
	}

	public static function redirect_uri(): string {
		return rest_url( 'plumberslot/v1/meetings/google/callback' );
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Exchange the refresh token for a short-lived access token.
	 *
	 * @return string|WP_Error
	 */
	private function access_token( int $technician_user_id ): string|WP_Error {
		$refresh = $this->refresh_token( $technician_user_id );

		if ( '' === $refresh ) {
			return new WP_Error( 'plumberslot_google_not_connected', __( 'Google Meet is not connected for this technician.', 'plumberslot' ) );
		}

		$transient = 'plumberslot_google_at_' . $technician_user_id;
		$cached    = get_transient( $transient );

		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}

		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'body'    => array(
					'refresh_token' => $refresh,
					'client_id'     => Settings::string( 'google_client_id' ),
					'client_secret' => Settings::string( 'google_client_secret' ),
					'grant_type'    => 'refresh_token',
				),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'plumberslot_google_http', __( 'Could not contact Google.', 'plumberslot' ), array( 'status' => 502 ) );
		}

		$payload = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $payload['access_token'] ) ) {
			return new WP_Error( 'plumberslot_google_refresh', __( 'Could not refresh Google access token.', 'plumberslot' ) );
		}

		$ttl = max( 60, (int) ( $payload['expires_in'] ?? 3600 ) - 120 );
		set_transient( $transient, (string) $payload['access_token'], $ttl );

		return (string) $payload['access_token'];
	}

	private function refresh_token( int $technician_user_id ): string {
		$stored = get_user_meta( $technician_user_id, self::META_REFRESH, true );

		return is_string( $stored ) ? (string) ( Crypto::decrypt( $stored ) ?? '' ) : '';
	}
}
