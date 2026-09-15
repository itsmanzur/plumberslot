<?php
/**
 * /plumberslot/v1/series — weekly courses.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\SeriesRepository;
use PlumberSlot\Database\Repository\SubjectRepository;
use PlumberSlot\Database\Repository\TutorRepository;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\RecurrenceService;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;
use PlumberSlot\Support\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SeriesController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly RecurrenceService $recurrence,
		private readonly SeriesRepository $series,
		private readonly BookingRepository $bookings,
		private readonly TutorRepository $tutors,
		private readonly SubjectRepository $subjects,
		private readonly CreditService $credits,
		private readonly PolicyService $policy
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/series',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'can_create' ),
				'args'                => array(
					'tutor_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'subject_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'student_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'start'      => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_iso8601' ),
					),
					'days'       => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'count'      => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
						'maximum'  => 104,
					),
					'use_credit' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'notes'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'timezone'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/series/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'cancel' ),
					'permission_callback' => array( $this, 'can_view' ),
					'args'                => array(
						'id'          => array( 'sanitize_callback' => 'absint' ),
						'future_only' => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
				),
			)
		);
	}

	public function can_create( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		return $this->verify_nonce( $request );
	}

	public function can_view( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->require_login();
		if ( is_wp_error( $auth ) ) {
			return $auth;
		}

		$nonce = $this->verify_nonce( $request );
		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$series = $this->series->find( (int) $request['id'] );
		if ( ! $series ) {
			return $this->guard->deny();
		}

		return $this->may_access_series( $series ) ? true : $this->guard->deny();
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor = $this->tutors->find( (int) $request['tutor_id'] );
		if ( ! $tutor || 'active' !== $tutor->status ) {
			return $this->guard->deny();
		}

		$days = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', (array) $request['days'] ),
					static fn ( int $d ): bool => $d >= 0 && $d <= 6
				)
			)
		);

		if ( array() === $days ) {
			return new WP_Error(
				'plumberslot_bad_days',
				__( 'Pick at least one weekday for the course.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$subject_id = $request['subject_id'] ? (int) $request['subject_id'] : null;
		$subject    = null;
		if ( null !== $subject_id ) {
			$subject = $this->subjects->find_for_tutor( $subject_id, (int) $tutor->id );
			if ( ! $subject || 'active' !== $subject->status ) {
				return $this->guard->deny();
			}
		}

		$actor      = get_current_user_id();
		$student_id = (int) ( $request['student_id'] ? $request['student_id'] : $actor );
		$parent_id  = $student_id === $actor ? null : $actor;

		if ( null !== $parent_id && ! $this->guard->is_guardian_of( $actor, $student_id ) ) {
			return $this->guard->deny();
		}

		if ( null !== $subject ) {
			$trial = $this->policy->can_use_trial( $student_id, $subject );
			if ( is_wp_error( $trial ) ) {
				return $trial;
			}
		}

		$start    = Time::from_iso( (string) $request['start'] );
		$duration = null !== $subject
			? (int) $subject->duration_min
			: Settings::int( 'default_lesson_minutes', 60 );

		$use_credit = (bool) $request['use_credit'];
		$credit_id  = null;
		if ( $use_credit ) {
			$credit = $this->credits->pick_usable( $parent_id ?? $actor, (int) $tutor->id, $subject_id );
			if ( ! $credit ) {
				return new WP_Error(
					'plumberslot_no_credits',
					__( 'There are no lessons left on this package.', 'plumberslot' ),
					array( 'status' => 409 )
				);
			}
			$credit_id = (int) $credit->id;
		}

		if ( $credit_id ) {
			$price = 0;
		} elseif ( null !== $subject && ! empty( $subject->is_trial ) ) {
			$price = 0;
		} elseif ( null !== $subject ) {
			$price = (int) $subject->price_minor;
		} else {
			$price = (int) $tutor->hourly_rate_minor;
		}

		$student_tz = (string) ( $request['timezone'] ? $request['timezone'] : $tutor->timezone );
		$currency   = (string) $tutor->currency;

		$result = $this->recurrence->create_series(
			array(
				'tutor_id'       => (int) $tutor->id,
				'student_id'     => $student_id,
				'parent_id'      => $parent_id,
				'subject_id'     => $subject_id,
				'start_utc'      => $start,
				'duration_min'   => $duration,
				'tutor_tz'       => (string) $tutor->timezone,
				'student_tz'     => $student_tz,
				'price_minor'    => $price,
				'currency'       => $currency,
				'credit_id'      => $credit_id,
				'consume_credit' => null !== $credit_id,
				'notes'          => (string) ( $request['notes'] ?? '' ),
			),
			$days,
			(int) $request['count']
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$series = $this->series->find( (int) $result['series_id'] );

		return $this->ok(
			array(
				'series_id'   => (int) $result['series_id'],
				'total_count' => $series ? (int) $series->total_count : (int) $request['count'],
				'booked'      => $result['booked'],
				'skipped'     => $result['skipped'],
				'label'       => sprintf(
					/* translators: 1: current or booked lesson count, 2: total or requested lesson count */
					__( 'Weekly %1$d/%2$d', 'plumberslot' ),
					count( $result['booked'] ),
					(int) $request['count']
				),
			),
			201
		);
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$series = $this->series->find( (int) $request['id'] );
		if ( ! $series ) {
			return $this->guard->deny();
		}

		$lessons = $this->bookings->find_for_series( (int) $series->id );
		$active  = array_values(
			array_filter(
				$lessons,
				static fn ( object $b ): bool => ! in_array( (string) $b->status, array( 'cancelled', 'refunded', 'moved', 'payment_expired' ), true )
			)
		);

		return $this->ok(
			array(
				'id'          => (int) $series->id,
				'tutor_id'    => (int) $series->tutor_id,
				'student_id'  => (int) $series->student_id,
				'rrule'       => (string) $series->rrule,
				'total_count' => (int) $series->total_count,
				'active'      => count( $active ),
				'label'       => sprintf(
					/* translators: 1: current or booked lesson count, 2: total or requested lesson count */
					__( 'Weekly %1$d/%2$d', 'plumberslot' ),
					count( $active ),
					(int) $series->total_count
				),
				'lessons'     => array_map(
					static function ( object $b ): array {
						return array(
							'id'           => (int) $b->id,
							'series_index' => (int) $b->series_index,
							'start_utc'    => (string) $b->start_utc,
							'status'       => (string) $b->status,
						);
					},
					$lessons
				),
			)
		);
	}

	public function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$series = $this->series->find( (int) $request['id'] );
		if ( ! $series ) {
			return $this->guard->deny();
		}

		$future_only = ! isset( $request['future_only'] ) || (bool) $request['future_only'];
		$cancelled   = $this->recurrence->cancel_series( (int) $series->id, $future_only );

		return $this->ok(
			array(
				'series_id'   => (int) $series->id,
				'cancelled'   => $cancelled,
				'future_only' => $future_only,
			)
		);
	}

	private function may_access_series( object $series ): bool {
		if ( $this->guard->is_site_manager() ) {
			return true;
		}

		$user_id = get_current_user_id();
		if ( (int) $series->student_id === $user_id ) {
			return true;
		}

		if ( $this->guard->is_guardian_of( $user_id, (int) $series->student_id ) ) {
			return true;
		}

		return $this->guard->owns_tutor( (int) $series->tutor_id );
	}
}
