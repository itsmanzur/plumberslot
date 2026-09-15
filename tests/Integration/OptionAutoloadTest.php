<?php
/**
 * TutorSlot option autoload regression coverage.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use TutorSlot\Activator;
use TutorSlot\Database\Migrator;
use TutorSlot\Database\Repository\AvailabilityRepository;
use TutorSlot\Database\Repository\SubjectRepository;
use TutorSlot\Database\Repository\TutorRepository;
use TutorSlot\Database\Schema;
use TutorSlot\Frontend\AssetManager;
use TutorSlot\Frontend\DashboardRoutes;
use TutorSlot\Rest\Guard;
use TutorSlot\Rest\SetupController;
use TutorSlot\Support\Capabilities;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/** @group integration */
final class OptionAutoloadTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		Schema::create_all();
		Capabilities::add_all();
		$this->delete_tutorslot_options();
	}

	public function tear_down(): void {
		wp_set_current_user( 0 );
		$this->delete_tutorslot_options();
		parent::tear_down();
	}

	public function test_fresh_install_and_runtime_options_are_not_autoloaded(): void {
		Activator::activate();
		( new DashboardRoutes( new AssetManager() ) )->add_rewrites();

		$tutor_user_id = self::factory()->user->create(
			array( 'role' => Capabilities::ROLE_TUTOR )
		);
		wp_set_current_user( $tutor_user_id );

		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/setup' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			(string) wp_json_encode(
				array(
					'mode'             => 'solo',
					'subjects'         => array( 'Mathematics' ),
					'week'             => array(),
					'payments_enabled' => false,
					'started_at'       => time() - 30,
				)
			)
		);

		$tutors   = new TutorRepository();
		$response = ( new SetupController(
			new Guard( $tutors ),
			$tutors,
			new SubjectRepository(),
			new AvailabilityRepository()
		) )->complete( $request );

		self::assertInstanceOf( WP_REST_Response::class, $response );
		self::assertSame( 200, $response->get_status() );
		self::assertSame(
			array(
				'tutorslot_db_version',
				'tutorslot_rewrite_version',
				'tutorslot_settings',
				'tutorslot_setup_analytics',
			),
			array_keys( $this->tutorslot_option_rows() )
		);
		$this->assert_no_tutorslot_option_autoloads();
	}

	public function test_version_five_migration_disables_legacy_autoload_rows_without_changing_values(): void {
		$legacy = array(
			'tutorslot_db_version'       => 5,
			'tutorslot_rewrite_version'  => '4',
			'tutorslot_settings'         => array( 'timezone' => 'Asia/Dhaka' ),
			'tutorslot_setup_analytics'  => array( 'elapsed_seconds' => 42 ),
			'tutorslot_legacy_extension' => 'preserve-me',
		);

		foreach ( $legacy as $name => $value ) {
			self::assertTrue( add_option( $name, $value, '', true ) );
		}

		self::assertNotEmpty( array_intersect( $this->autoloaded_values(), array_column( $this->tutorslot_option_rows(), 'autoload' ) ) );

		( new Migrator() )->maybe_upgrade();

		self::assertSame( \TutorSlot\DB_VERSION, (int) get_option( 'tutorslot_db_version', 0 ) );
		self::assertSame( '4', get_option( 'tutorslot_rewrite_version' ) );
		self::assertSame( array( 'timezone' => 'Asia/Dhaka' ), get_option( 'tutorslot_settings' ) );
		self::assertSame( array( 'elapsed_seconds' => 42 ), get_option( 'tutorslot_setup_analytics' ) );
		self::assertSame( 'preserve-me', get_option( 'tutorslot_legacy_extension' ) );
		$this->assert_no_tutorslot_option_autoloads();
	}

	private function assert_no_tutorslot_option_autoloads(): void {
		$autoloaded = $this->autoloaded_values();
		foreach ( $this->tutorslot_option_rows() as $name => $row ) {
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
	private function tutorslot_option_rows(): array {
		global $wpdb;

		$like = $wpdb->esc_like( 'tutorslot_' ) . '%';
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

	private function delete_tutorslot_options(): void {
		global $wpdb;

		$like = $wpdb->esc_like( 'tutorslot_' ) . '%';
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
