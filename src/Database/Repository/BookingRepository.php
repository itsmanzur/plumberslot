<?php
/**
 * Booking persistence.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;
use PlumberSlot\Domain\Contract\BookingOccupancySource;
use PlumberSlot\Domain\Contract\MeetingBookingStore;

defined( 'ABSPATH' ) || exit;

final class BookingRepository extends AbstractRepository implements BookingOccupancySource, MeetingBookingStore {

	protected function table(): string {
		return Schema::table( Schema::BOOKINGS );
	}

	/**
	 * Insert a booking under the technician lock. Recheck the active range here so a
	 * direct caller cannot bypass the service-level overlap guard.
	 *
	 * @param array<string, mixed> $data               Column values.
	 * @param int                  $exclude_booking_id Existing row being moved.
	 * @return int|null Booking id, or null when the write failed.
	 */
	public function insert_unique( array $data, int $exclude_booking_id = 0 ): ?int {
		$status = (string) ( $data['status'] ?? '' );

		if ( ! in_array( $status, array( 'cancelled', 'refunded', 'moved', 'payment_expired' ), true )
			&& $this->has_overlap(
				(int) $data['technician_id'],
				(string) $data['start_utc'],
				(string) $data['end_utc'],
				$exclude_booking_id
			) ) {
			return null;
		}

		$data['created_at'] = $this->now();
		$data['updated_at'] = $this->now();

		$inserted = $this->db->insert( $this->table(), $data );

		return false === $inserted ? null : (int) $this->db->insert_id;
	}

	/**
	 * Bookings that occupy any part of the window. Hits idx_technician_end so old
	 * booking history is skipped before the overlap and status checks.
	 *
	 * @return list<object>
	 */
	public function find_in_range( int $technician_id, string $from_utc, string $to_utc ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT id, start_utc, end_utc, status, customer_id, service_id
				 FROM ' . $this->table() . "
				 WHERE technician_id = %d
				   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )
				   AND start_utc < %s
				   AND end_utc   > %s
				 ORDER BY start_utc ASC",
				$technician_id,
				$to_utc,
				$from_utc
			)
		);
	}

	/**
	 * Does any active booking overlap the half-open interval [start, end)?
	 *
	 * @param int $exclude_booking_id Booking to ignore (the row being moved).
	 */
	public function has_overlap( int $technician_id, string $start_utc, string $end_utc, int $exclude_booking_id = 0 ): bool {
		$sql    = 'SELECT id FROM ' . $this->table() . "
			 WHERE technician_id = %d
			   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )
			   AND start_utc < %s
			   AND end_utc > %s";
		$params = array( $technician_id, $end_utc, $start_utc );

		if ( $exclude_booking_id > 0 ) {
			$sql     .= ' AND id <> %d';
			$params[] = $exclude_booking_id;
		}

		$sql .= ' LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared below with whitelist table.
		return (bool) $this->db->get_var( $this->db->prepare( $sql, $params ) );
	}

	/**
	 * Serialize all booking writes for one technician across PHP processes.
	 */
	public function acquire_technician_lock( int $technician_id, int $timeout_seconds = 5 ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL advisory lock is the cross-process mutex for overlapping intervals.
		return 1 === (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT GET_LOCK( %s, %d )',
				$this->technician_lock_name( $technician_id ),
				$timeout_seconds
			)
		);
	}

	public function release_technician_lock( int $technician_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases only the internally named technician mutex.
		$this->db->get_var(
			$this->db->prepare( 'SELECT RELEASE_LOCK( %s )', $this->technician_lock_name( $technician_id ) )
		);
	}

	public function update_status( int $id, string $status ): bool {
		return (bool) $this->db->update(
			$this->table(),
			array(
				'status'     => $status,
				'updated_at' => $this->now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Compare-and-set a booking status inside lifecycle transactions.
	 */
	public function update_status_if_current( int $id, string $expected, string $status ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table comes from the schema whitelist and values are prepared.
		return 1 === $this->db->query(
			$this->db->prepare(
				'UPDATE ' . $this->table() . ' SET status = %s, updated_at = %s WHERE id = %d AND status = %s',
				$status,
				$this->now(),
				$id,
				$expected
			)
		);
	}

	public function update_notes( int $id, string $notes ): bool {
		return (bool) $this->db->update(
			$this->table(),
			array(
				'notes'      => $notes,
				'updated_at' => $this->now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function set_payment_ref( int $id, string $payment_ref ): bool {
		return (bool) $this->db->update(
			$this->table(),
			array(
				'payment_ref' => $payment_ref,
				'updated_at'  => $this->now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Store the provider-supplied meeting reference and a fresh random token
	 * used to sign the join URL. Both are set in one round-trip.
	 */
	public function set_meeting_ref( int $id, string $meeting_ref ): bool {
		$token = bin2hex( random_bytes( 32 ) );

		return (bool) $this->db->update(
			$this->table(),
			array(
				'meeting_ref'   => $meeting_ref,
				'meeting_token' => $token,
				'updated_at'    => $this->now(),
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	public function clear_meeting_reference( int $id, string $expected_reference ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return 1 === $this->db->query(
			$this->db->prepare(
				'UPDATE ' . $this->table() . ' SET meeting_ref = NULL, updated_at = %s WHERE id = %d AND meeting_ref = %s',
				$this->now(),
				$id,
				$expected_reference
			)
		);
	}

	/**
	 * @return list<object>
	 */
	public function find_for_series( int $series_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE series_id = %d ORDER BY series_index ASC', $series_id )
		);
	}

	/**
	 * Bookings that need a reminder in the given window. Used by the scheduler.
	 *
	 * @return list<object>
	 */
	public function find_needing_reminder( string $from_utc, string $to_utc ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . " WHERE status = 'confirmed' AND start_utc BETWEEN %s AND %s",
				$from_utc,
				$to_utc
			)
		);
	}

	/**
	 * Everything belonging to one person, for the privacy exporter and eraser.
	 *
	 * @return list<object>
	 */
	public function find_for_user( int $user_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE customer_id = %d ORDER BY start_utc DESC',
				$user_id
			)
		);
	}

	/**
	 * Bookings where the user is the customer.
	 *
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function find_for_customer( int $customer_id, array $filters = array() ): array {
		return $this->paginated_list( 'customer', $customer_id, $filters );
	}

	/**
	 * Bookings taught by this technician.
	 *
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function find_for_technician( int $technician_id, array $filters = array() ): array {
		return $this->paginated_list( 'technician', $technician_id, $filters );
	}

	/**
	 * Scope controls every SQL identifier and fragment in this query builder.
	 * Callers may supply values only; arbitrary clauses are never accepted.
	 *
	 * @param 'customer'|'technician'                                                      $scope   Ownership scope.
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	private function paginated_list( string $scope, int $owner_id, array $filters ): array {
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		if ( 'technician' === $scope ) {
			$owner_clause = 'technician_id = %d';
		} elseif ( 'customer' === $scope ) {
			$owner_clause = 'customer_id = %d';
		} else {
			throw new \InvalidArgumentException( 'Unknown booking list scope.' );
		}

		$from_sql = $table;

		$where  = array( $owner_clause );
		$params = array( $owner_id );
		$from   = $filters['from_utc'] ?? null;
		$to     = $filters['to_utc'] ?? null;
		$status = $filters['status'] ?? null;

		if ( is_string( $from ) && '' !== $from ) {
			$where[]  = 'start_utc >= %s';
			$params[] = $from;
		}

		if ( is_string( $to ) && '' !== $to ) {
			$where[]  = 'start_utc < %s';
			$params[] = $to;
		}

		if ( is_string( $status ) && '' !== $status ) {
			$where[]  = 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . $from_sql . ' WHERE ' . $where_sql;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared below with whitelist tables.
		$total = (int) $this->db->get_var( $this->db->prepare( $count_sql, $params ) );

		$select_sql = 'SELECT * FROM ' . $from_sql
			. ' WHERE ' . $where_sql
			. ' ORDER BY start_utc ASC'
			. ' LIMIT %d OFFSET %d';

		$list_params   = $params;
		$list_params[] = $per_page;
		$list_params[] = $offset;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared below with whitelist tables.
		$items = (array) $this->db->get_results( $this->db->prepare( $select_sql, $list_params ) );

		return array(
			'items'    => $items,
			'total'    => $total,
			'page'     => $page,
			'per_page' => $per_page,
		);
	}

	/**
	 * Whether the customer already used a free-estimate service from any technician.
	 */
	public function customer_has_used_free_estimate( int $customer_id ): bool {
		$services = Schema::table( Schema::SERVICES );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from whitelist.
		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT b.id FROM ' . $this->table() . ' b
				 INNER JOIN ' . $services . " s ON s.id = b.service_id
				 WHERE b.customer_id = %d
				   AND s.is_free_estimate = 1
				   AND b.status NOT IN ( 'cancelled', 'refunded', 'payment_expired' )
				 LIMIT 1",
				$customer_id
			)
		);
	}

	private function technician_lock_name( int $technician_id ): string {
		return 'plumberslot:' . md5( $this->db->prefix . '|' . $technician_id );
	}
}
