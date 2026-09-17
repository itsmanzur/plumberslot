<?php
/**
 * /plumberslot/v1/dashboard — technician home aggregates.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Rest;

use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Frontend\BookingPage;
use PlumberSlot\Media\BookingPhotos;
use PlumberSlot\Support\Cache;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Settings;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

final class DashboardController extends AbstractController {

	/**
	 * Rolling window for the manager-only business snapshot. Fixed rather
	 * than a Settings field -- nothing in this first pass needs it to be
	 * configurable per site.
	 */
	private const SNAPSHOT_WINDOW_DAYS = 30;

	public function __construct(
		Guard $guard,
		private readonly TechnicianRepository $technicians,
		private readonly BookingRepository $bookings,
		private readonly CreditRepository $credits,
		private readonly AvailabilityRepository $availability,
		private readonly ServiceRepository $services
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
					'technician_id' => array(
						'type'              => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// A site-wide rollup -- busiest technician, top service, no-show rate
		// -- is deliberately its own route rather than a key folded into
		// `/dashboard`: that response is cached per technician_id (see
		// Cache::dashboard()), and a manager-only field riding along inside a
		// cache entry a technician's own request can also warm would leak
		// (or wrongly withhold) the snapshot depending on request order.
		// A separate, uncached, MANAGE_ALL-gated route has no such hazard.
		register_rest_route(
			self::NAMESPACE,
			'/dashboard/snapshot',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'snapshot' ),
				'permission_callback' => array( $this, 'can_view_snapshot' ),
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

		$technician_id = $this->resolve_technician_id( $request );

		if ( $technician_id <= 0 ) {
			return $this->guard->deny();
		}

		return $this->guard->owns_technician( $technician_id ) ? true : $this->guard->deny();
	}

	/**
	 * Manager-only, same gating pattern as AuditController::can_manage() --
	 * a technician should not see a ranking of how busy their coworkers are.
	 */
	public function can_view_snapshot( WP_REST_Request $request ): bool|WP_Error {
		$logged_in = $this->require_login();

		if ( is_wp_error( $logged_in ) ) {
			return $logged_in;
		}

		$nonce = $this->verify_nonce( $request );

		if ( is_wp_error( $nonce ) ) {
			return $nonce;
		}

		if ( ! current_user_can( Capabilities::MANAGE_ALL ) ) {
			return $this->guard->deny();
		}

		return true;
	}

	public function snapshot( WP_REST_Request $request ): WP_REST_Response {
		return $this->ok( $this->business_snapshot() );
	}

	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$technician_id = $this->resolve_technician_id( $request );
		$technician    = $this->technicians->find( $technician_id );

		if ( ! $technician ) {
			return $this->guard->deny();
		}

		$cached = Cache::dashboard( $technician_id );
		if ( null !== $cached ) {
			$cached['greeting'] = $this->greeting();

			return $this->ok( $cached );
		}

		$tz         = (string) ( $technician->timezone ? $technician->timezone : wp_timezone_string() );
		$now        = new \DateTimeImmutable( 'now', new \DateTimeZone( $tz ) );
		$today      = $now->format( 'Y-m-d' );
		$week_start = $now->modify( 'monday this week' )->setTime( 0, 0 );
		$week_end   = $week_start->modify( '+7 days' );

		$week = $this->bookings->find_for_technician(
			$technician_id,
			array(
				'from_utc' => $week_start->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'to_utc'   => $week_end->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
				'per_page' => 100,
			)
		);

		$upcoming = $this->bookings->find_for_technician(
			$technician_id,
			array(
				'from_utc' => gmdate( 'Y-m-d H:i:s' ),
				'per_page' => 8,
			)
		);

		$pending = $this->bookings->find_for_technician(
			$technician_id,
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

		$open_blocks = count( $this->availability->rules_for( $technician_id ) );
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

			$customer      = get_userdata( (int) $row->customer_id );
			$service       = $row->service_id ? $this->services->find( (int) $row->service_id ) : null;
			$today_items[] = array(
				'id'           => (int) $row->id,
				'title'        => sprintf(
					'%1$s · %2$s',
					$customer ? $customer->display_name : __( 'Customer', 'plumberslot' ),
					$service ? $service->name : __( 'Service call', 'plumberslot' )
				),
				'time'         => $local->format( 'H:i' ),
				'startPct'     => (int) round( ( $offset / $day_span ) * 100 ),
				'widthPct'     => $width,
				'done'         => $local < $now,
				'address'      => $this->short_address( $row ),
				'has_photos'   => array() !== BookingPhotos::decode( $row->photos ?? null ),
				'is_emergency' => ! empty( $row->is_emergency ),
			);
		}

		$now_pct = (int) round( ( ( $now->getTimestamp() - $day_start->getTimestamp() ) / $day_span ) * 100 );

		$next_up = array();
		foreach ( $upcoming['items'] as $row ) {
			if ( in_array( (string) $row->status, array( 'cancelled', 'refunded', 'moved', 'payment_expired' ), true ) ) {
				continue;
			}
			$booking_id = (int) $row->id;
			$customer   = get_userdata( (int) $row->customer_id );
			$service    = $row->service_id ? $this->services->find( (int) $row->service_id ) : null;
			$start      = new \DateTimeImmutable( (string) $row->start_utc, new \DateTimeZone( 'UTC' ) );
			$end        = new \DateTimeImmutable( (string) $row->end_utc, new \DateTimeZone( 'UTC' ) );
			$local      = $start->setTimezone( new \DateTimeZone( $tz ) );
			$local_end  = $end->setTimezone( new \DateTimeZone( $tz ) );
			$balance    = (int) $row->balance_minor;
			$paid       = ! empty( $row->payment_ref ) || 0 === (int) $row->price_minor;
			$payment    = ! empty( $row->credit_id )
				? __( 'Credit used', 'plumberslot' )
				: ( $paid
					? ( 0 === (int) $row->price_minor
						? __( 'Free', 'plumberslot' )
						: ( $balance > 0 ? __( 'Deposit paid', 'plumberslot' ) : __( 'Paid', 'plumberslot' ) ) )
					: __( 'Due', 'plumberslot' ) );
			$next_up[]  = array(
				'id'            => $booking_id,
				'customer'      => $customer ? $customer->display_name : __( 'Customer', 'plumberslot' ),
				'initials'      => $this->initials( $customer ? $customer->display_name : __( 'Customer', 'plumberslot' ) ),
				'address'       => $this->short_address( $row ),
				'has_photos'    => array() !== BookingPhotos::decode( $row->photos ?? null ),
				'is_emergency'  => ! empty( $row->is_emergency ),
				'service'       => $service ? (string) $service->name : __( 'Service call', 'plumberslot' ),
				'when'          => $local->format( 'Y-m-d' ) === $today
					? $local->format( 'H:i' ) . ' – ' . $local_end->format( 'H:i' )
					: $local->format( 'D H:i' ),
				'payment'       => $payment,
				'balance_minor' => $balance,
				'payment_tone'  => __( 'Due', 'plumberslot' ) === $payment ? 'wait' : ( __( 'Credit used', 'plumberslot' ) === $payment ? 'idle' : 'ok' ),
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
				'label' => __( 'Bookings awaiting confirmation', 'plumberslot' ),
				'value' => count( $pending['items'] ),
				'href'  => 'bookings',
			);
		}

		foreach ( $pending['items'] as $row ) {
			$customer = get_userdata( (int) $row->customer_id );
			$needs[]  = array(
				'id'         => (int) $row->id,
				'booking_id' => (int) $row->id,
				'label'      => sprintf(
					/* translators: %s: customer name */
					__( 'Confirm booking for %s', 'plumberslot' ),
					$customer ? $customer->display_name : __( 'customer', 'plumberslot' )
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
				'currency'        => (string) $technician->currency,
				'fill_rate'       => $fill_rate,
				'credits_held'    => $this->credits->remaining_for_technician( $technician_id ),
			),
			'next_up'         => $next_up,
			'needs_attention' => $needs,
			'booking_url'     => $this->booking_url( $technician ),
			'shortcode'       => sprintf( '[plumberslot technician="%s"]', esc_attr( (string) $technician->slug ) ),
			'defaults'        => array(
				'lesson_minutes'    => Settings::int( 'default_lesson_minutes', 60 ),
				'buffer_minutes'    => Settings::int( 'buffer_minutes', 0 ),
				'lead_time_minutes' => Settings::int( 'lead_time_minutes', 0 ),
			),
		);

		Cache::set_dashboard( $technician_id, $payload );

		return $this->ok( $payload );
	}

	/**
	 * Busiest technician, top service, and no-show rate across every
	 * technician's bookings in the rolling window, for a manager's one-screen
	 * view of how the business is running. Every figure is omitted rather
	 * than reported as a misleading zero when there is nothing to measure yet.
	 *
	 * @return array{busiest_technician:array{name:string,count:int}|null,top_service:array{name:string,count:int}|null,no_show_rate:int|null,window_days:int}
	 */
	private function business_snapshot(): array {
		$to   = gmdate( 'Y-m-d H:i:s' );
		$from = gmdate( 'Y-m-d H:i:s', time() - self::SNAPSHOT_WINDOW_DAYS * DAY_IN_SECONDS );

		$busiest_technician = null;
		$technician_rows    = $this->bookings->busiest_technicians( $from, $to, 1 );

		if ( $technician_rows ) {
			$technician = $this->technicians->find( (int) $technician_rows[0]->technician_id );

			if ( $technician ) {
				$busiest_technician = array(
					'name'  => (string) $technician->display_name,
					'count' => (int) $technician_rows[0]->total,
				);
			}
		}

		$top_service  = null;
		$service_rows = $this->bookings->most_booked_services( $from, $to, 1 );

		if ( $service_rows ) {
			$top_service = array(
				'name'  => (string) $service_rows[0]->name,
				'count' => (int) $service_rows[0]->total,
			);
		}

		$attendance   = $this->bookings->attendance_counts( $from, $to );
		$denominator  = $attendance['completed'] + $attendance['no_show'];
		$no_show_rate = $denominator > 0
			? (int) round( ( $attendance['no_show'] / $denominator ) * 100 )
			: null;

		return array(
			'busiest_technician' => $busiest_technician,
			'top_service'        => $top_service,
			'no_show_rate'       => $no_show_rate,
			'window_days'        => self::SNAPSHOT_WINDOW_DAYS,
		);
	}

	private function greeting(): string {
		$user = wp_get_current_user();

		return sprintf(
			/* translators: %s: first name */
			__( 'Good day, %s', 'plumberslot' ),
			$user->first_name ? $user->first_name : $user->display_name
		);
	}

	private function resolve_technician_id( WP_REST_Request $request ): int {
		$requested = (int) $request['technician_id'];

		if ( $requested > 0 ) {
			return $requested;
		}

		$owned = $this->technicians->technician_id_for_user( get_current_user_id() );

		if ( $owned > 0 || ! $this->guard->is_site_manager() ) {
			return $owned;
		}

		$active = $this->technicians->all_active();

		return $active ? (int) $active[0]->id : 0;
	}

	/**
	 * Compact "city, zip" (or street line, if city is missing) for a booking
	 * row's job-site address — kept short for list and timeline views.
	 */
	private function short_address( object $row ): string {
		$city  = trim( (string) ( $row->address_city ?? '' ) );
		$zip   = trim( (string) ( $row->address_zip ?? '' ) );
		$line1 = trim( (string) ( $row->address_line1 ?? '' ) );

		$city_zip = trim( implode( ' ', array_filter( array( $city, $zip ) ) ) );

		return '' !== $city_zip ? $city_zip : $line1;
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

	private function booking_url( object $technician ): string {
		return BookingPage::url_for_technician( $technician );
	}
}
