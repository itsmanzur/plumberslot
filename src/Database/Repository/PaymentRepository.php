<?php
/**
 * Payment attempts and durable webhook idempotency.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database\Repository;

use PlumberSlot\Database\Schema;

defined( 'ABSPATH' ) || exit;

final class PaymentRepository extends AbstractRepository {

	protected function table(): string {
		return Schema::table( Schema::PAYMENTS );
	}

	private function webhooks_table(): string {
		return Schema::table( Schema::WEBHOOKS );
	}

	/**
	 * Exactly one of booking_id/credit_id is ever passed -- the other is left
	 * out (and stored NULL) by the caller. start() only ever sets booking_id;
	 * start_credit_purchase() only ever sets credit_id.
	 *
	 * @param array{booking_id?:?int, credit_id?:?int, gateway:string, amount_minor:int, currency:string, reference?:?string, status?:string, idempotency?:?string, meta?:?array<string, mixed>} $data Payment fields.
	 */
	public function create( array $data ): int {
		$this->db->insert(
			$this->table(),
			array(
				'booking_id'   => ! empty( $data['booking_id'] ) ? (int) $data['booking_id'] : null,
				'credit_id'    => ! empty( $data['credit_id'] ) ? (int) $data['credit_id'] : null,
				'gateway'      => (string) $data['gateway'],
				'reference'    => $data['reference'] ?? null,
				'amount_minor' => (int) $data['amount_minor'],
				'currency'     => strtoupper( (string) $data['currency'] ),
				'status'       => (string) ( $data['status'] ?? 'pending' ),
				'idempotency'  => $data['idempotency'] ?? null,
				'meta'         => isset( $data['meta'] ) ? wp_json_encode( $data['meta'] ) : null,
				'created_at'   => $this->now(),
				'updated_at'   => $this->now(),
			)
		);

		return (int) $this->db->insert_id;
	}

	/** @return object{id:int,gateway:string,status:string,reference:?string,amount_minor:int,credit_id:?int}|null */
	public function find_by_reference( string $gateway, string $reference ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE gateway = %s AND reference = %s ORDER BY id DESC LIMIT 1',
				$gateway,
				$reference
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Find a payment after a paid booking has been rescheduled. The payment row
	 * stays with the original booking while the provider reference is carried
	 * to the replacement booking.
	 *
	 * @return object{id:int,gateway:string,status:string,reference:?string,amount_minor:int,credit_id:?int}|null
	 */
	public function find_latest_by_reference( string $reference ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE reference = %s ORDER BY id DESC LIMIT 1',
				$reference
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Latest payment row for a booking.
	 *
	 * @return object{id:int,gateway:string,status:string,reference:?string,amount_minor:int,credit_id:?int}|null
	 */
	public function latest_for_booking( int $booking_id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE booking_id = %d ORDER BY id DESC LIMIT 1',
				$booking_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * Latest payment row for a credit (Service Plan) purchase. The credit_id
	 * column is set once at row-creation time by start_credit_purchase() and
	 * never changes, unlike a gateway's own reference (bKash in particular
	 * rotates from paymentID to trxID between start and completion) -- so
	 * this is the reliable way to find "the" payment for a given credit id,
	 * where matching on reference alone would not be.
	 *
	 * @return object{id:int,gateway:string,status:string,reference:?string,amount_minor:int,credit_id:?int}|null
	 */
	public function latest_for_credit( int $credit_id ): ?object {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$row = $this->db->get_row(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE credit_id = %d ORDER BY id DESC LIMIT 1',
				$credit_id
			)
		);

		return $row ? $row : null;
	}

	/**
	 * @return list<object>
	 */
	public function for_booking( int $booking_id ): array {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		return (array) $this->db->get_results(
			$this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE booking_id = %d ORDER BY id DESC',
				$booking_id
			)
		);
	}

	public function update_status( int $id, string $status, ?string $reference = null ): bool {
		$data = array(
			'status'     => $status,
			'updated_at' => $this->now(),
		);

		if ( null !== $reference && '' !== $reference ) {
			$data['reference'] = $reference;
		}

		return (bool) $this->db->update( $this->table(), $data, array( 'id' => $id ) );
	}

	/**
	 * Compare-and-set a payment status for scheduled lifecycle transitions.
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

	public function set_reference( int $id, string $reference ): bool {
		return (bool) $this->db->update(
			$this->table(),
			array(
				'reference'  => $reference,
				'updated_at' => $this->now(),
			),
			array( 'id' => $id )
		);
	}

	/**
	 * Claim a webhook event. Returns false when the event was already processed.
	 */
	public function claim_webhook( string $gateway, string $event_key, int $booking_id, string $payload_hash = '' ): bool {
		$inserted = $this->db->insert(
			$this->webhooks_table(),
			array(
				'gateway'      => $gateway,
				'event_key'    => $event_key,
				'booking_id'   => $booking_id,
				'payload_hash' => $payload_hash ? $payload_hash : null,
				'created_at'   => $this->now(),
			)
		);

		return false !== $inserted;
	}

	public function webhook_seen( string $gateway, string $event_key ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table from whitelist.
		$id = $this->db->get_var(
			$this->db->prepare(
				'SELECT id FROM ' . $this->webhooks_table() . ' WHERE gateway = %s AND event_key = %s',
				$gateway,
				$event_key
			)
		);

		return (bool) $id;
	}
}
