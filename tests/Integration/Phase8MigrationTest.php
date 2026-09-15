<?php
/**
 * Phase 8 plugin schema migration integration tests.
 *
 * @package PlumberSlot\Tests
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Activator;
use PlumberSlot\Database\Migrator;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\Capabilities;
use PlumberSlot\Tests\Integration\Fixtures\PreviousSchemaFixture;

require_once __DIR__ . '/Fixtures/PreviousSchemaFixture.php';

/** @group integration */
final class Phase8MigrationTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		Schema::drop_all();
		Schema::create_all();
		update_option( 'plumberslot_db_version', \PlumberSlot\DB_VERSION, false );

		parent::tear_down();
	}

	public function test_version_four_fixture_matches_the_pre_payment_schema_contract(): void {
		PreviousSchemaFixture::install();

		$this->assertSame( 4, (int) get_option( 'plumberslot_db_version', 0 ) );
		$this->assertCount( 10, PreviousSchemaFixture::table_keys() );

		foreach ( PreviousSchemaFixture::table_keys() as $key ) {
			$this->assertTrue( $this->table_exists( Schema::table( $key ) ), 'Missing v4 fixture table: ' . $key );
		}

		$this->assertFalse( $this->table_exists( Schema::table( Schema::PAYMENTS ) ), 'A v4 fixture must not contain the v5 payments table.' );
		$this->assertFalse( $this->table_exists( Schema::table( Schema::WEBHOOKS ) ), 'A v4 fixture must not contain the v5 webhook table.' );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::SERVICES ), 'status' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::LOCKS ), 'owner_id' ) );
		$this->assertTrue( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'idx_technician_start' ) );
		$this->assertFalse( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'idx_technician_end' ) );
		$this->assertFalse( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'uq_slot' ) );
	}

	public function test_version_four_migrates_to_the_current_schema(): void {
		PreviousSchemaFixture::install();

		( new Migrator() )->maybe_upgrade();

		$this->assertSame( \PlumberSlot\DB_VERSION, (int) get_option( 'plumberslot_db_version', 0 ) );
		$this->assertSame( 7, \PlumberSlot\DB_VERSION );

		foreach ( Schema::all_keys() as $key ) {
			$this->assertTrue( $this->table_exists( Schema::table( $key ) ), 'Migration did not create table: ' . $key );
		}

		$payments = Schema::table( Schema::PAYMENTS );
		$this->assertTrue( $this->column_exists( $payments, 'booking_id' ) );
		$this->assertTrue( $this->column_exists( $payments, 'reference' ) );
		$this->assertTrue( $this->column_exists( $payments, 'idempotency' ) );
		$this->assertTrue( $this->index_exists( $payments, 'idx_booking' ) );
		$this->assertTrue( $this->index_exists( $payments, 'idx_reference' ) );
		$this->assertTrue( $this->index_exists( $payments, 'idx_idempotency' ) );

		$webhooks = Schema::table( Schema::WEBHOOKS );
		$this->assertTrue( $this->column_exists( $webhooks, 'event_key' ) );
		$this->assertTrue( $this->column_exists( $webhooks, 'payload_hash' ) );
		$this->assertTrue( $this->index_exists( $webhooks, 'uq_event' ) );
		$this->assertTrue( $this->index_is_unique( $webhooks, 'uq_event' ) );

		$this->assertTrue( $this->column_exists( Schema::table( Schema::SERVICES ), 'status' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::LOCKS ), 'owner_id' ) );
		$this->assertTrue( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'idx_technician_start' ) );
		$this->assertTrue( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'idx_technician_end' ) );
		$this->assertFalse( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'uq_slot' ) );

		// Step 7 adds the structured service-address columns to a v4 bookings table
		// that predates them.
		$bookings = Schema::table( Schema::BOOKINGS );
		$this->assertTrue( $this->column_exists( $bookings, 'address_line1' ) );
		$this->assertTrue( $this->column_exists( $bookings, 'address_line2' ) );
		$this->assertTrue( $this->column_exists( $bookings, 'address_city' ) );
		$this->assertTrue( $this->column_exists( $bookings, 'address_state' ) );
		$this->assertTrue( $this->column_exists( $bookings, 'address_zip' ) );
	}

	public function test_current_migration_is_an_idempotent_no_op(): void {
		PreviousSchemaFixture::install();
		$migrator = new Migrator();
		$migrator->maybe_upgrade();

		$before  = $this->schema_fingerprint();
		$queries = array();
		$capture = static function ( string $query ) use ( &$queries ): string {
			$normalized = strtoupper( ltrim( $query ) );
			if ( str_starts_with( $normalized, 'CREATE TABLE' )
				|| str_starts_with( $normalized, 'CREATE TEMPORARY TABLE' )
				|| str_starts_with( $normalized, 'ALTER TABLE' )
				|| str_starts_with( $normalized, 'DROP TABLE' )
				|| str_starts_with( $normalized, 'DROP TEMPORARY TABLE' ) ) {
				$queries[] = $query;
			}

			return $query;
		};

		add_filter( 'query', $capture );
		try {
			$migrator->maybe_upgrade();
		} finally {
			remove_filter( 'query', $capture );
		}

		$this->assertSame( \PlumberSlot\DB_VERSION, (int) get_option( 'plumberslot_db_version', 0 ) );
		$this->assertSame( array(), $queries, 'A current-version migration rerun must not execute DDL.' );
		$this->assertSame( $before, $this->schema_fingerprint() );
	}

	public function test_migration_preserves_existing_booking_settings_and_related_data(): void {
		PreviousSchemaFixture::install();
		$settings = array(
			'auto_confirm'             => false,
			'allow_customer_reschedule' => true,
			'hold_window_minutes'      => 17,
			'default_currency'         => 'BDT',
			'notification_preferences' => array(
				'email_24h' => true,
				'sms_1h'    => false,
			),
		);
		update_option( 'plumberslot_settings', $settings, false );
		$this->seed_version_four_data();

		$before = $this->persisted_data_snapshot();

		( new Migrator() )->maybe_upgrade();

		$this->assertSame( \PlumberSlot\DB_VERSION, (int) get_option( 'plumberslot_db_version', 0 ) );
		$this->assertSame( $settings, get_option( 'plumberslot_settings' ) );
		$this->assertSame( $before, $this->persisted_data_snapshot() );
	}

	public function test_fresh_activation_creates_the_current_empty_schema_and_defaults(): void {
		PreviousSchemaFixture::remove_all();
		delete_option( 'plumberslot_settings' );
		delete_option( 'plumberslot_db_version' );
		delete_transient( 'plumberslot_show_onboarding' );
		Capabilities::remove_all();

		foreach ( Schema::all_keys() as $key ) {
			$this->assertFalse( $this->table_exists( Schema::table( $key ) ), 'Fresh-install precondition failed: ' . $key );
		}

		Activator::activate();

		$this->assertSame( \PlumberSlot\DB_VERSION, (int) get_option( 'plumberslot_db_version', 0 ) );
		$this->assertSame(
			array(
				'timezone'                 => wp_timezone_string(),
				'slot_granularity_minutes' => 30,
				'default_lesson_minutes'   => 60,
				'buffer_minutes'           => 10,
				'lead_time_minutes'        => 240,
				'hold_window_minutes'      => 10,
				'slot_cache_ttl'           => 900,
				'auto_confirm'             => true,
				'delete_data_on_uninstall' => false,
			),
			get_option( 'plumberslot_settings' )
		);
		$this->assertNotFalse( get_transient( 'plumberslot_show_onboarding' ) );

		foreach ( Schema::all_keys() as $key ) {
			$table = Schema::table( $key );
			$this->assertTrue( $this->table_exists( $table ), 'Activation did not create table: ' . $key );
			$this->assertSame( 0, $this->table_row_count( $table ), 'Fresh table is not empty: ' . $key );
		}

		$this->assertTrue( $this->column_exists( Schema::table( Schema::BOOKINGS ), 'meeting_token' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::BOOKINGS ), 'address_line1' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::BOOKINGS ), 'address_city' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::BOOKINGS ), 'address_state' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::BOOKINGS ), 'address_zip' ) );
		$this->assertTrue( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'idx_technician_start' ) );
		$this->assertTrue( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'idx_technician_end' ) );
		$this->assertFalse( $this->index_exists( Schema::table( Schema::BOOKINGS ), 'uq_slot' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::SERVICES ), 'status' ) );
		$this->assertTrue( $this->column_exists( Schema::table( Schema::LOCKS ), 'owner_id' ) );
		$this->assertTrue( $this->index_exists( Schema::table( Schema::PAYMENTS ), 'idx_idempotency' ) );
		$this->assertTrue( $this->index_is_unique( Schema::table( Schema::WEBHOOKS ), 'uq_event' ) );

		$this->assertNotNull( get_role( Capabilities::ROLE_TECHNICIAN ) );
		$this->assertNotNull( get_role( Capabilities::ROLE_CUSTOMER ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_ALL ) );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		$previous = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table is selected from the schema whitelist.
		$result = $wpdb->query( "SELECT 1 FROM {$table} LIMIT 0" );
		$wpdb->suppress_errors( $previous );

		return false !== $result;
	}

	private function column_exists( string $table, string $column ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table is selected from the schema whitelist.
		return null !== $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM {$table} LIKE %s", $column ) );
	}

	private function index_exists( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table is selected from the schema whitelist.
		return null !== $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ) );
	}

	private function index_is_unique( string $table, string $index ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixture table is selected from the schema whitelist.
		$row = $wpdb->get_row( $wpdb->prepare( "SHOW INDEX FROM {$table} WHERE Key_name = %s", $index ), ARRAY_A );

		return is_array( $row ) && 0 === (int) $row['Non_unique'];
	}

	private function table_row_count( string $table ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted integration assertion.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/** @return array<string, array{columns:list<array<string,mixed>>,indexes:list<array<string,mixed>>}> */
	private function schema_fingerprint(): array {
		global $wpdb;

		$fingerprint = array();
		foreach ( Schema::all_keys() as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted integration introspection.
			$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$table}", ARRAY_A );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted integration introspection.
			$indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );

			$fingerprint[ $key ] = array(
				'columns' => is_array( $columns ) ? $columns : array(),
				'indexes' => is_array( $indexes ) ? $indexes : array(),
			);
		}

		return $fingerprint;
	}

	private function seed_version_four_data(): void {
		global $wpdb;

		$created = '2026-08-01 09:15:00';
		$updated = '2026-08-02 10:30:00';

		$wpdb->insert(
			Schema::table( Schema::TECHNICIANS ),
			array(
				'id'                => 41,
				'user_id'           => 501,
				'slug'              => 'migration-technician',
				'display_name'      => 'Migration Technician',
				'timezone'          => 'Asia/Dhaka',
				'bio'               => 'Preserve this technician profile.',
				'hourly_rate_minor' => 275000,
				'currency'          => 'BDT',
				'payout_share_pct'  => 85,
				'status'            => 'active',
				'created_at'        => $created,
				'updated_at'        => $updated,
			)
		);
		$wpdb->insert(
			Schema::table( Schema::SERVICES ),
			array(
				'id'           => 71,
				'technician_id'     => 41,
				'name'         => 'Advanced Drain Inspection',
				'level'        => 'Commercial',
				'curriculum'   => 'Camera Inspection',
				'duration_min' => 90,
				'price_minor'  => 275000,
				'is_free_estimate'     => 0,
				'status'       => 'active',
				'sort_order'   => 3,
			)
		);
		$wpdb->insert(
			Schema::table( Schema::CREDITS ),
			array(
				'id'          => 81,
				'owner_id'    => 601,
				'technician_id'    => 41,
				'service_id'  => 71,
				'total'       => 8,
				'used'        => 3,
				'price_minor' => 1800000,
				'expires_at'  => '2027-08-01 00:00:00',
				'created_at'  => $created,
			)
		);
		$wpdb->insert(
			Schema::table( Schema::BOOKINGS ),
			array(
				'id'            => 91,
				'technician_id'      => 41,
				'customer_id'    => 601,
				'service_id'    => 71,
				'series_id'     => null,
				'series_index'  => null,
				'start_utc'     => '2026-09-10 08:00:00',
				'end_utc'       => '2026-09-10 09:30:00',
				'customer_tz'    => 'Asia/Dhaka',
				'status'        => 'confirmed',
				'price_minor'   => 275000,
				'currency'      => 'BDT',
				'credit_id'     => 81,
				'payment_ref'   => 'legacy-payment-ref',
				'meeting_ref'   => 'zoom|legacy-meeting-ref',
				'meeting_token' => str_repeat( 'a', 64 ),
				'notes'         => 'Keep gate code and access instructions.',
				'created_at'    => $created,
				'updated_at'    => $updated,
			)
		);
		$wpdb->insert(
			Schema::table( Schema::LOCKS ),
			array(
				'id'         => 101,
				'technician_id'   => 41,
				'owner_id'   => 601,
				'start_utc'  => '2026-09-11 08:00:00',
				'token'      => str_repeat( 'b', 64 ),
				'expires_at' => '2026-09-11 07:55:00',
			)
		);
	}

	/** @return array<string, list<array<string, mixed>>> */
	private function persisted_data_snapshot(): array {
		global $wpdb;

		$snapshot = array();
		foreach ( array( Schema::TECHNICIANS, Schema::SERVICES, Schema::CREDITS, Schema::BOOKINGS, Schema::LOCKS ) as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted migration snapshot.
			$rows             = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A );
			$snapshot[ $key ] = is_array( $rows ) ? $rows : array();
		}

		return $snapshot;
	}
}
