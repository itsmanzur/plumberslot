<?php
/**
 * Email delivery.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Notifications\Channel;

use TutorSlot\Support\Crypto;
use TutorSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class EmailChannel implements ChannelInterface {

	public function id(): string {
		return 'email';
	}

	public function is_enabled( string $event ): bool {
		return (bool) apply_filters( 'tutorslot_email_enabled', true, $event );
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
			(string) $booking->student_tz
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
			'booking_created'     => sprintf( /* translators: %s: date and time. */ __( 'Lesson requested for %s', 'tutorslot' ), $when ),
			'booking_confirmed'   => sprintf( /* translators: %s: date and time. */ __( 'Lesson confirmed for %s', 'tutorslot' ), $when ),
			'booking_cancelled'   => sprintf( /* translators: %s: date and time. */ __( 'Lesson on %s is cancelled', 'tutorslot' ), $when ),
			'booking_rescheduled' => sprintf( /* translators: %s: date and time. */ __( 'Lesson moved to %s', 'tutorslot' ), $when ),
			'reminder_24h'        => sprintf( /* translators: %s: date and time. */ __( 'Tomorrow: your lesson at %s', 'tutorslot' ), $when ),
			'reminder_1h'         => __( 'Your lesson starts in an hour', 'tutorslot' ),
			default               => __( 'Lesson update', 'tutorslot' ),
		};
	}

	/**
	 * The join link is a signed, expiring URL rather than the raw meeting URL,
	 * so a forwarded email cannot let a stranger walk into a live lesson.
	 */
	private function body( string $event, string $name, string $when, object $booking ): string {
		$join = $booking->meeting_ref
			? Crypto::signed_join_url( (int) $booking->id, (string) $booking->meeting_token )
			: '';

		$lines = array(
			sprintf( '<p>%s,</p>', esc_html( $name ) ),
			sprintf( '<p>%s</p>', esc_html( $this->sentence( $event, $when ) ) ),
		);

		if ( '' !== $join ) {
			$lines[] = sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( $join ),
				esc_html__( 'Open your lesson', 'tutorslot' )
			);
		}

		/**
		 * Filter the rendered email body.
		 *
		 * @param string $html    Message body.
		 * @param string $event   Event key.
		 * @param object $booking Booking row.
		 */
		return apply_filters( 'tutorslot_email_body', implode( "\n", $lines ), $event, $booking );
	}

	private function sentence( string $event, string $when ): string {
		return match ( $event ) {
			'booking_confirmed'   => sprintf( /* translators: %s: date and time. */ __( 'Your lesson is confirmed for %s.', 'tutorslot' ), $when ),
			'booking_cancelled'   => sprintf( /* translators: %s: date and time. */ __( 'The lesson on %s has been cancelled. Nothing further is needed from you.', 'tutorslot' ), $when ),
			'booking_rescheduled' => sprintf( /* translators: %s: date and time. */ __( 'The lesson has moved to %s.', 'tutorslot' ), $when ),
			'reminder_24h'        => sprintf( /* translators: %s: date and time. */ __( 'A reminder that your lesson is tomorrow at %s.', 'tutorslot' ), $when ),
			'reminder_1h'         => __( 'Your lesson starts in an hour.', 'tutorslot' ),
			default               => sprintf( /* translators: %s: date and time. */ __( 'Your lesson is booked for %s.', 'tutorslot' ), $when ),
		};
	}
}
