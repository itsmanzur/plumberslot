<?php
/**
 * Service repository integration tests.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Schema;
use WP_UnitTestCase;

final class ServiceRepositoryTest extends WP_UnitTestCase {

	private ServiceRepository $services;

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		$this->services = new ServiceRepository();
		$this->empty_services();
	}

	public function tear_down(): void {
		$this->empty_services();

		parent::tear_down();
	}

	public function test_create_and_find_are_technician_scoped(): void {
		$service_id = $this->services->create(
			11,
			array(
				'name'             => '<b>Drain Cleaning</b>',
				'category'         => 'Drains',
				'duration_min'     => 90,
				'price_minor'      => 3500,
				'is_free_estimate' => 5,
				'sort_order'       => 3,
				'not_a_column'     => 'ignored',
			)
		);

		$service = $this->services->find_for_technician( $service_id, 11 );

		self::assertGreaterThan( 0, $service_id );
		self::assertNotNull( $service );
		self::assertSame( 'Drain Cleaning', $service->name );
		self::assertSame( 'Drains', $service->category );
		self::assertSame( 90, (int) $service->duration_min );
		self::assertSame( 3500, (int) $service->price_minor );
		self::assertSame( 1, (int) $service->is_free_estimate );
		self::assertSame( 'active', $service->status );
		self::assertSame( 3, (int) $service->sort_order );
		self::assertNull( $this->services->find_for_technician( $service_id, 12 ) );
	}

	public function test_all_for_technician_is_ordered_and_excludes_other_technicians(): void {
		$this->services->create( 21, array( 'name' => 'Faucet Repair', 'sort_order' => 2 ) );
		$this->services->create( 21, array( 'name' => 'Pipe Replacement', 'sort_order' => 1 ) );
		$this->services->create( 21, array( 'name' => 'Drain Cleaning', 'sort_order' => 1 ) );
		$this->services->create( 22, array( 'name' => 'Water Heater Install', 'sort_order' => 0 ) );

		$services = $this->services->all_for_technician( 21 );

		self::assertCount( 3, $services );
		self::assertSame(
			array( 'Drain Cleaning', 'Pipe Replacement', 'Faucet Repair' ),
			array_map( static fn ( object $service ): string => (string) $service->name, $services )
		);
		self::assertSame( array(), $this->services->all_for_technician( 23 ) );
	}

	public function test_update_and_delete_cannot_cross_technician_boundaries(): void {
		$service_id = $this->services->create(
			31,
			array(
				'name'         => 'Water Heater Repair',
				'duration_min' => 60,
			)
		);

		self::assertFalse(
			$this->services->update_for_technician(
				$service_id,
				32,
				array( 'name' => 'Stolen' )
			)
		);
		self::assertFalse( $this->services->update_for_technician( $service_id, 31, array( 'unknown' => 'ignored' ) ) );
		self::assertTrue(
			$this->services->update_for_technician(
				$service_id,
				31,
				array(
					'name'         => 'Water Heater Repair & Flush',
					'duration_min' => 45,
				)
			)
		);

		$service = $this->services->find_for_technician( $service_id, 31 );

		self::assertNotNull( $service );
		self::assertSame( 'Water Heater Repair & Flush', $service->name );
		self::assertSame( 45, (int) $service->duration_min );
		self::assertFalse( $this->services->delete_for_technician( $service_id, 32 ) );
		self::assertTrue( $this->services->delete_for_technician( $service_id, 31 ) );
		self::assertNull( $this->services->find_for_technician( $service_id, 31 ) );
	}

	private function empty_services(): void {
		global $wpdb;

		$wpdb->query( 'DELETE FROM ' . Schema::table( Schema::SERVICES ) );
	}
}
