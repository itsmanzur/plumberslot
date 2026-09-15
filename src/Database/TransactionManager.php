<?php
/**
 * Small transaction boundary for writes that span multiple repositories.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Database;

defined( 'ABSPATH' ) || exit;

final class TransactionManager {

	private \wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	public function begin(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control statement.
		return false !== $this->db->query( 'START TRANSACTION' );
	}

	public function commit(): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control statement.
		return false !== $this->db->query( 'COMMIT' );
	}

	public function rollback(): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- transaction control statement.
		$this->db->query( 'ROLLBACK' );
	}
}
