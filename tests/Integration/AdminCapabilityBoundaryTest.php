<?php
/**
 * Capability boundaries for admin REST surfaces.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Support\Capabilities;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @group integration
 */
final class AdminCapabilityBoundaryTest extends WP_UnitTestCase {

	public function test_technician_cannot_list_technicians_or_settings(): void {
		$user_id = self::factory()->user->create( array( 'role' => Capabilities::ROLE_TECHNICIAN ) );
		wp_set_current_user( $user_id );

		$technicians = rest_do_request( new WP_REST_Request( 'GET', '/plumberslot/v1/technicians' ) );
		$this->assertSame( 404, $technicians->get_status() );

		$settings = rest_do_request( new WP_REST_Request( 'GET', '/plumberslot/v1/settings' ) );
		$this->assertTrue( in_array( $settings->get_status(), array( 401, 403, 404 ), true ) );
	}

	public function test_manager_can_list_technicians(): void {
		$user_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/plumberslot/v1/technicians' );
		$request->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$response = rest_do_request( $request );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertArrayHasKey( 'technicians', $data );
	}
}
