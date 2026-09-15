<?php
/**
 * Frozen database-version 4 fixture for migration integration tests.
 *
 * @package PlumberSlot\Tests
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration\Fixtures;

use RuntimeException;
use PlumberSlot\Database\Schema;

final class PreviousSchemaFixture {

	public const VERSION = 4;

	/** @return list<string> */
	public static function table_keys(): array {
		return array(
			Schema::TUTORS,
			Schema::SUBJECTS,
			Schema::AVAILABILITY,
			Schema::EXCEPTIONS,
			Schema::BOOKINGS,
			Schema::SERIES,
			Schema::LOCKS,
			Schema::CREDITS,
			Schema::RELATIONS,
			Schema::REVIEWS,
			Schema::AUDIT,
		);
	}

	public static function install(): void {
		global $wpdb;

		self::remove_all();

		$payments_table = Schema::table( Schema::PAYMENTS );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- isolated fixture sanity check against persistent tables.
		if ( $payments_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $payments_table ) ) ) ) {
			throw new RuntimeException( 'PlumberSlot v4 fixture could not remove the current payments table.' );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local test fixture, never a URL.
		$sql = file_get_contents( __DIR__ . '/schema-v4.sql' );
		if ( false === $sql ) {
			throw new RuntimeException( 'PlumberSlot v4 schema fixture could not be read.' );
		}

		$sql        = str_replace( '{{prefix}}', $wpdb->prefix, $sql );
		$sql        = str_replace( '{{charset}}', $wpdb->get_charset_collate(), $sql );
		$statements = preg_split( '/;\s*(?:\R|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY );

		foreach ( is_array( $statements ) ? $statements : array() as $statement ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- frozen integration-test DDL with an internal prefix placeholder.
			if ( false === $wpdb->query( trim( $statement ) ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- diagnostic exception in an isolated test fixture.
				throw new RuntimeException( 'PlumberSlot v4 schema fixture failed: ' . $wpdb->last_error );
			}
		}

		update_option( 'plumberslot_db_version', self::VERSION, false );
	}

	public static function remove_all(): void {
		global $wpdb;

		// wp-phpunit may place temporary plugin tables over persistent test tables.
		// Remove both layers explicitly so the frozen snapshot starts clean.
		foreach ( array_reverse( Schema::all_keys() ) as $key ) {
			$table = Schema::table( $key );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted isolated test table.
			$wpdb->query( "DROP TEMPORARY TABLE IF EXISTS {$table}" );
			// The leading marker intentionally bypasses WP_UnitTestCase's DROP TABLE
			// to DROP TEMPORARY TABLE query rewrite in the isolated test database.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- schema-whitelisted isolated test table.
			$dropped = $wpdb->query( "/* plumberslot-v4-fixture */ DROP TABLE IF EXISTS {$table}" );
			if ( false === $dropped ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- diagnostic exception in an isolated test fixture.
				throw new RuntimeException( 'PlumberSlot v4 fixture could not drop ' . $table . ': ' . $wpdb->last_error );
			}
		}
	}
}
