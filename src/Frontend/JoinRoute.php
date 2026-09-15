<?php
/**
 * /plumberslot/join — signed meeting join URL.
 *
 * The raw meeting link (Zoom join URL, Google Meet hangoutLink) never appears
 * in an email body. Instead the email contains a signed, expiring URL pointing
 * here. This handler verifies the signature, resolves the real link through the
 * provider, and issues a short-lived redirect — so a forwarded confirmation
 * email cannot admit a stranger into the appointment.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Frontend;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Meetings\ProviderRegistry;
use PlumberSlot\Support\Crypto;

defined( 'ABSPATH' ) || exit;

final class JoinRoute {

	public function __construct(
		private readonly BookingRepository $bookings,
		private readonly ProviderRegistry $providers
	) {}

	public function register(): void {
		add_action( 'init', array( $this, 'add_rewrite' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle' ) );
	}

	public function add_rewrite(): void {
		add_rewrite_rule( '^plumberslot/join/?$', 'index.php?plumberslot_join=1', 'top' );
	}

	/**
	 * @param list<string> $vars Public query variables.
	 * @return list<string>
	 */
	public function query_vars( array $vars ): array {
		$vars[] = 'plumberslot_join';

		return $vars;
	}

	public function handle(): void {
		if ( ! get_query_var( 'plumberslot_join' ) ) {
			return;
		}

		// The signed HMAC below provides integrity; unslash and sanitize before use.
		$booking_id = isset( $_GET['ts_booking'] ) ? absint( wp_unslash( $_GET['ts_booking'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$expires    = isset( $_GET['ts_expires'] ) ? absint( wp_unslash( $_GET['ts_expires'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$signature  = isset( $_GET['ts_sig'] ) ? sanitize_text_field( wp_unslash( $_GET['ts_sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $booking_id || ! $expires || ! $signature ) {
			wp_die( esc_html__( 'Invalid meeting link.', 'plumberslot' ), '', array( 'response' => 400 ) );
		}

		/** @var object{meeting_token:string,customer_id:int,technician_id:int,meeting_ref:string}|null $booking */
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking || empty( $booking->meeting_token ) ) {
			wp_die( esc_html__( 'Meeting not found.', 'plumberslot' ), '', array( 'response' => 404 ) );
		}

		if ( ! Crypto::verify_join( $booking_id, (string) $booking->meeting_token, $expires, $signature ) ) {
			wp_die( esc_html__( 'This meeting link has expired or is invalid. Please check your email for a new link.', 'plumberslot' ), '', array( 'response' => 403 ) );
		}

		if ( ! is_user_logged_in() ) {
			wp_die( esc_html__( 'Sign in with a lesson participant account to join this meeting.', 'plumberslot' ), '', array( 'response' => 403 ) );
		}

		// Only the confirmed participants may join.
		$user_id = get_current_user_id();
		$allowed = array( (int) $booking->customer_id );

		// Resolve technician user_id from technician row.
		$technician_row = ( new \PlumberSlot\Database\Repository\TechnicianRepository() )->find( (int) $booking->technician_id );
		if ( $technician_row ) {
			$allowed[] = (int) $technician_row->user_id;
		}

		if ( ! in_array( $user_id, $allowed, true ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not a participant of this lesson.', 'plumberslot' ), '', array( 'response' => 403 ) );
		}

		// Resolve the real URL through the provider.
		$stored_ref = (string) $booking->meeting_ref;
		$parts      = explode( '|', $stored_ref, 2 );

		if ( 2 !== count( $parts ) ) {
			wp_die( esc_html__( 'Meeting reference is malformed.', 'plumberslot' ), '', array( 'response' => 500 ) );
		}

		// Let the provider know which technician this is for join_url resolution.
		add_filter(
			'plumberslot_join_technician_id',
			static fn() => $technician_row ? (int) $technician_row->user_id : 0
		);

		$provider = $this->providers->get( $parts[0] );

		if ( ! $provider ) {
			wp_die( esc_html__( 'The meeting provider is unavailable.', 'plumberslot' ), '', array( 'response' => 503 ) );
		}

		$join_url = $provider->join_url( $parts[1] );

		if ( ! $join_url ) {
			wp_die( esc_html__( 'Could not retrieve the meeting link. Please contact your technician.', 'plumberslot' ), '', array( 'response' => 503 ) );
		}

		wp_redirect( esc_url_raw( $join_url ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- provider URL is external.
		exit;
	}
}
