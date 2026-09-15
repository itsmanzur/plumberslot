<?php
/**
 * Destructive uninstall smoke against a disposable WordPress database.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use PHPUnit\Framework\TestCase;
use TutorSlot\Activator;
use TutorSlot\Database\Schema;
use TutorSlot\Support\Capabilities;

final class DestructiveUninstallTest extends TestCase {

	private const ACTION_HOOK  = 'tutorslot_destructive_uninstall_smoke';
	private const ACTION_GROUP = 'tutorslot';

	public function test_opted_in_uninstall_removes_all_plugin_state(): void {
		global $wpdb;

		Activator::activate();
		$this->assert_plugin_state_exists();

		$tutors = Schema::table( Schema::TUTORS );
		$wpdb->insert(
			$tutors,
			array(
				'user_id'      => 1,
				'slug'         => 'destructive-uninstall-smoke',
				'display_name' => 'Disposable Tutor',
				'timezone'     => 'UTC',
				'status'       => 'active',
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'updated_at'   => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		update_option(
			'tutorslot_settings',
			array( 'delete_data_on_uninstall' => true ),
			false
		);
		self::assertTrue(
			(bool) get_option( 'tutorslot_settings', array() )['delete_data_on_uninstall']
		);
		set_transient( 'tutorslot_show_onboarding', 1, DAY_IN_SECONDS );
		wp_cache_set( 'uninstall-smoke', 'present', 'tutorslot', HOUR_IN_SECONDS );

		self::assertTrue( $this->schedule_smoke_action() );
		self::assertNotFalse( get_transient( 'tutorslot_show_onboarding' ) );
		self::assertSame( 'present', wp_cache_get( 'uninstall-smoke', 'tutorslot' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress core uninstall contract.
			define( 'WP_UNINSTALL_PLUGIN', true );
		}

		$uninstall_result = require dirname( __DIR__, 2 ) . '/uninstall.php';
		self::assertSame( 1, $uninstall_result, 'Destructive uninstall returned before cleanup.' );

		self::assertFalse( get_option( 'tutorslot_settings', false ) );
		self::assertFalse( get_option( 'tutorslot_db_version', false ) );

		foreach ( Schema::all_keys() as $key ) {
			$table = Schema::table( $key );

			self::assertFalse(
				$this->table_exists( $table ),
				'Uninstall left table ' . $table . '; database error: ' . $wpdb->last_error
			);
		}

		self::assertFalse( get_transient( 'tutorslot_show_onboarding' ) );
		self::assertFalse( wp_cache_get( 'uninstall-smoke', 'tutorslot' ) );

		self::assertNull( get_role( Capabilities::ROLE_TUTOR ) );
		self::assertNull( get_role( Capabilities::ROLE_STUDENT ) );
		self::assertNull( get_role( Capabilities::ROLE_PARENT ) );

		$administrator = get_role( 'administrator' );
		self::assertNotNull( $administrator );

		foreach ( Capabilities::all() as $capability ) {
			self::assertFalse( $administrator->has_cap( $capability ) );
		}

		self::assertFalse( as_has_scheduled_action( self::ACTION_HOOK, array(), self::ACTION_GROUP ) );
	}

	private function assert_plugin_state_exists(): void {
		foreach ( Schema::all_keys() as $key ) {
			self::assertTrue(
				$this->table_exists( Schema::table( $key ) ),
				'Activation did not create table ' . Schema::table( $key )
			);
		}

		self::assertIsArray( get_option( 'tutorslot_settings', false ) );
		self::assertSame( \TutorSlot\DB_VERSION, (int) get_option( 'tutorslot_db_version', 0 ) );
		self::assertNotNull( get_role( Capabilities::ROLE_TUTOR ) );
		self::assertNotNull( get_role( Capabilities::ROLE_STUDENT ) );
		self::assertNotNull( get_role( Capabilities::ROLE_PARENT ) );
	}

	private function table_exists( string $table ): bool {
		global $wpdb;

		return $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}

	private function schedule_smoke_action(): bool {
		if ( ! function_exists( 'as_schedule_single_action' )
			|| ! function_exists( 'as_has_scheduled_action' )
			|| ! function_exists( 'as_unschedule_all_actions' ) ) {
			self::fail( 'Action Scheduler must be available for the destructive uninstall smoke.' );
		}

		// A previously interrupted run may leave this unique smoke action behind.
		as_unschedule_all_actions( self::ACTION_HOOK, array(), self::ACTION_GROUP );

		$action_id = as_schedule_single_action(
			time() + HOUR_IN_SECONDS,
			self::ACTION_HOOK,
			array(),
			self::ACTION_GROUP,
			true
		);

		return $action_id > 0
			&& false !== as_has_scheduled_action( self::ACTION_HOOK, array(), self::ACTION_GROUP );
	}
}
