<?php
/**
 * /plumberslot/v1/bookings
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\LockRepository;
use PlumberSlot\Database\Repository\SeriesRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Domain\BookingService;
use PlumberSlot\Domain\CreditService;
use PlumberSlot\Domain\PolicyService;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Media\BookingPhotos;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\RateLimiter;
use PlumberSlot\Support\ServiceArea;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;
use PlumberSlot\Support\Validate;
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
		private readonly TechnicianRepository $technicians,
		private readonly ServiceRepository $services,
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
						'scope'         => array(
							'type'    => 'string',
							'enum'    => array( 'mine', 'teaching' ),
							'default' => 'mine',
						),
						'from'          => array(
							'type'              => 'string',
							'validate_callback' => array( Validate::class, 'is_iso8601' ),
						),
						'to'            => array(
							'type'              => 'string',
							'validate_callback' => array( Validate::class, 'is_iso8601' ),
						),
						'status'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'page'          => array(
							'type'    => 'integer',
							'default' => 1,
							'minimum' => 1,
						),
						'per_page'      => array(
							'type'    => 'integer',
							'default' => 20,
							'minimum' => 1,
							'maximum' => 100,
						),
						'search'        => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'technician_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'tab'           => array(
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
						'enum'    => array( 'mine', 'teaching' ),
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
						'technician_id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'start'         => array(
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
			'/bookings/(?P<id>\d+)/job-status',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'job_status' ),
				'permission_callback' => array( $this, 'can_teach' ),
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'status' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'scheduled', 'on_the_way', 'in_progress' ),
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
	 * Price, duration and currency are resolved server-side from technician/service
	 * records and must never appear in this schema.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function create_args(): array {
		return array(
			'technician_id' => array(
				'required'          => true,
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'service_id'    => array(
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			),
			'start'         => array(
				'required'          => true,
				'type'              => 'string',
				'validate_callback' => array( Validate::class, 'is_iso8601' ),
			),
			'timezone'      => array(
				'type'              => 'string',
				'validate_callback' => array( Validate::class, 'is_timezone' ),
			),
			'lock_token'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'notes'         => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'use_credit'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'address_line1' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'address_line2' => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'address_city'  => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'address_state' => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'address_zip'   => array(
				'required'          => true,
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'mobile'        => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'photo_ids'     => array(
				'type'    => 'array',
				'items'   => array( 'type' => 'integer' ),
				'default' => array(),
			),
			'is_emergency'  => array(
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
				'plumberslot_too_many',
				__( 'Too many booking attempts. Wait a minute and try again.', 'plumberslot' ),
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

		if ( $this->guard->is_site_manager() || $this->guard->owns_technician( (int) $booking->technician_id ) ) {
			return true;
		}

		if ( Settings::bool( 'allow_customer_reschedule', false ) ) {
			return true;
		}

		return new WP_Error(
			'plumberslot_reschedule_disabled',
			__( 'Online rescheduling is disabled. Contact the technician to move this appointment.', 'plumberslot' ),
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
			'teaching' => $this->teaching_bookings( $user_id, $filters, (int) ( $request['technician_id'] ?? 0 ) ),
			default    => $this->repo->find_for_customer( $user_id, $filters ),
		};

		$search   = strtolower( trim( (string) ( $request['search'] ?? '' ) ) );
		$bookings = array();

		foreach ( $result['items'] as $row ) {
			$presented = $this->present_booking( $row );

			if ( '' !== $search ) {
				$hay = strtolower(
					$presented['customer'] . ' ' . $presented['service'] . ' ' . $presented['status']
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
		$lines = array( 'id,customer,service,start_utc,status,price_minor,currency,series_id,payment_ref,address_line1,address_line2,address_city,address_state,address_zip' );

		foreach ( (array) ( $data['bookings'] ?? array() ) as $row ) {
			$lines[] = implode(
				',',
				array(
					(int) $row['id'],
					$this->csv_escape( (string) $row['customer'] ),
					$this->csv_escape( (string) $row['service'] ),
					$this->csv_escape( (string) $row['start_utc'] ),
					$this->csv_escape( (string) $row['status'] ),
					(int) $row['price_minor'],
					$this->csv_escape( (string) $row['currency'] ),
					(int) ( $row['series_id'] ?? 0 ),
					$this->csv_escape( (string) ( $row['payment_ref'] ?? '' ) ),
					$this->csv_escape( (string) ( $row['address_line1'] ?? '' ) ),
					$this->csv_escape( (string) ( $row['address_line2'] ?? '' ) ),
					$this->csv_escape( (string) ( $row['address_city'] ?? '' ) ),
					$this->csv_escape( (string) ( $row['address_state'] ?? '' ) ),
					$this->csv_escape( (string) ( $row['address_zip'] ?? '' ) ),
				)
			);
		}

		$response = new WP_REST_Response( implode( "\n", $lines ) );
		$response->header( 'Content-Type', 'text/csv; charset=utf-8' );
		$response->header( 'Content-Disposition', 'attachment; filename="plumberslot-bookings.csv"' );
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
	private function teaching_bookings( int $user_id, array $filters, int $requested_technician = 0 ): array {
		$technician_id = $requested_technician > 0 && $this->guard->is_site_manager()
			? $requested_technician
			: $this->technicians->technician_id_for_user( $user_id );

		if ( $technician_id <= 0 || ! $this->guard->owns_technician( $technician_id ) ) {
			return array(
				'items'    => array(),
				'total'    => 0,
				'page'     => max( 1, (int) ( $filters['page'] ?? 1 ) ),
				'per_page' => min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) ),
			);
		}

		return $this->repo->find_for_technician( $technician_id, $filters );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function present_booking( object $row ): array {
		$booking_id = (int) $row->id;
		$customer   = get_userdata( (int) $row->customer_id );
		$service    = null;

		if ( ! empty( $row->service_id ) ) {
			$service = $this->services->find( (int) $row->service_id );
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
					/* translators: 1: current or booked appointment count, 2: total or requested appointment count */
					__( 'Visit %1$d/%2$d', 'plumberslot' ),
					(int) ( isset( $row->series_index ) && $row->series_index ? $row->series_index : 1 ),
					$series_total
				);
			}
		}

		$technician = $this->technicians->find( (int) $row->technician_id );

		$start_ts = strtotime( (string) $row->start_utc . ' UTC' );
		$end_ts   = strtotime( (string) $row->end_utc . ' UTC' );
		$duration = ( $start_ts && $end_ts && $end_ts > $start_ts )
			? (int) round( ( $end_ts - $start_ts ) / MINUTE_IN_SECONDS )
			: Settings::int( 'default_lesson_minutes', 60 );

		$tz   = $technician ? (string) $technician->timezone : 'UTC';
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
			'id'                  => $booking_id,
			'technician_id'       => (int) $row->technician_id,
			'technician'          => $technician ? (string) $technician->display_name : '',
			'technician_timezone' => $tz,
			'customer_id'         => (int) $row->customer_id,
			'customer'            => $customer ? $customer->display_name : __( 'Customer', 'plumberslot' ),
			'service_id'          => $row->service_id ? (int) $row->service_id : null,
			'service'             => $service ? (string) $service->name : '—',
			'start_utc'           => (string) $row->start_utc,
			'end_utc'             => (string) $row->end_utc,
			'when'                => $when,
			'duration_min'        => $duration,
			'status'              => (string) $row->status,
			'series_id'           => $row->series_id ? (int) $row->series_id : null,
			'series_index'        => $row->series_index ? (int) $row->series_index : null,
			'series_total'        => $series_total,
			'series_label'        => $series_label,
			'price_minor'         => (int) $row->price_minor,
			'currency'            => (string) $row->currency,
			'payment'             => $payment,
			'payment_ref'         => $row->payment_ref,
			'notes'               => $row->notes,
			'address_line1'       => (string) ( $row->address_line1 ?? '' ),
			'address_line2'       => $row->address_line2 ?? null,
			'address_city'        => (string) ( $row->address_city ?? '' ),
			'address_state'       => (string) ( $row->address_state ?? '' ),
			'address_zip'         => (string) ( $row->address_zip ?? '' ),
			'photos'              => BookingPhotos::present( $row->photos ?? null ),
			'is_emergency'        => (bool) ( $row->is_emergency ?? false ),
			'job_stage'           => (string) ( $row->job_stage ?? 'scheduled' ),
			'meeting_ready'       => $meeting_ready && '' !== $join_url,
			'join_url'            => $join_url,
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

		if ( $this->guard->is_site_manager() || $this->guard->owns_technician( (int) $booking->technician_id ) ) {
			return true;
		}

		return $this->guard->deny();
	}

	public function attendance( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->bookings->mark_attendance( (int) $request['id'], (string) $request['status'] );

		return is_wp_error( $result ) ? $result : $this->ok( array( 'status' => (string) $request['status'] ) );
	}

	public function job_status( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->bookings->set_job_stage( (int) $request['id'], (string) $request['status'] );

		return is_wp_error( $result ) ? $result : $this->ok( array( 'job_stage' => (string) $request['status'] ) );
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
		$technician_id = (int) $request['technician_id'];
		$technician    = $this->technicians->find( $technician_id );

		if ( ! $technician || 'active' !== $technician->status ) {
			return $this->guard->deny();
		}

		$start    = Time::from_iso( (string) $request['start'] );
		$duration = Settings::int( 'default_lesson_minutes', 60 );

		if ( ! $this->repo->acquire_technician_lock( $technician_id ) ) {
			return $this->slot_taken();
		}

		try {
			if ( ! $this->slots->is_open( $technician_id, $start, (string) $technician->timezone, $duration ) ) {
				return $this->slot_taken();
			}

			$token = $this->locks->acquire(
				$technician_id,
				get_current_user_id(),
				Time::sql( $start ),
				Settings::int( 'hold_window_minutes', 10 )
			);
		} finally {
			$this->repo->release_technician_lock( $technician_id );
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
				'plumberslot_hold_not_found',
				__( 'That hold is no longer available.', 'plumberslot' ),
				array( 'status' => 404 )
			);
		}

		return $this->ok( array( 'released' => true ) );
	}

	private function slot_taken(): WP_Error {
		return new WP_Error(
			'plumberslot_slot_taken',
			__( 'Someone is booking that time right now. Pick another slot.', 'plumberslot' ),
			array( 'status' => 409 )
		);
	}

	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find( (int) $request['technician_id'] );

		if ( ! $technician || 'active' !== $technician->status ) {
			return $this->guard->deny();
		}

		if ( ! ServiceArea::allows( (string) $request['address_zip'] ) ) {
			return new WP_Error(
				'plumberslot_outside_service_area',
				__( 'That address is outside the area we currently serve. Please contact us directly to check availability.', 'plumberslot' ),
				array( 'status' => 422 )
			);
		}

		$service_id = $request['service_id'] ? (int) $request['service_id'] : null;
		$service    = null;

		if ( null !== $service_id ) {
			$service = $this->services->find_for_technician( $service_id, (int) $technician->id );

			if ( ! $service || 'active' !== $service->status ) {
				return $this->guard->deny();
			}
		}

		$actor       = get_current_user_id();
		$customer_id = $actor;

		if ( null !== $service ) {
			$free_estimate = $this->policy->can_use_free_estimate( $customer_id, $service );

			if ( is_wp_error( $free_estimate ) ) {
				return $free_estimate;
			}
		}

		$credit_id = null;

		if ( $request['use_credit'] ) {
			$credit = $this->credits->pick_usable( $actor, (int) $technician->id, $service_id );

			if ( ! $credit ) {
				return new WP_Error(
					'plumberslot_no_credits',
					__( 'There are no jobs left on this package.', 'plumberslot' ),
					array( 'status' => 409 )
				);
			}

			$credit_id = (int) $credit->id;
		}

		$duration = null !== $service
			? (int) $service->duration_min
			: Settings::int( 'default_lesson_minutes', 60 );

		if ( $credit_id ) {
			$price = 0;
		} elseif ( null !== $service && ! empty( $service->is_free_estimate ) ) {
			$price = 0;
		} elseif ( null !== $service ) {
			$price = (int) $service->price_minor;
		} else {
			$price = (int) $technician->hourly_rate_minor;
		}

		$split         = \PlumberSlot\Support\Deposits::split( $service, $price );
		$deposit_minor = $split['deposit_minor'];
		$balance_minor = $split['balance_minor'];

		// Currency lives on the technician; services inherit it and clients never set it.
		$currency = (string) $technician->currency;

		// Only ids that are real attachments still marked as an unclaimed
		// pending upload survive — never trust an id the client sends.
		$photo_ids = BookingPhotos::validate_pending( (array) ( $request['photo_ids'] ?? array() ) );

		// A customer cannot fake urgency on a service the technician never
		// marked emergency-eligible. The widget already hides the checkbox
		// for such a service, so this is defense-in-depth, not a hard error.
		$is_emergency = (bool) $request['is_emergency']
			&& null !== $service
			&& ! empty( $service->is_emergency_available );

		$result = $this->bookings->create(
			array(
				'technician_id'  => (int) $technician->id,
				'customer_id'    => $customer_id,
				'service_id'     => $service_id,
				'start_utc'      => Time::from_iso( (string) $request['start'] ),
				'duration_min'   => $duration,
				'technician_tz'  => (string) $technician->timezone,
				'customer_tz'    => (string) ( $request['timezone'] ?? $technician->timezone ),
				'price_minor'    => $price,
				'deposit_minor'  => $deposit_minor,
				'balance_minor'  => $balance_minor,
				'currency'       => $currency,
				'credit_id'      => $credit_id,
				'consume_credit' => null !== $credit_id,
				'lock_token'     => $request['lock_token'] ? (string) $request['lock_token'] : null,
				'notes'          => $request['notes'] ? (string) $request['notes'] : null,
				'address_line1'  => (string) $request['address_line1'],
				'address_line2'  => $request['address_line2'] ? (string) $request['address_line2'] : null,
				'address_city'   => (string) $request['address_city'],
				'address_state'  => (string) $request['address_state'],
				'address_zip'    => (string) $request['address_zip'],
				'photo_ids'      => $photo_ids,
				'is_emergency'   => $is_emergency,
			)
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// A number on file today is ready for SMS the moment a site owner
		// turns it on, regardless of whether it is enabled right now.
		if ( ! empty( $request['mobile'] ) ) {
			update_user_meta( $customer_id, '_plumberslot_mobile', sanitize_text_field( (string) $request['mobile'] ) );
		}

		$booking  = $this->repo->find( (int) $result );
		$customer = get_userdata( $customer_id );
		$window   = Settings::int( 'reschedule_window_minutes', 720 );
		$start    = Time::from_iso( (string) $request['start'] );
		$deadline = $start->getTimestamp() - ( $window * MINUTE_IN_SECONDS );
		$provider = (string) get_user_meta( (int) $technician->user_id, 'plumberslot_meeting_provider', true );

		return $this->ok(
			array(
				'id'                  => (int) $result,
				'status'              => $booking ? (string) $booking->status : 'pending',
				'start_utc'           => $booking ? (string) $booking->start_utc : Time::sql( $start ),
				'end_utc'             => $booking ? (string) $booking->end_utc : null,
				'duration_min'        => $duration,
				'price_minor'         => $price,
				'deposit_minor'       => $deposit_minor,
				'balance_minor'       => $balance_minor,
				'currency'            => $currency,
				'payment'             => $credit_id ? 'credit' : ( 0 === $price ? 'free' : 'unpaid' ),
				'service'             => $service ? (string) $service->name : '',
				'technician'          => (string) $technician->display_name,
				'customer'            => $customer ? $customer->display_name : '',
				'meeting_provider'    => $provider ? $provider : 'Google Meet',
				'reschedule_deadline' => gmdate( 'c', max( time(), $deadline ) ),
				'dashboard_url'       => home_url( '/customer-dashboard/' ),
				'address_line1'       => (string) $request['address_line1'],
				'address_line2'       => $request['address_line2'] ? (string) $request['address_line2'] : null,
				'address_city'        => (string) $request['address_city'],
				'address_state'       => (string) $request['address_state'],
				'address_zip'         => (string) $request['address_zip'],
				'photos'              => BookingPhotos::present_ids( $photo_ids ),
				'is_emergency'        => $is_emergency,
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
