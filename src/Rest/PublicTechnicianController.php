<?php
/**
 * Public technician profile + services for the booking widget.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\ReviewRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Domain\SlotEngine;
use PlumberSlot\Support\RateLimiter;
use PlumberSlot\Support\Settings;
use PlumberSlot\Support\Time;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class PublicTechnicianController extends AbstractController {

	public function __construct(
		Guard $guard,
		private readonly TechnicianRepository $technicians,
		private readonly ServiceRepository $services,
		private readonly ReviewRepository $reviews,
		private readonly SlotEngine $slots,
		private readonly BookingRepository $bookings
	) {
		parent::__construct( $guard );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/public/technicians/(?P<id>[\d]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'id'       => array( 'sanitize_callback' => 'absint' ),
					'timezone' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/public/technicians/by-slug/(?P<slug>[a-z0-9\-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show_by_slug' ),
				'permission_callback' => array( $this, 'can_read' ),
				'args'                => array(
					'slug' => array( 'sanitize_callback' => 'sanitize_title' ),
				),
			)
		);
	}

	public function can_read(): bool|WP_Error {
		if ( ! RateLimiter::allow( 'read_public_technician', 60 ) ) {
			return new WP_Error(
				'plumberslot_too_many',
				__( 'Too many requests. Wait a moment and try again.', 'plumberslot' ),
				array( 'status' => 429 )
			);
		}

		return true;
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find( (int) $request['id'] );

		return $this->present_or_deny( $technician, (string) ( $request['timezone'] ?? '' ) );
	}

	public function show_by_slug( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician = $this->technicians->find_by_slug( (string) $request['slug'] );

		return $this->present_or_deny( $technician, '' );
	}

	private function present_or_deny( ?object $technician, string $display_tz ): WP_REST_Response|WP_Error {
		if ( ! $technician || 'active' !== (string) $technician->status ) {
			return $this->guard->deny();
		}

		$rating   = $this->reviews->rating_summary( (int) $technician->id );
		$jobs     = $this->completed_job_count( (int) $technician->id );
		$services = array();

		foreach ( $this->services->all_for_technician( (int) $technician->id ) as $service ) {
			if ( 'active' !== (string) $service->status ) {
				continue;
			}

			$services[] = array(
				'id'               => (int) $service->id,
				'name'             => (string) $service->name,
				'category'         => $service->category,
				'duration_min'     => (int) $service->duration_min,
				'price_minor'      => (int) $service->price_minor,
				'currency'         => (string) $technician->currency,
				'is_free_estimate' => (bool) $service->is_free_estimate,
			);
		}

		$from_price = null;
		foreach ( $services as $service ) {
			if ( $service['is_free_estimate'] ) {
				continue;
			}
			if ( null === $from_price || $service['price_minor'] < $from_price ) {
				$from_price = $service['price_minor'];
			}
		}

		$meta     = $this->profile_meta( $technician );
		$duration = (int) ( $services[0]['duration_min'] ?? Settings::int( 'default_lesson_minutes', 60 ) );
		$next     = $this->next_opening( $technician, $duration, $display_tz );

		$review_rows = array();
		foreach ( $this->reviews->approved_for_technician( (int) $technician->id ) as $row ) {
			$author        = get_userdata( (int) $row->author_id );
			$review_rows[] = array(
				'id'     => (int) $row->id,
				'rating' => (int) $row->rating,
				'body'   => (string) $row->body,
				'author' => $author ? $author->display_name : __( 'Customer', 'plumberslot' ),
				'role'   => __( 'Customer', 'plumberslot' ),
			);
		}

		$user = get_userdata( (int) $technician->user_id );

		return $this->ok(
			array(
				'id'                        => (int) $technician->id,
				'slug'                      => (string) $technician->slug,
				'display_name'              => (string) $technician->display_name,
				'initials'                  => $this->initials( (string) $technician->display_name ),
				'bio'                       => (string) ( $technician->bio ?? '' ),
				'timezone'                  => (string) $technician->timezone,
				'currency'                  => (string) $technician->currency,
				'hourly_rate_minor'         => (int) $technician->hourly_rate_minor,
				'from_price_minor'          => $from_price ?? (int) $technician->hourly_rate_minor,
				'rating'                    => $rating['average'],
				'review_count'              => $rating['count'],
				'job_count'                 => $jobs,
				'years_teaching'            => $meta['years_teaching'],
				'response_time'             => $meta['response_time'],
				'languages'                 => $meta['languages'],
				'meeting_provider'          => $meta['meeting_provider'],
				'offer_free_estimate'       => Settings::bool( 'offer_free_estimate', true ),
				'reschedule_window_minutes' => Settings::int( 'reschedule_window_minutes', 720 ),
				'hold_minutes'              => Settings::int( 'hold_window_minutes', 10 ),
				'default_duration'          => $duration,
				'next_opening'              => $next,
				'services'                  => $services,
				'reviews'                   => $review_rows,
				'email'                     => $user ? $user->user_email : '',
			)
		);
	}

	/**
	 * @return array{years_teaching:int,response_time:string,languages:list<string>,meeting_provider:string}
	 */
	private function profile_meta( object $technician ): array {
		$user_id  = (int) $technician->user_id;
		$years    = (int) get_user_meta( $user_id, 'plumberslot_years_teaching', true );
		$response = (string) get_user_meta( $user_id, 'plumberslot_response_time', true );
		$langs    = get_user_meta( $user_id, 'plumberslot_languages', true );
		$provider = (string) get_user_meta( $user_id, 'plumberslot_meeting_provider', true );

		if ( $years <= 0 && ! empty( $technician->created_at ) ) {
			$created = strtotime( (string) $technician->created_at );
			$years   = max( 1, (int) floor( ( time() - $created ) / YEAR_IN_SECONDS ) );
		}

		if ( is_string( $langs ) && '' !== $langs ) {
			$languages = array_values( array_filter( array_map( 'trim', explode( ',', $langs ) ) ) );
		} elseif ( is_array( $langs ) ) {
			$languages = array_values( array_map( 'strval', $langs ) );
		} else {
			$languages = array( 'Bangla', 'English' );
		}

		return array(
			'years_teaching'   => max( 1, $years ? $years : 1 ),
			'response_time'    => '' !== $response ? $response : __( '~2 hours', 'plumberslot' ),
			'languages'        => $languages,
			'meeting_provider' => '' !== $provider ? $provider : 'Google Meet',
		);
	}

	private function completed_job_count( int $technician_id ): int {
		$result = $this->bookings->find_for_technician(
			$technician_id,
			array(
				'status'   => 'completed',
				'per_page' => 1,
			)
		);

		// Prefer total from a broader query without status filter for "jobs completed".
		$all = $this->bookings->find_for_technician(
			$technician_id,
			array(
				'to_utc'   => gmdate( 'Y-m-d H:i:s' ),
				'per_page' => 1,
			)
		);

		return max( (int) $result['total'], (int) $all['total'] );
	}

	/**
	 * @return array{start:string,label:string}|null
	 */
	private function next_opening( object $technician, int $duration, string $display_tz ): ?array {
		$tz    = (string) $technician->timezone;
		$from  = new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) );
		$to    = $from->modify( '+14 days' );
		$slots = $this->slots->slots_for( (int) $technician->id, $from, $to, $tz, $duration );

		foreach ( $slots as $slot ) {
			$payload = $slot instanceof \JsonSerializable ? $slot->jsonSerialize() : (array) $slot;
			$state   = $payload['state'] ?? '';
			$start   = $payload['start'] ?? '';

			if ( 'open' !== $state || ! is_string( $start ) || '' === $start ) {
				continue;
			}

			try {
				$utc   = Time::from_iso( $start );
				$local = $utc->setTimezone( new \DateTimeZone( '' !== $display_tz && Time::is_valid_zone( $display_tz ) ? $display_tz : $tz ) );
			} catch ( \Exception ) {
				continue;
			}

			return array(
				'start' => $start,
				'label' => $local->format( 'D j M · H:i' ),
			);
		}

		return null;
	}

	private function initials( string $name ): string {
		$parts = preg_split( '/\s+/', trim( $name ) );
		$parts = $parts ? $parts : array();
		$out   = '';

		foreach ( array_slice( $parts, 0, 2 ) as $part ) {
			$out .= strtoupper( substr( $part, 0, 1 ) );
		}

		return '' !== $out ? $out : 'T';
	}
}
