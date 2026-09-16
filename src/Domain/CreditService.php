<?php
/**
 * Job packages. A customer buys ten jobs and spends them whenever.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain;

use PlumberSlot\Database\Repository\CreditRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class CreditService {

	public function __construct( private readonly CreditRepository $credits ) {}

	/**
	 * Find a package that can pay for this job.
	 */
	public function pick_usable( int $owner_id, int $technician_id, ?int $service_id = null ): ?object {
		foreach ( $this->credits->usable_for( $owner_id, $technician_id ) as $credit ) {
			if ( null !== $credit->service_id && null !== $service_id && (int) $credit->service_id !== $service_id ) {
				continue;
			}

			if ( null !== $credit->service_id && null === $service_id ) {
				continue;
			}

			return $credit;
		}

		return null;
	}

	public function spend( int $credit_id, int $booking_id ): bool|WP_Error {
		if ( ! $this->credits->consume_one( $credit_id ) ) {
			return new WP_Error(
				'plumberslot_no_credits',
				__( 'This package has no jobs left on it.', 'plumberslot' ),
				array( 'status' => 409 )
			);
		}

		AuditLog::record( 'credit.spent', 'credit', $credit_id, array( 'booking' => $booking_id ) );

		return true;
	}

	public function refund( int $credit_id, int $booking_id ): bool {
		if ( ! $this->credits->refund_one( $credit_id ) ) {
			return false;
		}

		AuditLog::record( 'credit.refunded', 'credit', $credit_id, array( 'booking' => $booking_id ) );

		return true;
	}

	/**
	 * Refund a credit on cancel when still inside the policy window.
	 *
	 * @param object{credit_id:mixed,start_utc:string,id:int} $booking Booking row.
	 */
	public function maybe_refund_on_cancel( object $booking ): bool {
		if ( empty( $booking->credit_id ) ) {
			return true;
		}

		$window = Settings::int( 'credit_refund_window_minutes', 720 );
		$start  = strtotime( (string) $booking->start_utc );

		if ( false === $start || $start - time() < $window * MINUTE_IN_SECONDS ) {
			AuditLog::record(
				'credit.refund_denied',
				'credit',
				(int) $booking->credit_id,
				array(
					'booking' => (int) $booking->id,
					'reason'  => 'outside_window',
				)
			);

			return true;
		}

		return $this->refund( (int) $booking->credit_id, (int) $booking->id );
	}

	/**
	 * Create a prepaid package. Optionally rolls unused jobs from an
	 * expiring pack into the new one when rollover is enabled.
	 *
	 * @param array{owner_id:int, technician_id:?int, service_id:?int, total:int, price_minor?:int} $args Package fields.
	 * @return object|\WP_Error
	 */
	public function purchase( array $args ) {
		$owner_id      = (int) $args['owner_id'];
		$total         = max( 1, min( 100, (int) $args['total'] ) );
		$technician_id = isset( $args['technician_id'] ) && $args['technician_id'] ? (int) $args['technician_id'] : null;
		$service       = isset( $args['service_id'] ) && $args['service_id'] ? (int) $args['service_id'] : null;

		$rollover = 0;
		if ( Settings::bool( 'credit_rollover_enabled', true ) && null !== $technician_id ) {
			$rollover = $this->credits->roll_unused_into( $owner_id, $technician_id );
			$total   += $rollover;
		}

		$days       = Settings::int( 'credit_expiry_days', 180 );
		$expires_at = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ) : null;

		$id = $this->credits->create_package(
			array(
				'owner_id'      => $owner_id,
				'technician_id' => $technician_id,
				'service_id'    => $service,
				'total'         => $total,
				'price_minor'   => (int) ( $args['price_minor'] ?? 0 ),
				'expires_at'    => $expires_at,
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error(
				'plumberslot_credit_create_failed',
				__( 'Could not create the job package.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record(
			'credit.purchased',
			'credit',
			$id,
			array(
				'total'         => $total,
				'rollover'      => $rollover,
				'technician_id' => $technician_id,
			)
		);

		$package = $this->credits->find( $id );

		return $package ? $package : new WP_Error(
			'plumberslot_credit_create_failed',
			__( 'Could not create the job package.', 'plumberslot' ),
			array( 'status' => 500 )
		);
	}

	/**
	 * Start a paid Service Plan purchase. Mirrors purchase()'s field handling,
	 * but a package with a real price is inserted 'pending' -- unusable,
	 * uncounted in a balance (CreditRepository::usable_for() filters on
	 * status) -- until PaymentService::apply_event() confirms the charge and
	 * calls activate_package(). A free (price_minor === 0) package still
	 * needs no payment step at all, so that case is delegated straight to the
	 * existing purchase() to keep its behaviour (including rollover)
	 * identical to today.
	 *
	 * expires_at is deliberately left unset here: an abandoned, never-paid
	 * checkout must not carry an expiry clock that started ticking before the
	 * package was ever usable. activate_package() computes it at activation
	 * time instead.
	 *
	 * Rollover (burning down a near-expiry pack into this one) is
	 * deliberately NOT applied on the pending/paid path -- purchase() rolls
	 * old credits over immediately because the new package is usable right
	 * away, but here the new package might never be paid for, and burning a
	 * customer's remaining credits before their payment even confirms would
	 * be a real loss if the charge then fails.
	 *
	 * @param array{owner_id:int, technician_id:?int, service_id:?int, total:int, price_minor?:int} $args Package fields.
	 * @return int|WP_Error Credit package id.
	 */
	public function create_pending( array $args ): int|WP_Error {
		$price = (int) ( $args['price_minor'] ?? 0 );

		if ( $price <= 0 ) {
			$package = $this->purchase( $args );

			return is_wp_error( $package ) ? $package : (int) $package->id;
		}

		$owner_id      = (int) $args['owner_id'];
		$total         = max( 1, min( 100, (int) $args['total'] ) );
		$technician_id = isset( $args['technician_id'] ) && $args['technician_id'] ? (int) $args['technician_id'] : null;
		$service       = isset( $args['service_id'] ) && $args['service_id'] ? (int) $args['service_id'] : null;

		$id = $this->credits->create_package(
			array(
				'owner_id'      => $owner_id,
				'technician_id' => $technician_id,
				'service_id'    => $service,
				'total'         => $total,
				'price_minor'   => $price,
				'currency'      => Settings::string( 'default_currency', 'USD' ),
				'status'        => 'pending',
				'expires_at'    => null,
			)
		);

		if ( $id <= 0 ) {
			return new WP_Error(
				'plumberslot_credit_create_failed',
				__( 'Could not create the job package.', 'plumberslot' ),
				array( 'status' => 500 )
			);
		}

		AuditLog::record(
			'credit.purchase_pending',
			'credit',
			$id,
			array(
				'total'         => $total,
				'technician_id' => $technician_id,
				'price_minor'   => $price,
			)
		);

		return $id;
	}

	/**
	 * Confirm a paid Service Plan purchase: flips 'pending' -> 'active' and
	 * computes expires_at now, since the usable window should start when the
	 * package actually becomes usable, not when checkout began. Guarded by
	 * CreditRepository::activate()'s compare-and-set, so a duplicate webhook
	 * can never re-activate (and re-roll the expiry clock on) the same
	 * package twice.
	 */
	public function activate_package( int $credit_id, string $payment_ref ): bool {
		$days       = Settings::int( 'credit_expiry_days', 180 );
		$expires_at = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + ( $days * DAY_IN_SECONDS ) ) : null;

		if ( ! $this->credits->activate( $credit_id, $payment_ref, $expires_at ) ) {
			return false;
		}

		AuditLog::record( 'credit.activated', 'credit', $credit_id, array( 'ref' => $payment_ref ) );

		return true;
	}

	/**
	 * Terminal, audit-trail state for a purchase that never got paid --
	 * mirrors the "don't delete, mark terminal" convention BookingService
	 * already uses for payment_failed/cancelled bookings.
	 */
	public function mark_purchase_failed( int $credit_id ): bool {
		if ( ! $this->credits->mark_failed( $credit_id ) ) {
			return false;
		}

		AuditLog::record( 'credit.purchase_failed', 'credit', $credit_id );

		return true;
	}

	/**
	 * @return array{total:int, used:int, remaining:int}
	 */
	public function balance( int $owner_id, int $technician_id ): array {
		$total = 0;
		$used  = 0;

		foreach ( $this->credits->usable_for( $owner_id, $technician_id ) as $credit ) {
			$total += (int) $credit->total;
			$used  += (int) $credit->used;
		}

		return array(
			'total'     => $total,
			'used'      => $used,
			'remaining' => $total - $used,
		);
	}

	/**
	 * Credit ledger entries from the audit log for the given package ids.
	 *
	 * @param list<int> $credit_ids Package ids owned by the requester.
	 * @return list<array<string, mixed>>
	 */
	public function ledger_for( array $credit_ids, int $limit = 50 ): array {
		if ( array() === $credit_ids ) {
			return array();
		}

		global $wpdb;

		$credit_ids = array_values( array_unique( array_filter( array_map( 'absint', $credit_ids ) ) ) );
		if ( array() === $credit_ids ) {
			return array();
		}

		$limit        = max( 1, min( 100, $limit ) );
		$table        = Schema::table( Schema::AUDIT );
		$placeholders = implode( ', ', array_fill( 0, count( $credit_ids ), '%d' ) );
		$params       = array_merge( $credit_ids, array( $wpdb->esc_like( 'credit.' ) . '%', $limit ) );
		$sql          = "SELECT * FROM {$table}
			 WHERE object_type = 'credit'
			   AND object_id IN ( {$placeholders} )
			   AND action LIKE %s
			 ORDER BY id DESC
			 LIMIT %d";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- identifiers are schema-whitelisted; every value has a generated placeholder.
		$prepared = $wpdb->prepare( $sql, $params );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- intentional execution of the prepared audit-table query above.
		$rows = (array) $wpdb->get_results( $prepared );

		return array_map(
			static function ( object $row ): array {
				$meta = json_decode( (string) $row->meta, true );

				return array(
					'id'         => (int) $row->id,
					'action'     => (string) $row->action,
					'credit_id'  => (int) $row->object_id,
					'actor_id'   => (int) $row->actor_id,
					'meta'       => is_array( $meta ) ? $meta : array(),
					'created_at' => (string) $row->created_at,
				);
			},
			$rows
		);
	}
}
