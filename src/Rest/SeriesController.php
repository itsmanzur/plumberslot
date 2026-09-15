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
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
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
		private readonly TechnicianRepository $technicians,
		private readonly ServiceRepository $services,
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
					'days'          => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
					),
					'count'         => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
						'maximum'  => 104,
					),
					'use_credit'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'notes'         => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'timezone'      => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
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
		$technician = $this->technicians->find( (int) $request['technician_id'] );
		if ( ! $technician || 'active' !== $technician->status ) {
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

		$start    = Time::from_iso( (string) $request['start'] );
		$duration = null !== $service
			? (int) $service->duration_min
			: Settings::int( 'default_lesson_minutes', 60 );

		$use_credit = (bool) $request['use_credit'];
		$credit_id  = null;
		if ( $use_credit ) {
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

		if ( $credit_id ) {
			$price = 0;
		} elseif ( null !== $service && ! empty( $service->is_free_estimate ) ) {
			$price = 0;
		} elseif ( null !== $service ) {
			$price = (int) $service->price_minor;
		} else {
			$price = (int) $technician->hourly_rate_minor;
		}

		$customer_tz = (string) ( $request['timezone'] ? $request['timezone'] : $technician->timezone );
		$currency    = (string) $technician->currency;

		$result = $this->recurrence->create_series(
			array(
				'technician_id'  => (int) $technician->id,
				'customer_id'    => $customer_id,
				'service_id'     => $service_id,
				'start_utc'      => $start,
				'duration_min'   => $duration,
				'technician_tz'  => (string) $technician->timezone,
				'customer_tz'    => $customer_tz,
				'price_minor'    => $price,
				'currency'       => $currency,
				'credit_id'      => $credit_id,
				'consume_credit' => null !== $credit_id,
				'notes'          => (string) ( $request['notes'] ?? '' ),
				'address_line1'  => (string) $request['address_line1'],
				'address_line2'  => $request['address_line2'] ? (string) $request['address_line2'] : null,
				'address_city'   => (string) $request['address_city'],
				'address_state'  => (string) $request['address_state'],
				'address_zip'    => (string) $request['address_zip'],
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
					/* translators: 1: current or booked appointment count, 2: total or requested appointment count */
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

		$appointments = $this->bookings->find_for_series( (int) $series->id );
		$active       = array_values(
			array_filter(
				$appointments,
				static fn ( object $b ): bool => ! in_array( (string) $b->status, array( 'cancelled', 'refunded', 'moved', 'payment_expired' ), true )
			)
		);

		return $this->ok(
			array(
				'id'            => (int) $series->id,
				'technician_id' => (int) $series->technician_id,
				'customer_id'   => (int) $series->customer_id,
				'rrule'         => (string) $series->rrule,
				'total_count'   => (int) $series->total_count,
				'active'        => count( $active ),
				'label'         => sprintf(
					/* translators: 1: current or booked appointment count, 2: total or requested appointment count */
					__( 'Weekly %1$d/%2$d', 'plumberslot' ),
					count( $active ),
					(int) $series->total_count
				),
				'appointments'  => array_map(
					static function ( object $b ): array {
						return array(
							'id'           => (int) $b->id,
							'series_index' => (int) $b->series_index,
							'start_utc'    => (string) $b->start_utc,
							'status'       => (string) $b->status,
						);
					},
					$appointments
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
		if ( (int) $series->customer_id === $user_id ) {
			return true;
		}

		return $this->guard->owns_technician( (int) $series->technician_id );
	}
}
