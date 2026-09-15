<?php
/**
 * Shared query plumbing. Every statement goes through $wpdb->prepare().
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Database\Repository;

defined( 'ABSPATH' ) || exit;

abstract class AbstractRepository {

	protected \wpdb $db;

	public function __construct() {
		global $wpdb;
		$this->db = $wpdb;
	}

	/**
	 * The table this repository owns.
	 */
	abstract protected function table(): string;

	/**
	 * @return object|null
	 */
	public function find( int $id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row( $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id ) );

		return $row;
	}

	public function delete( int $id ): bool {
		return (bool) $this->db->delete( $this->table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Current UTC timestamp in MySQL DATETIME form.
	 */
	protected function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}
