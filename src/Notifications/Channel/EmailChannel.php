<?php
/**
 * Email delivery.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Notifications\Channel;

use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Money;
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

	/**
	 * A Service Plan nearing its expiry date, with jobs still left on it.
	 *
	 * Deliberately not routed through send()/ChannelInterface: that contract
	 * is shaped around a booking row (start_utc, customer_tz, address,
	 * meeting_ref -- see subject()/body()/sentence()'s match statements), and
	 * a credit package has none of those. Forcing this through it would mean
	 * either faking a booking-shaped object just to satisfy the signature, or
	 * widening ChannelInterface for every other channel (SmsChannel included)
	 * for a single event type -- a new, minimal method here is the smaller
	 * change.
	 */
	public function send_credit_expiring( int $user_id, object $credit, int $appointments_left ): void {
		$user = get_userdata( $user_id );

		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$when = Time::for_human(
			Time::from_sql( (string) $credit->expires_at ),
			Time::is_valid_zone( wp_timezone_string() ) ? wp_timezone_string() : 'UTC',
			get_option( 'date_format' )
		);

		$subject = sprintf(
			/* translators: %s: expiry date. */
			__( 'Your Service Plan expires %s', 'plumberslot' ),
			$when
		);

		$lines   = $this->header_lines();
		$lines[] = sprintf( '<p>%s,</p>', esc_html( $user->display_name ) );
		$lines[] = sprintf(
			'<p>%s</p>',
			esc_html(
				sprintf(
					/* translators: 1: number of appointments left, 2: expiry date. */
					_n(
						'Your Service Plan expires %2$s. You have %1$d appointment left on it.',
						'Your Service Plan expires %2$s. You have %1$d appointments left on it.',
						$appointments_left,
						'plumberslot'
					),
					$appointments_left,
					$when
				)
			)
		);
		$lines[] = sprintf(
			'<p style="color:#555555;">%s</p>',
			esc_html__( "Book before it expires to use what's left, or renew to keep going.", 'plumberslot' )
		);

		array_push( $lines, ...$this->footer_lines() );

		/**
		 * Filter the rendered Service Plan expiry email body.
		 *
		 * @param string $html   Message body.
		 * @param object $credit Credit package row.
		 */
		$body = apply_filters( 'plumberslot_credit_expiry_email_body', implode( "\n", $lines ), $credit );

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
			'booking_on_the_way'  => __( 'Your technician is on the way', 'plumberslot' ),
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
	 *
	 * @param object{id:int,meeting_ref:?string,meeting_token:?string,address_line1:?string} $booking Booking row.
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

		$deposit_note = $this->deposit_note( $event, $booking );

		if ( '' !== $deposit_note ) {
			$lines[] = sprintf( '<p style="color:#555555;">%s</p>', esc_html( $deposit_note ) );
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

		if ( in_array( $event, array( 'booking_confirmed', 'booking_on_the_way' ), true ) && ! empty( $booking->meeting_token ) ) {
			$track_url = Crypto::track_url( (int) $booking->id, (string) $booking->meeting_token );
			$lines[]   = sprintf(
				'<p style="color:#555555;"><a href="%1$s">%2$s</a></p>',
				esc_url( $track_url ),
				esc_html__( 'Track your appointment', 'plumberslot' )
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
			'booking_on_the_way'  => __( "You'll get another update if anything changes.", 'plumberslot' ),
			'booking_rescheduled' => __( 'Your technician will arrive at the new time above.', 'plumberslot' ),
			'reminder_24h',
			'reminder_1h'         => __( 'Your technician will arrive at the scheduled time.', 'plumberslot' ),
			default               => '',
		};
	}

	/**
	 * When a booking was only partly paid online, a calm one-line reminder of
	 * what was already paid as a deposit and what is still due on arrival --
	 * shown only on the confirmation email, and only when there is a balance
	 * left, so a full-price booking's email is unchanged.
	 */
	private function deposit_note( string $event, object $booking ): string {
		if ( 'booking_confirmed' !== $event ) {
			return '';
		}

		$balance = (int) ( $booking->balance_minor ?? 0 );

		if ( $balance <= 0 ) {
			return '';
		}

		$currency = (string) ( $booking->currency ?? 'USD' );
		$deposit  = (int) ( $booking->deposit_minor ?? 0 );

		return sprintf(
			/* translators: 1: deposit amount already paid, 2: balance due on arrival. */
			__( 'A deposit of %1$s has been paid. %2$s is due on arrival.', 'plumberslot' ),
			Money::format( $deposit, $currency ),
			Money::format( $balance, $currency )
		);
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
			'booking_on_the_way'  => __( 'Your technician is on the way to your appointment.', 'plumberslot' ),
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
