<?php
/**
 * Namespace-wide REST authorization inventory.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class RestAuthorizationMatrixTest extends WP_UnitTestCase {

	private const PREFIX = '/plumberslot/v1';

	public function test_every_route_method_has_the_expected_anonymous_boundary(): void {
		wp_set_current_user( 0 );

		$expected = $this->expected_matrix();
		$actual   = $this->registered_endpoints();
		ksort( $expected );
		ksort( $actual );

		self::assertSame(
			array_keys( $expected ),
			array_keys( $actual ),
			'PlumberSlot REST inventory changed; classify every new or removed route method in the authorization matrix.'
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
					self::assertArrayNotHasKey( $id, $actual, 'Duplicate PlumberSlot REST route method: ' . $id );
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
			'GET /plumberslot/v1/audit'                      => 'manager',
			'GET /plumberslot/v1/availability/(?P<tutor_id>\d+)' => 'owner',
			'PUT /plumberslot/v1/availability/(?P<tutor_id>\d+)' => 'owner',
			'GET /plumberslot/v1/availability/(?P<tutor_id>\d+)/exceptions' => 'owner',
			'POST /plumberslot/v1/availability/(?P<tutor_id>\d+)/exceptions' => 'owner',
			'DELETE /plumberslot/v1/availability/(?P<tutor_id>\d+)/exceptions/(?P<id>\d+)' => 'owner',
			'POST /plumberslot/v1/availability/(?P<tutor_id>\d+)/defaults' => 'owner',
			'GET /plumberslot/v1/bookings'                   => 'authenticated',
			'POST /plumberslot/v1/bookings'                  => 'account',
			'GET /plumberslot/v1/bookings/export'            => 'authenticated',
			'GET /plumberslot/v1/bookings/(?P<id>\d+)'       => 'owner',
			'DELETE /plumberslot/v1/bookings/(?P<id>\d+)'    => 'owner',
			'POST /plumberslot/v1/bookings/(?P<id>\d+)/reschedule' => 'owner',
			'POST /plumberslot/v1/bookings/hold'             => 'account',
			'DELETE /plumberslot/v1/bookings/hold'           => 'account',
			'POST /plumberslot/v1/bookings/(?P<id>\d+)/attendance' => 'tutor',
			'POST /plumberslot/v1/bookings/(?P<id>\d+)/notes' => 'tutor',
			'GET /plumberslot/v1/credits'                    => 'authenticated',
			'POST /plumberslot/v1/credits'                   => 'authenticated',
			'GET /plumberslot/v1/credits/balance'            => 'authenticated',
			'GET /plumberslot/v1/credits/ledger'             => 'authenticated',
			'GET /plumberslot/v1/credits/packages'           => 'public',
			'GET /plumberslot/v1/dashboard'                  => 'tutor',
			'GET /plumberslot/v1/meetings/providers'         => 'tutor',
			'GET /plumberslot/v1/meetings/google/connect'    => 'tutor',
			'GET /plumberslot/v1/meetings/google/callback'   => 'public',
			'POST /plumberslot/v1/meetings/google/disconnect' => 'tutor',
			'GET /plumberslot/v1/payments/gateways'          => 'public',
			'POST /plumberslot/v1/payments/start'            => 'owner',
			'GET /plumberslot/v1/payments/booking/(?P<booking_id>\d+)' => 'owner',
			'POST /plumberslot/v1/payments/booking/(?P<booking_id>\d+)/refund' => 'tutor',
			'POST /plumberslot/v1/payments/booking/(?P<booking_id>\d+)/cancel' => 'owner',
			'GET /plumberslot/v1/payments/bkash/callback'    => 'public',
			'POST /plumberslot/v1/payments/bkash/callback'   => 'public',
			'GET /plumberslot/v1/public/tutors/(?P<id>[\d]+)' => 'public',
			'GET /plumberslot/v1/public/tutors/by-slug/(?P<slug>[a-z0-9\-]+)' => 'public',
			'GET /plumberslot/v1/relations/children'         => 'authenticated',
			'GET /plumberslot/v1/relations/pending'          => 'authenticated',
			'POST /plumberslot/v1/relations/invite'          => 'authenticated',
			'POST /plumberslot/v1/relations/(?P<id>\d+)/confirm' => 'owner',
			'POST /plumberslot/v1/series'                    => 'account',
			'GET /plumberslot/v1/series/(?P<id>\d+)'         => 'owner',
			'DELETE /plumberslot/v1/series/(?P<id>\d+)'      => 'owner',
			'GET /plumberslot/v1/setup'                      => 'tutor',
			'POST /plumberslot/v1/setup'                     => 'tutor',
			'GET /plumberslot/v1/slots'                      => 'public',
			'GET /plumberslot/v1/tutors/(?P<tutor_id>\d+)/subjects' => 'owner',
			'POST /plumberslot/v1/tutors/(?P<tutor_id>\d+)/subjects' => 'owner',
			'PATCH /plumberslot/v1/tutors/(?P<tutor_id>\d+)/subjects/(?P<id>\d+)' => 'owner',
			'DELETE /plumberslot/v1/tutors/(?P<tutor_id>\d+)/subjects/(?P<id>\d+)' => 'owner',
			'GET /plumberslot/v1/tutors'                     => 'manager',
			'POST /plumberslot/v1/tutors'                    => 'manager',
			'GET /plumberslot/v1/tutors/(?P<id>\d+)'         => 'manager',
			'PATCH /plumberslot/v1/tutors/(?P<id>\d+)'       => 'manager',
			'POST /plumberslot/v1/tutors/(?P<id>\d+)/resend' => 'manager',
			'GET /plumberslot/v1/settings'                   => 'manager',
			'POST /plumberslot/v1/settings'                  => 'manager',
			'POST /plumberslot/v1/webhook/(?P<gateway>[a-z0-9_-]+)' => 'public',
		);
	}
}
