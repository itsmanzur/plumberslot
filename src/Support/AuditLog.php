<?php
/**
 * Append-only record of privileged actions.
 *
 * Two audiences: support, answering "who cancelled this lesson", and incident
 * response, answering "what did that account touch".
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Support;

use PlumberSlot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class AuditLog {

	/**
	 * @param array<string, mixed> $meta Extra context; must not contain secrets.
	 * @param int|null             $actor_id Verified actor when the callback has no login cookie.
	 */
	public static function record( string $action, string $object_type, int $object_id, array $meta = array(), ?int $actor_id = null ): void {
		global $wpdb;

		$meta = SecretMasker::redact( $meta );

		$wpdb->insert(
			Schema::table( Schema::AUDIT ),
			array(
				'actor_id'    => null === $actor_id ? get_current_user_id() : absint( $actor_id ),
				'action'      => $action,
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'ip_hash'     => RateLimiter::ip_hash(),
				'meta'        => wp_json_encode( $meta ),
				'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Newest audit rows for the settings Security card.
	 *
	 * @return list<object>
	 */
	public static function recent( int $limit = 50 ): array {
		global $wpdb;

		$limit = max( 1, min( 200, $limit ) );
		$table = Schema::table( Schema::AUDIT );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the schema whitelist.
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM ' . $table . ' ORDER BY id DESC LIMIT %d',
				$limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $rows;
	}

	public static function count(): int {
		global $wpdb;

		$table = Schema::table( Schema::AUDIT );

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the schema whitelist.
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		return $count;
	}
}
