<?php
/**
 * /tutorslot/v1/bookings
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Rest;

use TutorSlot\Database\Repository\BookingRepository;
use TutorSlot\Database\Repository\LockRepository;
use TutorSlot\Database\Repository\SeriesRepository;
use TutorSlot\Database\Repository\SubjectRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Domain\BookingService;
use TutorSlot\Domain\CreditService;
use TutorSlot\Domain\PolicyService;
use TutorSlot\Domain\SlotEngine;
use TutorSlot\Support\Capabilities;
use TutorSlot\Support\Crypto;
use TutorSlot\Support\AuditLog;
use TutorSlot\Support\RateLimiter;
use TutorSlot\Support\Settings;
use TutorSlot\Support\Time;
use TutorSlot\Support\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class BookingsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly BookingService $bookings,
		private readonly BookingRepository $repo,
		private readonly LockRepository $locks,
		private readonly TutorRepository $tutors,
		private readonly SubjectRepository $subjects,
		private readonly CreditService $credits,
		private readonly SlotEngine $slots,
		private readonly PolicyService $policy,
		private readonly SeriesRepository $series = new SeriesRepository()
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/bookings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'index' ),
					'permission_callback' => array( $this, 'can_list' ),
					'args'                => array(
						'scope'    => array(
							'type'    => 'string',
							'enum'    => array( 'mine', 'family', 'teaching' ),
							'default' => 'mine',
						),
						'from'     => array(
							'type'              => 'string',
							'validate_callback' => array( Validate::class, 'is_iso8601' ),
						),
						'to'       => array(
							'type'              => 'string',
							'validate_callback' => array( Validate::class, 'is_iso8601' ),
						),
						'status'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'page'     => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'search'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'tutor_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'tab'      => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'create' ),
					'permission_callback' => array( $this, 'can_create' ),
					'args'                => $this->create_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/export',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'export_csv' ),
				'permission_callback' => array( $this, 'can_list' ),
				'args'                => array(
					'scope' => array(
						'type'    => 'string',
						'enum'    => array( 'mine', 'family', 'teaching' ),
						'default' => 'teaching',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'show' ),
					'permission_callback' => array( $this, 'can_touch' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'destroy' ),
					'permission_callback' => array( $this, 'can_touch' ),
					'args'                => array( 'id' => array( 'sanitize_callback' => 'absint' ) ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reschedule' ),
				'permission_callback' => array( $this, 'can_reschedule_booking' ),
				'args'                => array(
					'id'    => array( 'sanitize_callback' => 'absint' ),
					'start' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_iso8601' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/hold',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'hold' ),
					'permission_callback' => array( $this, 'can_create' ),
					'args'                => array(
						'tutor_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'start'    => array(
							'required'          => true,
							'type'              => 'string',
							'validate_callback' => array( Validate::class, 'is_iso8601' ),
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( $this, 'release_hold' ),
					'permission_callback' => array( $this, 'can_release_hold' ),
					'args'                => array(
						'token' => array(
							'required'          => true,
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/attendance',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'attendance' ),
				'permission_callback' => array( $this, 'can_teach' ),
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'status' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'completed', 'no_show' ),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/bookings/(?P<id>\d+)/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_notes' ),
				'permission_callback' => array( $this, 'can_teach' ),
				'args'                => array(
					'id'    => array( 'sanitize_callback' => 'absint' ),
					'notes' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Accept only identity, schedule and notes from the client.
	 *
	 * Price, duration and currency are resolved server-side from tutor/subject
	 * records and must never appear in this schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function create_args(): array {
		return array(
			'tutor_id'   => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'subject_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'start'      => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => array( Validate::class, 'is_iso8601' ),
			),
			'student_id' => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'timezone'   => array(
				'type'              => 'string',
				'validate_callback' => array( Validate::class, 'is_timezone' ),
			),
			'lock_token' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'notes'      => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'use_credit' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/* ------------------------------------------------------------------
	 * Permissions
	 * ------------------------------------------------------------------ */

	public function can_list(): bool|WP_Error {
		return $this->require_login();
	}

	public function can_create( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! current_user_can( Capabilities::BOOK ) ) {
			return $this->guard->deny();
		}

		if ( ! RateLimiter::allow( 'create_booking', 10 ) ) {
			return new WP_Error(
				'tutorslot_too_many',
				__( 'Too many booking attempts. Wait a minute and try again.', 'tutorslot' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	public function can_release_hold( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		return current_user_can( Capabilities::BOOK ) ? true : $this->guard->deny();
	}

	/**
	 * Ownership check, run before the callback ever sees the id.
	 */
	public function can_touch( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		$booking = $this->repo->find( (int) $request['id'] );

		if ( ! $booking || ! $this->guard->may_touch_booking( $booking ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function can_reschedule_booking( WP_REST_Request $request ): bool|WP_Error {
		$can_touch = $this->can_touch( $request );

		if ( true !== $can_touch ) {
			return $can_touch;
		}

		$booking = $this->repo->find( (int) $request['id'] );

		if ( ! $booking ) {
			return $this->guard->deny();
		}

		if ( $this->guard->is_site_manager() || $this->guard->owns_tutor( (int) $booking->tutor_id ) ) {
			return true;
		}

		if ( Settings::bool( 'allow_student_reschedule', false ) ) {
			return true;
		}

		return new WP_Error(
			'tutorslot_reschedule_disabled',
			__( 'Online rescheduling is disabled. Contact the tutor to move this lesson.', 'tutorslot' ),
			array( 'status' => 403 )
		);
	}

	/* ------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------ */

	public function index( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$scope   = (string) $request['scope'];
		$filters = array(
			'from_utc' => $request['from'] ? Time::sql( Time::from_iso( (string) $request['from'] ) ) : null,
			'to_utc'   => $request['to'] ? Time::sql( Time::from_iso( (string) $request['to'] ) ) : null,
			'status'   => $request['status'] ? (string) $request['status'] : null,
			'page'     => (int) $request['page'],
			'per_page' => (int) $request['per_page'],
		);

		$tab = (string) ( $request['tab'] ?? '' );
		$now = gmdate( 'Y-m-d H:i:s' );

		if ( 'upcoming' === $tab ) {
			$filters['from_utc'] = $filters['from_utc'] ? $filters['from_utc'] : $now;
			if ( empty( $filters['status'] ) ) {
				$filters['status'] = null;
			}
		} elseif ( 'past' === $tab ) {
			$filters['to_utc'] = $filters['to_utc'] ? $filters['to_utc'] : $now;
		} elseif ( 'cancelled' === $tab ) {
			$filters['status'] = 'cancelled';
		} elseif ( 'needs' === $tab ) {
			$filters['status']   = 'pending';
			$filters['from_utc'] = $filters['from_utc'] ? $filters['from_utc'] : $now;
		}

		$result = match ( $scope ) {
			'family'   => $this->repo->find_for_family( $user_id, $filters ),
			'teaching' => $this->teaching_bookings( $user_id, $filters, (int) ( $request['tutor_id'] ?? 0 ) ),
			default    => $this->repo->find_for_student( $user_id, $filters ),
		};

		$search   = strtolower( trim( (string) ( $request['search'] ?? '' ) ) );
		$bookings = array();

		foreach ( $result['items'] as $row ) {
			$presented = $this->present_booking( $row );

			if ( '' !== $search ) {
				$hay = strtolower(
					$presented['student'] . ' ' . $presented['subject'] . ' ' . $presented['status']
				);

				if ( ! str_contains( $hay, $search ) ) {
					continue;
				}
			}

			$bookings[] = $presented;
		}

		return $this->ok(
			array(
				'bookings' => $bookings,
				'total'    => '' !== $search ? count( $bookings ) : $result['total'],
				'page'     => $result['page'],
				'per_page' => $result['per_page'],
			)
		);
	}

	public function export_csv( WP_REST_Request $request ): WP_REST_Response {
		$request->set_param( 'per_page', 100 );
		$request->set_param( 'page', 1 );
		$request->set_param( 'scope', (string) ( $request['scope'] ? $request['scope'] : 'teaching' ) );

		$list  = $this->index( $request );
		$data  = $list->get_data();
		$lines = array( 'id,student,subject,start_utc,status,price_minor,currency,series_id,payment_ref' );

		foreach ( (array) ( $data['bookings'] ?? array() ) as $row ) {
			$lines[] = implode(
				',',
				array(
					(int) $row['id'],
					$this->csv_escape( (string) $row['student'] ),
					$this->csv_escape( (string) $row['subject'] ),
					$this->csv_escape( (string) $row['start_utc'] ),
					$this->csv_escape( (string) $row['status'] ),
					(int) $row['price_minor'],
					$this->csv_escape( (string) $row['currency'] ),
					(int) ( $row['series_id'] ?? 0 ),
					$this->csv_escape( (string) ( $row['payment_ref'] ?? '' ) ),
				)
			);
		}

		$response = new WP_REST_Response( implode( "\n", $lines ) );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="tutorslot-bookings.csv"' );
		AuditLog::record(
			'bookings.exported',
			'export',
			get_current_user_id(),
			array(
				'count' => max( 0, count( $lines ) - 1 ),
				'scope' => (string) $request['scope'],
			)
		);

		return $response;
	}

	/**
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	private function teaching_bookings( int $user_id, array $filters, int $requested_tutor = 0 ): array {
		$tutor_id = $requested_tutor > 0 && $this->guard->is_site_manager()
			? $requested_tutor
			: $this->tutors->tutor_id_for_user( $user_id );

		if ( $tutor_id <= 0 || ! $this->guard->owns_tutor( $tutor_id ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => max( 1, (int) ( $filters['page'] ?? 1 ) ),
				'per_page' => min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) ),
			);
		}

		return $this->repo->find_for_tutor( $tutor_id, $filters );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present_booking( object $row ): array {
		$booking_id = (int) $row->id;
		$student    = get_userdata( (int) $row->student_id );
		$subject    = null;

		if ( ! empty( $row->subject_id ) ) {
			$subject = $this->subjects->find( (int) $row->subject_id );
		}

		$payment = 'unpaid';
		if ( ! empty( $row->credit_id ) ) {
			$payment = 'credit';
		} elseif ( ! empty( $row->payment_ref ) || 0 === (int) $row->price_minor ) {
			$payment = ! empty( $row->payment_ref ) ? 'paid' : ( 0 === (int) $row->price_minor ? 'free' : 'unpaid' );
		}

		$series_total = null;
		$series_label = null;
		if ( ! empty( $row->series_id ) ) {
			$series       = $this->series->find( (int) $row->series_id );
			$series_total = $series ? (int) $series->total_count : null;
			if ( $series_total ) {
				$series_label = sprintf(
					/* translators: 1: current or booked lesson count, 2: total or requested lesson count */
					__( 'Weekly %1$d/%2$d', 'tutorslot' ),
					(int) ( isset( $row->series_index ) && $row->series_index ? $row->series_index : 1 ),
					$series_total
				);
			}
		}

		$tutor = $this->tutors->find( (int) $row->tutor_id );

		$start_ts = strtotime( (string) $row->start_utc . ' UTC' );
		$end_ts   = strtotime( (string) $row->end_utc . ' UTC' );
		$duration = ( $start_ts && $end_ts && $end_ts > $start_ts )
			? (int) round( ( $end_ts - $start_ts ) / MINUTE_IN_SECONDS )
			: Settings::int( 'default_lesson_minutes', 60 );

		$tz   = $tutor ? (string) $tutor->timezone : 'UTC';
		$when = (string) $row->start_utc;
		try {
			$local = ( new \DateTimeImmutable( (string) $row->start_utc, new \DateTimeZone( 'UTC' ) ) )
				->setTimezone( new \DateTimeZone( $tz ) );
			$when  = $local->format( 'D, j M Y · H:i' ) . ' ' . $tz;
		} catch ( \Exception ) {
			$when = (string) $row->start_utc;
		}

		$meeting_ready = ! empty( $row->meeting_ref ) && ! empty( $row->meeting_token );
		$join_url      = '';
		if ( $meeting_ready ) {
			try {
				$join_url = Crypto::signed_join_url( $booking_id, (string) $row->meeting_token );
			} catch ( \Throwable ) {
				$join_url = '';
			}
		}

		return array(
			'id'             => $booking_id,
			'tutor_id'       => (int) $row->tutor_id,
			'tutor'          => $tutor ? (string) $tutor->display_name : '',
			'tutor_timezone' => $tz,
			'student_id'     => (int) $row->student_id,
			'student'        => $student ? $student->display_name : __( 'Student', 'tutorslot' ),
			'subject_id'     => $row->subject_id ? (int) $row->subject_id : null,
			'subject'        => $subject ? (string) $subject->name : '—',
			'start_utc'      => (string) $row->start_utc,
			'end_utc'        => (string) $row->end_utc,
			'when'           => $when,
			'duration_min'   => $duration,
			'status'         => (string) $row->status,
			'series_id'      => $row->series_id ? (int) $row->series_id : null,
			'series_index'   => $row->series_index ? (int) $row->series_index : null,
			'series_total'   => $series_total,
			'series_label'   => $series_label,
			'price_minor'    => (int) $row->price_minor,
			'currency'       => (string) $row->currency,
			'payment'        => $payment,
			'payment_ref'    => $row->payment_ref,
			'notes'          => $row->notes,
			'meeting_ready'  => $meeting_ready && '' !== $join_url,
			'join_url'       => $join_url,
		);
	}

	public function can_teach( WP_REST_Request $request ): bool|WP_Error {
		$auth = $this->can_touch( $request );
		if ( true !== $auth ) {
			return $auth;
		}

		$booking = $this->repo->find( (int) $request['id'] );
		if ( ! $booking ) {
			return $this->guard->deny();
		}

		if ( $this->guard->is_site_manager() || $this->guard->owns_tutor( (int) $booking->tutor_id ) ) {
			return true;
		}

		return $this->guard->deny();
	}

	public function attendance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->bookings->mark_attendance( (int) $request['id'], (string) $request['status'] );

		return is_wp_error( $result ) ? $result : $this->ok( array( 'status' => (string) $request['status'] ) );
	}

	public function save_notes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->bookings->update_notes( (int) $request['id'], (string) $request['notes'] );

		return is_wp_error( $result ) ? $result : $this->ok( array( 'notes' => (string) $request['notes'] ) );
	}

	private function csv_escape( string $value ): string {
		$value = str_replace( '"', '""', $value );

		return '"' . $value . '"';
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$booking = $this->repo->find( (int) $request['id'] );

		return $booking ? $this->ok( $this->present_booking( $booking ) ) : $this->guard->deny();
	}

	/**
	 * Reserve a slot for the length of a checkout.
	 */
	public function hold( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor_id = (int) $request['tutor_id'];
		$tutor    = $this->tutors->find( $tutor_id );

		if ( ! $tutor || 'active' !== $tutor->status ) {
			return $this->guard->deny();
		}

		$start    = Time::from_iso( (string) $request['start'] );
		$duration = Settings::int( 'default_lesson_minutes', 60 );

		if ( ! $this->repo->acquire_tutor_lock( $tutor_id ) ) {
			return $this->slot_taken();
		}

		try {
			if ( ! $this->slots->is_open( $tutor_id, $start, (string) $tutor->timezone, $duration ) ) {
				return $this->slot_taken();
			}

			$token = $this->locks->acquire(
				$tutor_id,
				get_current_user_id(),
				Time::sql( $start ),
				Settings::int( 'hold_window_minutes', 10 )
			);
		} finally {
			$this->repo->release_tutor_lock( $tutor_id );
		}

		if ( null === $token ) {
			return $this->slot_taken();
		}

		return $this->ok(
			array(
				'token'      => $token,
				'expires_in' => Settings::int( 'hold_window_minutes', 10 ) * MINUTE_IN_SECONDS,
			)
		);
	}

	public function release_hold( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$released = $this->locks->release_owned(
			(string) $request['token'],
			get_current_user_id()
		);

		if ( ! $released ) {
			return new WP_Error(
				'tutorslot_hold_not_found',
				__( 'That hold is no longer available.', 'tutorslot' ),
				array( 'status' => 404 )
			);
		}

		return $this->ok( array( 'released' => true ) );
	}

	private function slot_taken(): WP_Error {
		return new WP_Error(
			'tutorslot_slot_taken',
			__( 'Someone is booking that time right now. Pick another slot.', 'tutorslot' ),
			array( 'status' => 409 )
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor = $this->tutors->find( (int) $request['tutor_id'] );

		if ( ! $tutor || 'active' !== $tutor->status ) {
			return $this->guard->deny();
		}

		$subject_id = $request['subject_id'] ? (int) $request['subject_id'] : null;
		$subject    = null;

		if ( null !== $subject_id ) {
			$subject = $this->subjects->find_for_tutor( $subject_id, (int) $tutor->id );

			if ( ! $subject || 'active' !== $subject->status ) {
				return $this->guard->deny();
			}
		}

		$actor = get_current_user_id();

		// The booker may be a parent acting for a child. Never take that on trust.
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

		$credit_id = null;

		if ( $request['use_credit'] ) {
			$credit = $this->credits->pick_usable( $parent_id ?? $actor, (int) $tutor->id, $subject_id );

			if ( ! $credit ) {
				return new WP_Error(
					'tutorslot_no_credits',
					__( 'There are no lessons left on this package.', 'tutorslot' ),
					array( 'status' => 409 )
				);
			}

			$credit_id = (int) $credit->id;
		}

		$duration = null !== $subject
			? (int) $subject->duration_min
			: Settings::int( 'default_lesson_minutes', 60 );

		if ( $credit_id ) {
			$price = 0;
		} elseif ( null !== $subject && ! empty( $subject->is_trial ) ) {
			$price = 0;
		} elseif ( null !== $subject ) {
			$price = (int) $subject->price_minor;
		} else {
			$price = (int) $tutor->hourly_rate_minor;
		}

		// Currency lives on the tutor; subjects inherit it and clients never set it.
		$currency = (string) $tutor->currency;

		$result = $this->bookings->create(
			array(
				'tutor_id'       => (int) $tutor->id,
				'student_id'     => $student_id,
				'parent_id'      => $parent_id,
				'subject_id'     => $subject_id,
				'start_utc'      => Time::from_iso( (string) $request['start'] ),
				'duration_min'   => $duration,
				'tutor_tz'       => (string) $tutor->timezone,
				'student_tz'     => (string) ( $request['timezone'] ?? $tutor->timezone ),
				'price_minor'    => $price,
				'currency'       => $currency,
				'credit_id'      => $credit_id,
				'consume_credit' => null !== $credit_id,
				'lock_token'     => $request['lock_token'] ? (string) $request['lock_token'] : null,
				'notes'          => $request['notes'] ? (string) $request['notes'] : null,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$booking  = $this->repo->find( (int) $result );
		$student  = get_userdata( $student_id );
		$window   = Settings::int( 'reschedule_window_minutes', 720 );
		$start    = Time::from_iso( (string) $request['start'] );
		$deadline = $start->getTimestamp() - ( $window * MINUTE_IN_SECONDS );
		$provider = (string) get_user_meta( (int) $tutor->user_id, 'tutorslot_meeting_provider', true );

		return $this->ok(
			array(
				'id'                  => (int) $result,
				'status'              => $booking ? (string) $booking->status : 'pending',
				'start_utc'           => $booking ? (string) $booking->start_utc : Time::sql( $start ),
				'end_utc'             => $booking ? (string) $booking->end_utc : null,
				'duration_min'        => $duration,
				'price_minor'         => $price,
				'currency'            => $currency,
				'payment'             => $credit_id ? 'credit' : ( 0 === $price ? 'free' : 'unpaid' ),
				'subject'             => $subject ? (string) $subject->name : '',
				'tutor'               => (string) $tutor->display_name,
				'student'             => $student ? $student->display_name : '',
				'meeting_provider'    => $provider ? $provider : 'Google Meet',
				'reschedule_deadline' => gmdate( 'c', max( time(), $deadline ) ),
				'dashboard_url'       => home_url( '/my-account/' ),
				// Join links are never returned raw on create — they are issued
				// behind a signed short-lived URL after confirmation.
				'meeting_ready'       => false,
			),
			201
		);
	}

	public function reschedule( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->bookings->reschedule(
			(int) $request['id'],
			Time::from_iso( (string) $request['start'] )
		);

		return is_wp_error( $result ) ? $result : $this->ok( array( 'rescheduled' => true ) );
	}

	public function destroy( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->bookings->cancel( (int) $request['id'] );

		return is_wp_error( $result ) ? $result : $this->ok( array( 'cancelled' => true ) );
	}
}
