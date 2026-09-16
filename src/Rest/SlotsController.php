<?php
/**
 * GET /plumberslot/v1/slots — the endpoint the booking widget lives on.
 *
 * Public by design: a visitor has to see open times before signing up. That
 * makes it the most exposed surface in the plugin, so it is read-only, rate
 * limited, and returns nothing beyond start times and states.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Support\RateLimiter;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;
use PlumberSlot\Support\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SlotsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly SlotEngine $engine,
		private readonly TechnicianRepository $technicians
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/slots',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'technician_id'   => array(
						'required'          => true,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
					'from'            => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_iso8601' ),
					),
					'to'              => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_iso8601' ),
					),
					'duration'        => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 60,
					),
					'timezone'        => array(
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_timezone' ),
					),
					'exclude_booking' => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/slots-any',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index_any' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'technician_ids' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_id_list' ),
					),
					'from'           => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_iso8601' ),
					),
					'to'             => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_iso8601' ),
					),
					'duration'       => array(
						'required'          => false,
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
						'default'           => 60,
					),
					'timezone'       => array(
						'required'          => false,
						'type'              => 'string',
						'validate_callback' => array( Validate::class, 'is_timezone' ),
					),
				),
			)
		);
	}

	/**
	 * Anyone may read, but not at any rate.
	 */
	public function can_read(): bool|WP_Error {
		if ( ! RateLimiter::allow( 'read_slots', 60 ) ) {
			return new WP_Error(
				'plumberslot_too_many',
				__( 'Too many requests. Wait a moment and try again.', 'plumberslot' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find( (int) $request['technician_id'] );

		if ( ! $technician || 'active' !== $technician->status ) {
			return $this->guard->deny();
		}

		$exclude = (int) ( $request['exclude_booking'] ?? 0 );

		if ( $exclude > 0 ) {
			$allowed = $this->can_exclude_booking( $request, (int) $technician->id );

			if ( true !== $allowed ) {
				return is_wp_error( $allowed ) ? $allowed : $this->guard->deny();
			}
		}

		$from = Time::from_iso( (string) $request['from'] );
		$to   = Time::from_iso( (string) $request['to'] );

		try {
			SlotEngine::assert_valid_range( $from, $to );
		} catch ( \InvalidArgumentException ) {
			return new WP_Error(
				'plumberslot_bad_range',
				sprintf(
					/* translators: %d: maximum number of days. */
					__( 'Ask for a window of up to %d days.', 'plumberslot' ),
					SlotEngine::MAX_RANGE_DAYS
				),
				array( 'status' => 422 )
			);
		}

		$slots = $this->engine->slots_for(
			(int) $technician->id,
			$from,
			$to,
			(string) $technician->timezone,
			min( 480, max( 15, (int) $request['duration'] ) ),
			$exclude
		);

		$display_tz = (string) ( $request['timezone'] ?? $technician->timezone );

		return $this->ok(
			array(
				'timezone'            => $display_tz,
				'technician_timezone' => $technician->timezone,
				'hold_minutes'        => Settings::int( 'hold_window_minutes', 10 ),
				'slots'               => $slots,
			)
		);
	}

	/**
	 * Every open slot across a candidate list of technicians, merged into one
	 * list. Used only before a specific technician is chosen -- an aggregate
	 * "book with anyone" service groups several technicians' rows under one
	 * name (see PublicTechnicianController::business()), and this is how the
	 * widget finds the earliest opening across all of them. Once a slot is
	 * picked the widget resolves a real technician and every later request
	 * goes through the ordinary /slots route unchanged.
	 */
	public function index_any( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'absint', explode( ',', (string) $request['technician_ids'] ) ),
					static fn ( int $id ): bool => $id > 0
				)
			)
		);

		if ( array() === $ids ) {
			return $this->guard->deny();
		}

		$from = Time::from_iso( (string) $request['from'] );
		$to   = Time::from_iso( (string) $request['to'] );

		try {
			SlotEngine::assert_valid_range( $from, $to );
		} catch ( \InvalidArgumentException ) {
			return new WP_Error(
				'plumberslot_bad_range',
				sprintf(
					/* translators: %d: maximum number of days. */
					__( 'Ask for a window of up to %d days.', 'plumberslot' ),
					SlotEngine::MAX_RANGE_DAYS
				),
				array( 'status' => 422 )
			);
		}

		$duration = min( 480, max( 15, (int) $request['duration'] ) );
		$by_start = array();

		foreach ( $ids as $technician_id ) {
			$technician = $this->technicians->find( $technician_id );

			if ( ! $technician || 'active' !== (string) $technician->status ) {
				continue;
			}

			$slots = $this->engine->slots_for( $technician_id, $from, $to, (string) $technician->timezone, $duration );

			foreach ( $slots as $slot ) {
				$payload = $slot instanceof \JsonSerializable ? $slot->jsonSerialize() : (array) $slot;

				if ( 'open' !== ( $payload['state'] ?? '' ) ) {
					continue;
				}

				$start = (string) ( $payload['start'] ?? '' );

				if ( '' === $start ) {
					continue;
				}

				// Two technicians open at the same instant would otherwise show
				// as two buttons for what looks like one slot; keep one,
				// deterministically, rather than let request order decide.
				if ( isset( $by_start[ $start ] ) && $by_start[ $start ]['technician_id'] <= $technician_id ) {
					continue;
				}

				$by_start[ $start ] = array(
					'start'         => $start,
					'state'         => 'open',
					'technician_id' => $technician_id,
				);
			}
		}

		ksort( $by_start );

		$display_tz = (string) ( $request['timezone'] ?? '' );

		return $this->ok(
			array(
				'timezone'     => '' !== $display_tz ? $display_tz : wp_timezone_string(),
				'hold_minutes' => Settings::int( 'hold_window_minutes', 10 ),
				'slots'        => array_values( $by_start ),
			)
		);
	}

	/**
	 * Only the technician (or a site manager) may ask the slot engine to ignore a booking.
	 */
	private function can_exclude_booking( WP_REST_Request $request, int $technician_id ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		return $this->guard->owns_technician( $technician_id ) ? true : $this->guard->deny();
	}
}
