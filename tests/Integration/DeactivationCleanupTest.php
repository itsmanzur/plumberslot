<?php
/**
 * Deactivation cleanup integration coverage.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use TutorSlot\Activator;
use TutorSlot\Database\Schema;
use TutorSlot\Deactivator;
use TutorSlot\Support\Capabilities;

final class DeactivationCleanupTest extends \WP_UnitTestCase {

	private const ACTION_HOOK    = 'tutorslot_deactivation_cleanup_smoke';
	private const ISOLATION_HOOK = 'shared_deactivation_isolation_smoke';
	private const FOREIGN_GROUP  = 'another-plugin';

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK, array(), 'tutorslot' );
			as_unschedule_all_actions( self::ISOLATION_HOOK, array(), 'tutorslot' );
			as_unschedule_all_actions( self::ISOLATION_HOOK, array(), self::FOREIGN_GROUP );
		}

		wp_cache_delete( 'deactivation-smoke', 'tutorslot' );
		parent::tear_down();
	}

	public function test_deactivation_clears_runtime_state_without_deleting_plugin_data(): void {
		global $wpdb;

		Activator::activate();

		$settings                           = get_option( 'tutorslot_settings' );
		$settings['buffer_minutes']         = 17;
		$settings['deactivation_test_mark'] = 'preserve';
		update_option( 'tutorslot_settings', $settings, false );
		set_transient( 'tutorslot_show_onboarding', 1, DAY_IN_SECONDS );

		$user_id      = self::factory()->user->create();
		$tutors_table = Schema::table( Schema::TUTORS );
		$inserted     = $wpdb->insert(
			$tutors_table,
			array(
				'user_id'      => $user_id,
				'slug'         => 'deactivation-smoke-' . $user_id,
				'display_name' => 'Deactivation Smoke Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		self::assertSame( 1, $inserted );
		$tutor_id = (int) $wpdb->insert_id;

		wp_cache_set( 'deactivation-smoke', 'present', 'tutorslot', HOUR_IN_SECONDS );
		self::assertTrue( $this->schedule_smoke_action() );

		Deactivator::deactivate();

		self::assertFalse( as_has_scheduled_action( self::ACTION_HOOK, array(), 'tutorslot' ) );
		self::assertFalse( wp_cache_get( 'deactivation-smoke', 'tutorslot' ) );

		self::assertSame( $settings, get_option( 'tutorslot_settings' ) );
		self::assertSame( \TutorSlot\DB_VERSION, (int) get_option( 'tutorslot_db_version', 0 ) );
		self::assertNotFalse( get_transient( 'tutorslot_show_onboarding' ) );

		foreach ( Schema::all_keys() as $key ) {
			self::assertTrue( $this->table_exists( Schema::table( $key ) ), 'Deactivation removed table: ' . $key );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted integration assertion.
		$stored_tutor = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tutors_table} WHERE id = %d", $tutor_id ), ARRAY_A );
		self::assertIsArray( $stored_tutor );
		self::assertSame( $user_id, (int) $stored_tutor['user_id'] );

		self::assertNotNull( get_role( Capabilities::ROLE_TUTOR ) );
		self::assertNotNull( get_role( Capabilities::ROLE_STUDENT ) );
		self::assertNotNull( get_role( Capabilities::ROLE_PARENT ) );
		self::assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE_ALL ) );
	}

	public function test_deactivation_unschedules_only_tutorslot_group_actions(): void {
		$tutorslot_action = $this->schedule_action( self::ISOLATION_HOOK, 'tutorslot' );
		$foreign_action   = $this->schedule_action( self::ISOLATION_HOOK, self::FOREIGN_GROUP );

		self::assertGreaterThan( 0, $tutorslot_action );
		self::assertGreaterThan( 0, $foreign_action );
		self::assertNotSame( $tutorslot_action, $foreign_action );
		self::assertNotFalse( as_has_scheduled_action( self::ISOLATION_HOOK, array(), 'tutorslot' ) );
		self::assertNotFalse( as_has_scheduled_action( self::ISOLATION_HOOK, array(), self::FOREIGN_GROUP ) );

		Deactivator::deactivate();

		self::assertFalse( as_has_scheduled_action( self::ISOLATION_HOOK, array(), 'tutorslot' ) );
		self::assertNotFalse( as_has_scheduled_action( self::ISOLATION_HOOK, array(), self::FOREIGN_GROUP ) );
	}

	private function schedule_smoke_action(): bool {
		if ( ! function_exists( 'as_schedule_single_action' )
			|| ! function_exists( 'as_has_scheduled_action' )
			|| ! function_exists( 'as_unschedule_all_actions' ) ) {
			self::fail( 'Action Scheduler must be available for the deactivation cleanup smoke.' );
		}

		$action_id = $this->schedule_action( self::ACTION_HOOK, 'tutorslot' );

		return $action_id > 0
			&& false !== as_has_scheduled_action( self::ACTION_HOOK, array(), 'tutorslot' );
	}

	private function schedule_action( string $hook, string $group ): int {
		if ( ! function_exists( 'as_schedule_single_action' )
			|| ! function_exists( 'as_unschedule_all_actions' ) ) {
			self::fail( 'Action Scheduler must be available for deactivation isolation tests.' );
		}

		as_unschedule_all_actions( $hook, array(), $group );

		return as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			$hook,
			array(),
			$group,
			true
		);
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}
}
