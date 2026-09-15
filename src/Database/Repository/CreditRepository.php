<?php
/**
 * Prepaid lesson packages.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class CreditRepository extends AbstractRepository {

	protected function table(): string {
		return Schema::table( Schema::CREDITS );
	}

	/**
	 * Usable packages for a payer, oldest expiry first so credits burn down
	 * in the order the family bought them.
	 *
	 * @return list<object>
	 */
	public function usable_for( int $owner_id, int $technician_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . '
				 WHERE owner_id = %d
				   AND ( technician_id IS NULL OR technician_id = %d )
				   AND used < total
				   AND ( expires_at IS NULL OR expires_at > %s )
				 ORDER BY expires_at IS NULL, expires_at ASC',
				$owner_id,
				$technician_id,
				$this->now()
			)
		);
	}

	/**
	 * Consume one credit. The WHERE clause carries the guard, so two
	 * simultaneous bookings can never overdraw the same package.
	 */
	public function consume_one( int $credit_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$affected = $this->db->query(
			$this->db->prepare( 'UPDATE ' . $this->table() . ' SET used = used + 1 WHERE id = %d AND used < total', $credit_id )
		);

		return 1 === $affected;
	}

	public function refund_one( int $credit_id ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return 1 === $this->db->query(
			$this->db->prepare( 'UPDATE ' . $this->table() . ' SET used = used - 1 WHERE id = %d AND used > 0', $credit_id )
		);
	}

	/**
	 * Remaining prepaid lessons held against this technician (or site-wide packs).
	 */
	public function remaining_for_technician( int $technician_id ): int {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT COALESCE( SUM( total - used ), 0 ) FROM ' . $this->table() . '
				 WHERE ( technician_id = %d OR technician_id IS NULL )
				   AND used < total
				   AND ( expires_at IS NULL OR expires_at > %s )',
				$technician_id,
				$this->now()
			)
		);
	}

	/**
	 * @param array{owner_id:int, technician_id:?int, service_id:?int, total:int, price_minor:int, expires_at:?string} $data Package fields.
	 */
	public function create_package( array $data ): int {
		$this->db->insert(
			$this->table(),
			array(
				'owner_id'    => (int) $data['owner_id'],
				'technician_id'    => $data['technician_id'] ?? null,
				'service_id'  => $data['service_id'] ?? null,
				'total'       => (int) $data['total'],
				'used'        => 0,
				'price_minor' => (int) $data['price_minor'],
				'expires_at'  => $data['expires_at'] ?? null,
				'created_at'  => $this->now(),
			)
		);

		return (int) $this->db->insert_id;
	}

	/**
	 * @return list<object>
	 */
	public function for_owner( int $owner_id, ?int $technician_id = null ): array {
		if ( null === $technician_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
			return (array) $this->db->get_results(
				$this->db->prepare(
					'SELECT * FROM ' . $this->table() . ' WHERE owner_id = %d ORDER BY id DESC',
					$owner_id
				)
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . '
				 WHERE owner_id = %d
				   AND ( technician_id IS NULL OR technician_id = %d )
				 ORDER BY id DESC',
				$owner_id,
				$technician_id
			)
		);
	}

	/**
	 * Burn remaining lessons on near-expiry packs so they can roll into a repurchase.
	 *
	 * @return int Lessons rolled over (removed from old packs).
	 */
	public function roll_unused_into( int $owner_id, int $technician_id ): int {
		$now     = $this->now();
		$horizon = gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$rows = (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . '
				 WHERE owner_id = %d
				   AND ( technician_id IS NULL OR technician_id = %d )
				   AND used < total
				   AND expires_at IS NOT NULL
				   AND expires_at > %s
				   AND expires_at <= %s',
				$owner_id,
				$technician_id,
				$now,
				$horizon
			)
		);

		$rolled = 0;

		foreach ( $rows as $row ) {
			$left = (int) $row->total - (int) $row->used;
			if ( $left <= 0 ) {
				continue;
			}

			$this->db->update(
				$this->table(),
				array( 'used' => (int) $row->total ),
				array( 'id' => (int) $row->id ),
				array( '%d' ),
				array( '%d' )
			);
			$rolled += $left;
		}

		return $rolled;
	}
}
