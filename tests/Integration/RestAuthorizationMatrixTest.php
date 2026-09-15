<?php
/**
 * Namespace-wide REST authorization inventory.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RestAuthorizationMatrixTest extends WP_UnitTestCase {

	private const PREFIX = '/tutorslot/v1';

	public function test_every_route_method_has_the_expected_anonymous_boundary(): void {
		wp_set_current_user( 0 );

		$expected = $this->expected_matrix();
		$actual   = $this->registered_endpoints();
		ksort( $expected );
		ksort( $actual );

		self::assertSame(
			array_keys( $expected ),
			array_keys( $actual ),
			'TutorSlot REST inventory changed; classify every new or removed route method in the authorization matrix.'
		);

		foreach ( $expected as $id => $policy ) {
			$endpoint = $actual[ $id ];
			self::assertArrayHasKey( 'permission_callback', $endpoint, $id . ' has no permission callback.' );
			self::assertIsCallable( $endpoint['permission_callback'], $id . ' permission callback is not callable.' );

			[$method, $route] = explode( ' ', $id, 2 );
			$request          = new WP_REST_Request( $method, $route );
			$result           = call_user_func( $endpoint['permission_callback'], $request );

			if ( 'public' === $policy ) {
				self::assertTrue( $result, $id . ' must remain publicly discoverable.' );
			} else {
				self::assertNotTrue( $result, $id . ' unexpectedly allows an anonymous request.' );
			}
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private function registered_endpoints(): array {
		$actual = array();

		foreach ( rest_get_server()->get_routes() as $route => $endpoints ) {
			if ( ! str_starts_with( $route, self::PREFIX . '/' ) ) {
				continue;
			}

			foreach ( $endpoints as $endpoint ) {
				if ( ! is_array( $endpoint ) || ! isset( $endpoint['methods'] ) ) {
					continue;
				}

				foreach ( $this->endpoint_methods( $endpoint['methods'] ) as $method ) {
					$id = $method . ' ' . $route;
					self::assertArrayNotHasKey( $id, $actual, 'Duplicate TutorSlot REST route method: ' . $id );
					$actual[ $id ] = $endpoint;
				}
			}
		}

		return $actual;
	}

	/**
	 * @param mixed $methods Registered endpoint methods.
	 * @return list<string>
	 */
	private function endpoint_methods( mixed $methods ): array {
		if ( is_string( $methods ) ) {
			return array_values( array_filter( array_map( 'trim', explode( ',', strtoupper( $methods ) ) ) ) );
		}

		$out = array();
		foreach ( (array) $methods as $method => $enabled ) {
			if ( is_string( $method ) && $enabled ) {
				$out[] = strtoupper( $method );
			} elseif ( is_string( $enabled ) ) {
				$out[] = strtoupper( $enabled );
			}
		}

		return $out;
	}

	/**
	 * Policy labels document the next, role-specific matrix without weakening
	 * this namespace-wide anonymous boundary test.
	 *
	 * @return array<string, 'public'|'authenticated'|'account'|'owner'|'tutor'|'manager'>
	 */
	private function expected_matrix(): array {
		return array(
			'GET /tutorslot/v1/audit'                      => 'manager',
			'GET /tutorslot/v1/availability/(?P<tutor_id>\d+)' => 'owner',
			'PUT /tutorslot/v1/availability/(?P<tutor_id>\d+)' => 'owner',
			'GET /tutorslot/v1/availability/(?P<tutor_id>\d+)/exceptions' => 'owner',
			'POST /tutorslot/v1/availability/(?P<tutor_id>\d+)/exceptions' => 'owner',
			'DELETE /tutorslot/v1/availability/(?P<tutor_id>\d+)/exceptions/(?P<id>\d+)' => 'owner',
			'POST /tutorslot/v1/availability/(?P<tutor_id>\d+)/defaults' => 'owner',
			'GET /tutorslot/v1/bookings'                   => 'authenticated',
			'POST /tutorslot/v1/bookings'                  => 'account',
			'GET /tutorslot/v1/bookings/export'            => 'authenticated',
			'GET /tutorslot/v1/bookings/(?P<id>\d+)'       => 'owner',
			'DELETE /tutorslot/v1/bookings/(?P<id>\d+)'    => 'owner',
			'POST /tutorslot/v1/bookings/(?P<id>\d+)/reschedule' => 'owner',
			'POST /tutorslot/v1/bookings/hold'             => 'account',
			'DELETE /tutorslot/v1/bookings/hold'           => 'account',
			'POST /tutorslot/v1/bookings/(?P<id>\d+)/attendance' => 'tutor',
			'POST /tutorslot/v1/bookings/(?P<id>\d+)/notes' => 'tutor',
			'GET /tutorslot/v1/credits'                    => 'authenticated',
			'POST /tutorslot/v1/credits'                   => 'authenticated',
			'GET /tutorslot/v1/credits/balance'            => 'authenticated',
			'GET /tutorslot/v1/credits/ledger'             => 'authenticated',
			'GET /tutorslot/v1/credits/packages'           => 'public',
			'GET /tutorslot/v1/dashboard'                  => 'tutor',
			'GET /tutorslot/v1/meetings/providers'         => 'tutor',
			'GET /tutorslot/v1/meetings/google/connect'    => 'tutor',
			'GET /tutorslot/v1/meetings/google/callback'   => 'public',
			'POST /tutorslot/v1/meetings/google/disconnect' => 'tutor',
			'GET /tutorslot/v1/payments/gateways'          => 'public',
			'POST /tutorslot/v1/payments/start'            => 'owner',
			'GET /tutorslot/v1/payments/booking/(?P<booking_id>\d+)' => 'owner',
			'POST /tutorslot/v1/payments/booking/(?P<booking_id>\d+)/refund' => 'tutor',
			'POST /tutorslot/v1/payments/booking/(?P<booking_id>\d+)/cancel' => 'owner',
			'GET /tutorslot/v1/payments/bkash/callback'    => 'public',
			'POST /tutorslot/v1/payments/bkash/callback'   => 'public',
			'GET /tutorslot/v1/public/tutors/(?P<id>[\d]+)' => 'public',
			'GET /tutorslot/v1/public/tutors/by-slug/(?P<slug>[a-z0-9\-]+)' => 'public',
			'GET /tutorslot/v1/relations/children'         => 'authenticated',
			'GET /tutorslot/v1/relations/pending'          => 'authenticated',
			'POST /tutorslot/v1/relations/invite'          => 'authenticated',
			'POST /tutorslot/v1/relations/(?P<id>\d+)/confirm' => 'owner',
			'POST /tutorslot/v1/series'                    => 'account',
			'GET /tutorslot/v1/series/(?P<id>\d+)'         => 'owner',
			'DELETE /tutorslot/v1/series/(?P<id>\d+)'      => 'owner',
			'GET /tutorslot/v1/setup'                      => 'tutor',
			'POST /tutorslot/v1/setup'                     => 'tutor',
			'GET /tutorslot/v1/slots'                      => 'public',
			'GET /tutorslot/v1/tutors/(?P<tutor_id>\d+)/subjects' => 'owner',
			'POST /tutorslot/v1/tutors/(?P<tutor_id>\d+)/subjects' => 'owner',
			'PATCH /tutorslot/v1/tutors/(?P<tutor_id>\d+)/subjects/(?P<id>\d+)' => 'owner',
			'DELETE /tutorslot/v1/tutors/(?P<tutor_id>\d+)/subjects/(?P<id>\d+)' => 'owner',
			'GET /tutorslot/v1/tutors'                     => 'manager',
			'POST /tutorslot/v1/tutors'                    => 'manager',
			'GET /tutorslot/v1/tutors/(?P<id>\d+)'         => 'manager',
			'PATCH /tutorslot/v1/tutors/(?P<id>\d+)'       => 'manager',
			'POST /tutorslot/v1/tutors/(?P<id>\d+)/resend' => 'manager',
			'GET /tutorslot/v1/settings'                   => 'manager',
			'POST /tutorslot/v1/settings'                  => 'manager',
			'POST /tutorslot/v1/webhook/(?P<gateway>[a-z0-9_-]+)' => 'public',
		);
	}
}
