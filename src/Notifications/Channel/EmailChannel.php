<?php
/**
 * Email delivery.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications\Channel;

use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class EmailChannel implements ChannelInterface {

	public function id(): string {
		return 'email';
	}

	public function is_enabled( string $event ): bool {
		return (bool) apply_filters( 'plumberslot_email_enabled', true, $event );
	}

	/**
	 * @param array<string, mixed> $context Extra template variables.
	 */
	public function send( string $event, int $user_id, object $booking, array $context = array() ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$when = Time::for_human(
			Time::from_sql( (string) $booking->start_utc ),
			(string) $booking->customer_tz
		);

		$subject = $this->subject( $event, $when );
		$body    = $this->body( $event, $user->display_name, $when, $booking );

		wp_mail(
			$user->user_email,
			$subject,
			$body,
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
	}

	private function subject( string $event, string $when ): string {
		return match ( $event ) {
			'booking_created'     => sprintf( /* translators: %s: date and time. */ __( 'Appointment requested for %s', 'plumberslot' ), $when ),
			'booking_confirmed'   => sprintf( /* translators: %s: date and time. */ __( 'Appointment confirmed for %s', 'plumberslot' ), $when ),
			'booking_cancelled'   => sprintf( /* translators: %s: date and time. */ __( 'Appointment on %s is cancelled', 'plumberslot' ), $when ),
			'booking_rescheduled' => sprintf( /* translators: %s: date and time. */ __( 'Appointment moved to %s', 'plumberslot' ), $when ),
			'reminder_24h'        => sprintf( /* translators: %s: date and time. */ __( 'Tomorrow: your appointment at %s', 'plumberslot' ), $when ),
			'reminder_1h'         => __( 'Your appointment starts in an hour', 'plumberslot' ),
			default               => __( 'Appointment update', 'plumberslot' ),
		};
	}

	/**
	 * The join link is a signed, expiring URL rather than the raw meeting URL,
	 * so a forwarded email cannot let a stranger walk into a live video estimate.
	 */
	private function body( string $event, string $name, string $when, object $booking ): string {
		$join = $booking->meeting_ref
			? Crypto::signed_join_url( (int) $booking->id, (string) $booking->meeting_token )
			: '';

		$lines   = $this->header_lines();
		$lines[] = sprintf( '<p>%s,</p>', esc_html( $name ) );
		$lines[] = sprintf( '<p>%s</p>', esc_html( $this->sentence( $event, $when ) ) );

		$expect = $this->expectation( $event );

		if ( '' !== $expect ) {
			$lines[] = sprintf( '<p style="color:#555555;">%s</p>', esc_html( $expect ) );
		}

		$address_line1 = trim( (string) ( $booking->address_line1 ?? '' ) );

		if ( '' !== $address_line1 ) {
			$lines[] = sprintf(
				'<p><strong>%s</strong><br>%s</p>',
				esc_html__( 'Service address:', 'plumberslot' ),
				implode( '<br>', $this->format_address_lines( $booking ) )
			);
		}

		if ( '' !== $join ) {
			$lines[] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $join ),
				esc_html__( 'Join your video estimate', 'plumberslot' )
			);
		}

		array_push( $lines, ...$this->footer_lines() );

		/**
		 * Filter the rendered email body.
		 *
		 * @param string $html    Message body.
		 * @param string $event   Event key.
		 * @param object $booking Booking row.
		 */
		return apply_filters( 'plumberslot_email_body', implode( "\n", $lines ), $event, $booking );
	}

	/**
	 * The site's Custom Logo (Customizer "Site Identity") and a business-name
	 * header line, when one is set -- reusing WordPress's own logo upload
	 * rather than building new upload infrastructure just for email.
	 *
	 * @return list<string>
	 */
	private function header_lines(): array {
		$lines = array();

		if ( has_custom_logo() ) {
			$logo_url = wp_get_attachment_image_url( (int) get_theme_mod( 'custom_logo' ), 'medium' );

			if ( $logo_url ) {
				$lines[] = sprintf(
					'<p><img src="%s" alt="%s" style="max-width:160px;height:auto;"></p>',
					esc_url( $logo_url ),
					esc_attr( $this->business_name() )
				);
			}
		}

		$lines[] = sprintf(
			'<p style="font-weight:600;margin:0 0 12px;">%s</p>',
			esc_html( $this->business_name() )
		);

		return $lines;
	}

	private function business_name(): string {
		$name = Settings::string( 'business_name', '' );

		return '' !== $name ? $name : get_bloginfo( 'name' );
	}

	/**
	 * A short, generic line on what happens next, tailored to the event --
	 * kept to one sentence per event, matching sentence()'s own weight.
	 */
	private function expectation( string $event ): string {
		return match ( $event ) {
			'booking_created'     => __( "We'll confirm this shortly.", 'plumberslot' ),
			'booking_confirmed'   => __( "Your technician will arrive at the scheduled time. You'll get a reminder beforehand.", 'plumberslot' ),
			'booking_rescheduled' => __( 'Your technician will arrive at the new time above.', 'plumberslot' ),
			'reminder_24h',
			'reminder_1h'         => __( 'Your technician will arrive at the scheduled time.', 'plumberslot' ),
			default               => '',
		};
	}

	/**
	 * Business hours, when set -- omitted entirely rather than showing a
	 * blank "Hours:" label.
	 *
	 * @return list<string>
	 */
	private function footer_lines(): array {
		$hours = trim( Settings::string( 'business_hours', '' ) );

		if ( '' === $hours ) {
			return array();
		}

		return array(
			'<hr style="border:none;border-top:1px solid #e2e2e2;margin:20px 0;">',
			sprintf(
				'<p style="color:#888888;font-size:12px;">%s %s</p>',
				esc_html__( 'Hours:', 'plumberslot' ),
				esc_html( $hours )
			),
		);
	}

	private function sentence( string $event, string $when ): string {
		return match ( $event ) {
			'booking_confirmed'   => sprintf( /* translators: %s: date and time. */ __( 'Your appointment is confirmed for %s.', 'plumberslot' ), $when ),
			'booking_cancelled'   => sprintf( /* translators: %s: date and time. */ __( 'The appointment on %s has been cancelled. Nothing further is needed from you.', 'plumberslot' ), $when ),
			'booking_rescheduled' => sprintf( /* translators: %s: date and time. */ __( 'The appointment has moved to %s.', 'plumberslot' ), $when ),
			'reminder_24h'        => sprintf( /* translators: %s: date and time. */ __( 'A reminder that your appointment is tomorrow at %s.', 'plumberslot' ), $when ),
			'reminder_1h'         => __( 'Your appointment starts in an hour.', 'plumberslot' ),
			default               => sprintf( /* translators: %s: date and time. */ __( 'Your appointment is booked for %s.', 'plumberslot' ), $when ),
		};
	}

	/**
	 * Escaped, human-readable address lines: a street line and a city/state/zip
	 * line, mirroring the formatting the booking widget's confirm step uses.
	 *
	 * @return list<string>
	 */
	private function format_address_lines( object $booking ): array {
		$line1 = esc_html( (string) ( $booking->address_line1 ?? '' ) );
		$line2 = esc_html( (string) ( $booking->address_line2 ?? '' ) );
		$city  = esc_html( (string) ( $booking->address_city ?? '' ) );
		$state = esc_html( (string) ( $booking->address_state ?? '' ) );
		$zip   = esc_html( (string) ( $booking->address_zip ?? '' ) );

		$street    = implode( ', ', array_filter( array( $line1, $line2 ) ) );
		$state_zip = trim( $state . ' ' . $zip );
		$city_line = implode( ', ', array_filter( array( $city, $state_zip ) ) );

		return array_values( array_filter( array( $street, $city_line ) ) );
	}
}
