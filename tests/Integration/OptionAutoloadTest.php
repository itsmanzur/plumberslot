<?php
/**
 * PlumberSlot option autoload regression coverage.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Activator;
use PlumberSlot\Database\Migrator;
use PlumberSlot\Database\Repository\AvailabilityRepository;
use PlumberSlot\Database\Repository\ServiceRepository;
use PlumberSlot\Database\Repository\TechnicianRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Frontend\AssetManager;
use PlumberSlot\Frontend\DashboardRoutes;
use PlumberSlot\Rest\Guard;
use PlumberSlot\Rest\SetupController;
use PlumberSlot\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/** @group integration */
final class OptionAutoloadTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->delete_plumberslot_options();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->delete_plumberslot_options();
		parent::tear_down();
	}

	public function test_fresh_install_and_runtime_options_are_not_autoloaded(): void {
		Activator::activate();
		( new DashboardRoutes( new AssetManager() ) )->add_rewrites();

		$technician_user_id = self::factory()->user->create(
			array( 'role' => Capabilities::ROLE_TECHNICIAN )
		);
		wp_set_current_user( $technician_user_id );

		$request = new WP_REST_Request( 'POST', '/plumberslot/v1/setup' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'mode'             => 'solo',
					'services'         => array( 'Plumbing Inspection' ),
					'week'             => array(),
					'payments_enabled' => false,
					'started_at'       => time() - 30,
				)
			)
		);

		$technicians   = new TechnicianRepository();
		$response = ( new SetupController(
			new Guard( $technicians ),
			$technicians,
			new ServiceRepository(),
			new AvailabilityRepository()
		) )->complete( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'plumberslot_db_version',
				'plumberslot_rewrite_version',
				'plumberslot_settings',
				'plumberslot_setup_analytics',
			),
			array_keys( $this->plumberslot_option_rows() )
		);
		$this->assert_no_plumberslot_option_autoloads();
	}

	public function test_version_five_migration_disables_legacy_autoload_rows_without_changing_values(): void {
		$legacy = array(
			'plumberslot_db_version'       => 5,
			'plumberslot_rewrite_version'  => '4',
			'plumberslot_settings'         => array( 'timezone' => 'Asia/Dhaka' ),
			'plumberslot_setup_analytics'  => array( 'elapsed_seconds' => 42 ),
			'plumberslot_legacy_extension' => 'preserve-me',
		);

		foreach ( $legacy as $name => $value ) {
			self::assertTrue( add_option( $name, $value, '', true ) );
		}

		self::assertNotEmpty( array_intersect( $this->autoloaded_values(), array_column( $this->plumberslot_option_rows(), 'autoload' ) ) );

		( new Migrator() )->maybe_upgrade();

		self::assertSame( \PlumberSlot\DB_VERSION, (int) get_option( 'plumberslot_db_version', 0 ) );
		self::assertSame( '4', get_option( 'plumberslot_rewrite_version' ) );
		self::assertSame( array( 'timezone' => 'Asia/Dhaka' ), get_option( 'plumberslot_settings' ) );
		self::assertSame( array( 'elapsed_seconds' => 42 ), get_option( 'plumberslot_setup_analytics' ) );
		self::assertSame( 'preserve-me', get_option( 'plumberslot_legacy_extension' ) );
		$this->assert_no_plumberslot_option_autoloads();
	}

	private function assert_no_plumberslot_option_autoloads(): void {
		$autoloaded = $this->autoloaded_values();
		foreach ( $this->plumberslot_option_rows() as $name => $row ) {
			self::assertNotContains( $row['autoload'], $autoloaded, $name . ' is autoloaded.' );
		}
	}

	/**
	 * @return list<string>
	 */
	private function autoloaded_values(): array {
		if ( function_exists( 'wp_autoload_values_to_autoload' ) ) {
			return wp_autoload_values_to_autoload();
		}

		return array( 'yes' );
	}

	/**
	 * @return array<string, array{option_name:string,autoload:string}>
	 */
	private function plumberslot_option_rows(): array {
		global $wpdb;

		$like = $wpdb->esc_like( 'plumberslot_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated option-prefix regression query.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, autoload FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name ASC",
				$like
			),
			ARRAY_A
		);

		$indexed = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$indexed[ (string) $row['option_name'] ] = array(
				'option_name' => (string) $row['option_name'],
				'autoload'    => (string) $row['autoload'],
			);
		}

		return $indexed;
	}

	private function delete_plumberslot_options(): void {
		global $wpdb;

		$like = $wpdb->esc_like( 'plumberslot_' ) . '%';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- isolated option-prefix fixture cleanup.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
		wp_cache_delete( 'alloptions', 'options' );
	}
}
