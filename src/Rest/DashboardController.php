<?php
/**
 * /tutorslot/v1/dashboard — tutor home aggregates.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Rest;

use TutorSlot\Database\Repository\AvailabilityRepository;
use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\CreditRepository;
use TutorSlot\Database\Repository\SubjectRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Frontend\BookingPage;
use TutorSlot\Support\Cache;
use TutorSlot\Support\Crypto;
use TutorSlot\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class DashboardController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly TutorRepository $tutors,
		private readonly BookingRepository $bookings,
		private readonly CreditRepository $credits,
		private readonly AvailabilityRepository $availability,
		private readonly SubjectRepository $subjects
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/dashboard',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_view' ),
				'args'                => array(
					'tutor_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function can_view( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$tutor_id = $this->resolve_tutor_id( $request );

		if ( $tutor_id <= 0 ) {
			return $this->guard->deny();
		}

		return $this->guard->owns_tutor( $tutor_id ) ? true : $this->guard->deny();
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id = $this->resolve_tutor_id( $request );
		$tutor    = $this->tutors->find( $tutor_id );

		if ( ! $tutor ) {
			return $this->guard->deny();
		}

		$cached = Cache::dashboard( $tutor_id );
		if ( null !== $cached ) {
			$cached['greeting'] = $this->greeting();

			return $this->ok( $cached );
		}

		$tz         = (string) ( $tutor->timezone ? $tutor->timezone : wp_timezone_string() );
		$now        = new \DateTimeImmutable( 'now', new \DateTimeZone( $tz ) );
		$today      = $now->format( 'Y-m-d' );
		$week_start = $now->modify( 'monday this week' )->setTime( 0, 0 );
		$week_end   = $week_start->modify( '+7 days' );

		$week = $this->bookings->find_for_tutor(
			$tutor_id,
			array(
				'from_utc' => $week_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'to_utc'   => $week_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'per_page' => 100,
			)
		);

		$upcoming = $this->bookings->find_for_tutor(
			$tutor_id,
			array(
				'from_utc' => gmdate( 'Y-m-d H:i:s' ),
				'per_page' => 8,
			)
		);

		$pending = $this->bookings->find_for_tutor(
			$tutor_id,
			array(
				'status'   => 'pending',
				'from_utc' => gmdate( 'Y-m-d H:i:s' ),
				'per_page' => 10,
			)
		);

		$active_week = array_values(
			array_filter(
				$week['items'],
				static fn ( $row ) => ! in_array( (string) $row->status, array( 'cancelled', 'refunded', 'moved', 'payment_expired' ), true )
			)
		);

		$earnings = 0;
		foreach ( $active_week as $row ) {
			if ( in_array( (string) $row->status, array( 'confirmed', 'completed', 'paid' ), true )
				|| ! empty( $row->payment_ref ) ) {
				$earnings += (int) $row->price_minor;
			}
		}

		$open_blocks = count( $this->availability->rules_for( $tutor_id ) );
		$fill_rate   = $open_blocks > 0
			? (int) min( 100, round( ( count( $active_week ) / max( 1, $open_blocks * 2 ) ) * 100 ) )
			: 0;

		$today_items = array();
		$day_start   = $now->setTime( 0, 0 );
		$day_end     = $day_start->modify( '+1 day' );
		$day_span    = max( 1, $day_end->getTimestamp() - $day_start->getTimestamp() );

		foreach ( $active_week as $row ) {
			$start = new \DateTimeImmutable( (string) $row->start_utc, new \DateTimeZone( 'UTC' ) );
			$local = $start->setTimezone( new \DateTimeZone( $tz ) );

			if ( $local->format( 'Y-m-d' ) !== $today ) {
				continue;
			}

			$offset = $local->getTimestamp() - $day_start->getTimestamp();
			$end    = new \DateTimeImmutable( (string) $row->end_utc, new \DateTimeZone( 'UTC' ) );
			$width  = max( 4, (int) round( ( ( $end->getTimestamp() - $start->getTimestamp() ) / $day_span ) * 100 ) );

			$student       = get_userdata( (int) $row->student_id );
			$subject       = $row->subject_id ? $this->subjects->find( (int) $row->subject_id ) : null;
			$today_items[] = array(
				'id'       => (int) $row->id,
				'title'    => sprintf(
					'%1$s · %2$s',
					$student ? $student->display_name : __( 'Student', 'tutorslot' ),
					$subject ? $subject->name : __( 'Lesson', 'tutorslot' )
				),
				'time'     => $local->format( 'H:i' ),
				'startPct' => (int) round( ( $offset / $day_span ) * 100 ),
				'widthPct' => $width,
				'done'     => $local < $now,
			);
		}

		$now_pct = (int) round( ( ( $now->getTimestamp() - $day_start->getTimestamp() ) / $day_span ) * 100 );

		$next_up = array();
		foreach ( $upcoming['items'] as $row ) {
			if ( in_array( (string) $row->status, array( 'cancelled', 'refunded', 'moved', 'payment_expired' ), true ) ) {
				continue;
			}
			$booking_id = (int) $row->id;
			$student    = get_userdata( (int) $row->student_id );
			$parent     = $row->parent_id ? get_userdata( (int) $row->parent_id ) : null;
			$subject    = $row->subject_id ? $this->subjects->find( (int) $row->subject_id ) : null;
			$start      = new \DateTimeImmutable( (string) $row->start_utc, new \DateTimeZone( 'UTC' ) );
			$end        = new \DateTimeImmutable( (string) $row->end_utc, new \DateTimeZone( 'UTC' ) );
			$local      = $start->setTimezone( new \DateTimeZone( $tz ) );
			$local_end  = $end->setTimezone( new \DateTimeZone( $tz ) );
			$payment    = ! empty( $row->credit_id )
				? __( 'Credit used', 'tutorslot' )
				: ( ! empty( $row->payment_ref ) || 0 === (int) $row->price_minor
					? ( 0 === (int) $row->price_minor ? __( 'Free', 'tutorslot' ) : __( 'Paid', 'tutorslot' ) )
					: __( 'Due', 'tutorslot' ) );
			$next_up[]  = array(
				'id'            => $booking_id,
				'student'       => $student ? $student->display_name : __( 'Student', 'tutorslot' ),
				'initials'      => $this->initials( $student ? $student->display_name : __( 'Student', 'tutorslot' ) ),
				'context'       => $parent
					? sprintf( /* translators: %s: parent display name. */ __( 'Parent: %s', 'tutorslot' ), $parent->display_name )
					: __( 'Books their own lessons', 'tutorslot' ),
				'subject'       => $subject ? (string) $subject->name : __( 'Lesson', 'tutorslot' ),
				'when'          => $local->format( 'Y-m-d' ) === $today
					? $local->format( 'H:i' ) . ' – ' . $local_end->format( 'H:i' )
					: $local->format( 'D H:i' ),
				'payment'       => $payment,
				'payment_tone'  => __( 'Due', 'tutorslot' ) === $payment ? 'wait' : ( __( 'Credit used', 'tutorslot' ) === $payment ? 'idle' : 'ok' ),
				'meeting_ready' => ! empty( $row->meeting_ref ),
				'join_url'      => ( ! empty( $row->meeting_ref ) && ! empty( $row->meeting_token ) )
					? Crypto::signed_join_url( $booking_id, (string) $row->meeting_token )
					: '',
			);
			if ( count( $next_up ) >= 5 ) {
				break;
			}
		}

		$needs = array();
		if ( count( $pending['items'] ) > 0 ) {
			$needs[] = array(
				'id'    => 'pending',
				'label' => __( 'Bookings awaiting confirmation', 'tutorslot' ),
				'value' => count( $pending['items'] ),
				'href'  => 'bookings',
			);
		}

		foreach ( $pending['items'] as $row ) {
			$student = get_userdata( (int) $row->student_id );
			$needs[] = array(
				'id'         => (int) $row->id,
				'booking_id' => (int) $row->id,
				'label'      => sprintf(
					/* translators: %s: student name */
					__( 'Confirm booking for %s', 'tutorslot' ),
					$student ? $student->display_name : __( 'student', 'tutorslot' )
				),
				'start'      => (string) $row->start_utc,
				'value'      => '→',
				'href'       => 'bookings',
			);
		}

		$payload = array(
			'greeting'        => $this->greeting(),
			'date'            => $now->format( 'l, j F Y' ),
			'timezone'        => $tz,
			'timezone_label'  => $tz . ' · UTC' . $now->format( 'P' ),
			'now_label'       => $now->format( 'H:i' ),
			'now_pct'         => max( 0, min( 100, $now_pct ) ),
			'today'           => $today_items,
			'tiles'           => array(
				'weekly_sessions' => count( $active_week ),
				'earnings_minor'  => $earnings,
				'currency'        => (string) $tutor->currency,
				'fill_rate'       => $fill_rate,
				'credits_held'    => $this->credits->remaining_for_tutor( $tutor_id ),
			),
			'next_up'         => $next_up,
			'needs_attention' => $needs,
			'booking_url'     => $this->booking_url( $tutor ),
			'shortcode'       => sprintf( '[tutorslot tutor="%s"]', esc_attr( (string) $tutor->slug ) ),
			'defaults'        => array(
				'lesson_minutes'    => Settings::int( 'default_lesson_minutes', 60 ),
				'buffer_minutes'    => Settings::int( 'buffer_minutes', 0 ),
				'lead_time_minutes' => Settings::int( 'lead_time_minutes', 0 ),
			),
		);

		Cache::set_dashboard( $tutor_id, $payload );

		return $this->ok( $payload );
	}

	private function greeting(): string {
		$user = wp_get_current_user();

		return sprintf(
			/* translators: %s: first name */
			__( 'Good day, %s', 'tutorslot' ),
			$user->first_name ? $user->first_name : $user->display_name
		);
	}

	private function resolve_tutor_id( WP_REST_Request $request ): int {
		$requested = (int) $request['tutor_id'];

		if ( $requested > 0 ) {
			return $requested;
		}

		$owned = $this->tutors->tutor_id_for_user( get_current_user_id() );

		if ( $owned > 0 || ! $this->guard->is_site_manager() ) {
			return $owned;
		}

		$active = $this->tutors->all_active();

		return $active ? (int) $active[0]->id : 0;
	}

	private function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );

		if ( false === $parts ) {
			$parts = array();
		}
		$first = $parts[0] ?? '';
		$last  = count( $parts ) > 1 ? $parts[ count( $parts ) - 1 ] : '';

		return strtoupper( substr( $first, 0, 1 ) . substr( $last, 0, 1 ) );
	}

	private function booking_url( object $tutor ): string {
		return BookingPage::url_for_tutor( $tutor );
	}
}
