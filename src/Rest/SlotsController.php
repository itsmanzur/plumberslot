<?php
/**
 * GET /tutorslot/v1/slots — the endpoint the booking widget lives on.
 *
 * Public by design: a visitor has to see open times before signing up. That
 * makes it the most exposed surface in the plugin, so it is read-only, rate
 * limited, and returns nothing beyond start times and states.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Rest;

use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Domain\SlotEngine;
use TutorSlot\Support\RateLimiter;
use TutorSlot\Support\Settings;
use TutorSlot\Support\Time;
use TutorSlot\Support\Validate;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class SlotsController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly SlotEngine $engine,
		private readonly TutorRepository $tutors
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
					'tutor_id'        => array(
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
	}

	/**
	 * Anyone may read, but not at any rate.
	 */
	public function can_read(): bool|WP_Error {
		if ( ! RateLimiter::allow( 'read_slots', 60 ) ) {
			return new WP_Error(
				'tutorslot_too_many',
				__( 'Too many requests. Wait a moment and try again.', 'tutorslot' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	public function index( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$tutor = $this->tutors->find( (int) $request['tutor_id'] );

		if ( ! $tutor || 'active' !== $tutor->status ) {
			return $this->guard->deny();
		}

		$exclude = (int) ( $request['exclude_booking'] ?? 0 );

		if ( $exclude > 0 ) {
			$allowed = $this->can_exclude_booking( $request, (int) $tutor->id );

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
				'tutorslot_bad_range',
				sprintf(
					/* translators: %d: maximum number of days. */
					__( 'Ask for a window of up to %d days.', 'tutorslot' ),
					SlotEngine::MAX_RANGE_DAYS
				),
				array( 'status' => 422 )
			);
		}

		$slots = $this->engine->slots_for(
			(int) $tutor->id,
			$from,
			$to,
			(string) $tutor->timezone,
			min( 480, max( 15, (int) $request['duration'] ) ),
			$exclude
		);

		$display_tz = (string) ( $request['timezone'] ?? $tutor->timezone );

		return $this->ok(
			array(
				'timezone'       => $display_tz,
				'tutor_timezone' => $tutor->timezone,
				'hold_minutes'   => Settings::int( 'hold_window_minutes', 10 ),
				'slots'          => $slots,
			)
		);
	}

	/**
	 * Only the tutor (or a site manager) may ask the slot engine to ignore a booking.
	 */
	private function can_exclude_booking( WP_REST_Request $request, int $tutor_id ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		return $this->guard->owns_tutor( $tutor_id ) ? true : $this->guard->deny();
	}
}
