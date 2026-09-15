<?php
/**
 * Booking persistence.
 *
 * @package TutorSlot
 */

declare( strict_types = 1 );

namespace TutorSlot\Database\Repository;

use TutorSlot\Database\Schema;
use TutorSlot\Domain\Contract\BookingOccupancySource;
use TutorSlot\Domain\Contract\MeetingBookingStore;

defined( 'ABSPATH' ) || exit;

final class BookingRepository extends AbstractRepository implements BookingOccupancySource, MeetingBookingStore {

	protected function table(): string {
		return Schema::table( Schema::BOOKINGS );
	}

	/**
	 * Insert a booking under the tutor lock. Recheck the active range here so a
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
				(int) $data['tutor_id'],
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
	 * Bookings that occupy any part of the window. Hits idx_tutor_end so old
	 * booking history is skipped before the overlap and status checks.
	 *
	 * @return list<object>
	 */
	public function find_in_range( int $tutor_id, string $from_utc, string $to_utc ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT id, start_utc, end_utc, status, student_id, subject_id
				 FROM ' . $this->table() . "
				 WHERE tutor_id = %d
				   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )
				   AND start_utc < %s
				   AND end_utc   > %s
				 ORDER BY start_utc ASC",
				$tutor_id,
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
	public function has_overlap( int $tutor_id, string $start_utc, string $end_utc, int $exclude_booking_id = 0 ): bool {
		$sql    = 'SELECT id FROM ' . $this->table() . "
			 WHERE tutor_id = %d
			   AND status NOT IN ( 'cancelled', 'refunded', 'moved', 'payment_expired' )
			   AND start_utc < %s
			   AND end_utc > %s";
		$params = array( $tutor_id, $end_utc, $start_utc );

		if ( $exclude_booking_id > 0 ) {
			$sql     .= ' AND id <> %d';
			$params[] = $exclude_booking_id;
		}

		$sql .= ' LIMIT 1';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared below with whitelist table.
		return (bool) $this->db->get_var( $this->db->prepare( $sql, $params ) );
	}

	/**
	 * Serialize all booking writes for one tutor across PHP processes.
	 */
	public function acquire_tutor_lock( int $tutor_id, int $timeout_seconds = 5 ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- MySQL advisory lock is the cross-process mutex for overlapping intervals.
		return 1 === (int) $this->db->get_var(
			$this->db->prepare(
				'SELECT GET_LOCK( %s, %d )',
				$this->tutor_lock_name( $tutor_id ),
				$timeout_seconds
			)
		);
	}

	public function release_tutor_lock( int $tutor_id ): void {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- releases only the internally named tutor mutex.
		$this->db->get_var(
			$this->db->prepare( 'SELECT RELEASE_LOCK( %s )', $this->tutor_lock_name( $tutor_id ) )
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
				'SELECT * FROM ' . $this->table() . ' WHERE student_id = %d OR parent_id = %d ORDER BY start_utc DESC',
				$user_id,
				$user_id
			)
		);
	}

	/**
	 * Bookings where the user is the student.
	 *
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function find_for_student( int $student_id, array $filters = array() ): array {
		return $this->paginated_list( 'student', $student_id, $filters );
	}

	/**
	 * Bookings for confirmed children of this parent.
	 *
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function find_for_family( int $parent_id, array $filters = array() ): array {
		return $this->paginated_list( 'family', $parent_id, $filters );
	}

	/**
	 * Bookings taught by this tutor.
	 *
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	public function find_for_tutor( int $tutor_id, array $filters = array() ): array {
		return $this->paginated_list( 'tutor', $tutor_id, $filters );
	}

	/**
	 * Scope controls every SQL identifier and fragment in this query builder.
	 * Callers may supply values only; arbitrary clauses are never accepted.
	 *
	 * @param 'student'|'family'|'tutor'                                                      $scope   Ownership scope.
	 * @param array{from_utc?:?string,to_utc?:?string,status?:?string,page?:int,per_page?:int} $filters Filters.
	 * @return array{items:list<object>,total:int,page:int,per_page:int}
	 */
	private function paginated_list( string $scope, int $owner_id, array $filters ): array {
		$page     = max( 1, (int) ( $filters['page'] ?? 1 ) );
		$per_page = min( 100, max( 1, (int) ( $filters['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$table    = $this->table();

		if ( 'family' === $scope ) {
			$relations    = Schema::table( Schema::RELATIONS );
			$owner_clause = 'r.parent_id = %d AND r.confirmed = 1';
			$from_sql     = $table . ' b INNER JOIN ' . $relations . ' r ON r.student_id = b.student_id';
			$alias        = 'b.';
		} elseif ( 'tutor' === $scope ) {
			$owner_clause = 'tutor_id = %d';
			$from_sql     = $table;
			$alias        = '';
		} elseif ( 'student' === $scope ) {
			$owner_clause = 'student_id = %d';
			$from_sql     = $table;
			$alias        = '';
		} else {
			throw new \InvalidArgumentException( 'Unknown booking list scope.' );
		}

		$where  = array( $owner_clause );
		$params = array( $owner_id );
		$from   = $filters['from_utc'] ?? null;
		$to     = $filters['to_utc'] ?? null;
		$status = $filters['status'] ?? null;

		if ( is_string( $from ) && '' !== $from ) {
			$where[]  = $alias . 'start_utc >= %s';
			$params[] = $from;
		}

		if ( is_string( $to ) && '' !== $to ) {
			$where[]  = $alias . 'start_utc < %s';
			$params[] = $to;
		}

		if ( is_string( $status ) && '' !== $status ) {
			$where[]  = $alias . 'status = %s';
			$params[] = $status;
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = 'SELECT COUNT(*) FROM ' . $from_sql . ' WHERE ' . $where_sql;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared below with whitelist tables.
		$total = (int) $this->db->get_var( $this->db->prepare( $count_sql, $params ) );

		$select_sql = 'SELECT ' . ( 'family' === $scope ? 'b.*' : '*' ) . ' FROM ' . $from_sql
			. ' WHERE ' . $where_sql
			. ' ORDER BY ' . $alias . 'start_utc ASC'
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
	 * Whether the student already used a free-trial subject on any tutor.
	 */
	public function student_has_used_trial( int $student_id ): bool {
		$subjects = Schema::table( Schema::SUBJECTS );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- tables from whitelist.
		return (bool) $this->db->get_var(
			$this->db->prepare(
				'SELECT b.id FROM ' . $this->table() . ' b
				 INNER JOIN ' . $subjects . " s ON s.id = b.subject_id
				 WHERE b.student_id = %d
				   AND s.is_trial = 1
				   AND b.status NOT IN ( 'cancelled', 'refunded', 'payment_expired' )
				 LIMIT 1",
				$student_id
			)
		);
	}

	private function tutor_lock_name( int $tutor_id ): string {
		return 'tutorslot:' . md5( $this->db->prefix . '|' . $tutor_id );
	}
}
