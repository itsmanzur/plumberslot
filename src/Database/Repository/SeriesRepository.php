<?php
/**
 * Weekly course series rows.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class SeriesRepository extends AbstractRepository {

	protected function table(): string {
		return Schema::table( Schema::SERIES );
	}

	/**
	 * @param array{technician_id:int, customer_id:int, rrule:string, total_count:int} $data Series fields.
	 */
	public function create( array $data ): int {
		$this->db->insert(
			$this->table(),
			array(
				'technician_id'    => (int) $data['technician_id'],
				'customer_id'  => (int) $data['customer_id'],
				'rrule'       => (string) $data['rrule'],
				'total_count' => (int) $data['total_count'],
				'created_at'  => $this->now(),
			),
			array( '%d', '%d', '%s', '%d', '%s' )
		);

		return (int) $this->db->insert_id;
	}

	/**
	 * Active (non-cancelled) appointment count for progress labels like Weekly 9/12.
	 */
	public function active_count( int $series_id ): int {
		$bookings = Schema::table( Schema::BOOKINGS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from whitelist.
		return (int) $this->db->get_var(
			$this->db->prepare(
				"SELECT COUNT(*) FROM {$bookings}
				 WHERE series_id = %d
				   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )",
				$series_id
			)
		);
	}

	/**
	 * @return list<object>
	 */
	public function find_for_customer( int $customer_id, int $limit = 50 ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE customer_id = %d ORDER BY id DESC LIMIT %d',
				$customer_id,
				max( 1, min( 100, $limit ) )
			)
		);
	}
}
