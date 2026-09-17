<?php
/**
 * Table definitions.
 *
 * Custom tables, not custom post types. Booking data in wp_postmeta means one
 * row per field and an exploding JOIN on every date-range query; at ten
 * thousand bookings the calendar stops loading. Everything here is a flat row
 * with composite indexes that match the queries the slot engine actually runs.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Database;

defined( 'ABSPATH' ) || exit;

final class Schema {

	public const TECHNICIANS  = 'plumberslot_technicians';
	public const SERVICES     = 'plumberslot_services';
	public const AVAILABILITY = 'plumberslot_availability';
	public const EXCEPTIONS   = 'plumberslot_exceptions';
	public const BOOKINGS     = 'plumberslot_bookings';
	public const SERIES       = 'plumberslot_series';
	public const LOCKS        = 'plumberslot_slot_locks';
	public const CREDITS      = 'plumberslot_credits';
	public const REVIEWS      = 'plumberslot_reviews';
	public const AUDIT        = 'plumberslot_audit_log';
	public const PAYMENTS     = 'plumberslot_payments';
	public const WEBHOOKS     = 'plumberslot_webhook_events';

	/**
	 * Fully qualified table name.
	 *
	 * Always used instead of interpolating a caller-supplied string, so a table
	 * name can never come from user input.
	 */
	public static function table( string $key ): string {
		global $wpdb;

		if ( ! in_array( $key, self::all_keys(), true ) ) {
			throw new \InvalidArgumentException( 'Unknown PlumberSlot table.' );
		}

		return $wpdb->prefix . $key;
	}

	/** @return list<string> */
	public static function all_keys(): array {
		return array(
			self::TECHNICIANS,
			self::SERVICES,
			self::AVAILABILITY,
			self::EXCEPTIONS,
			self::BOOKINGS,
			self::SERIES,
			self::LOCKS,
			self::CREDITS,
			self::REVIEWS,
			self::AUDIT,
			self::PAYMENTS,
			self::WEBHOOKS,
		);
	}

	public static function create_all(): void {
		global $wpdb;

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$charset = $wpdb->get_charset_collate();

		foreach ( self::definitions( $charset ) as $sql ) {
			dbDelta( $sql );
		}
	}

	public static function drop_all(): void {
		global $wpdb;

		foreach ( array_reverse( self::all_keys() ) as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name is resolved from the internal whitelist.
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $key ) );
		}
	}

	/**
	 * @return list<string>
	 */
	private static function definitions( string $charset ): array {
		$p = self::table( ... );

		return array(

			// A technician is a WordPress user plus service metadata.
			"CREATE TABLE {$p( self::TECHNICIANS )} (
				id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id          BIGINT UNSIGNED NOT NULL,
				slug             VARCHAR(96)     NOT NULL,
				display_name     VARCHAR(191)    NOT NULL,
				timezone         VARCHAR(64)     NOT NULL DEFAULT 'UTC',
				bio              TEXT            NULL,
				hourly_rate_minor INT UNSIGNED   NOT NULL DEFAULT 0,
				currency         CHAR(3)         NOT NULL DEFAULT 'USD',
				status           VARCHAR(20)     NOT NULL DEFAULT 'active',
				created_at       DATETIME        NOT NULL,
				updated_at       DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_user (user_id),
				UNIQUE KEY uq_slug (slug),
				KEY idx_status (status)
			) {$charset};",

			// A service: category and price, the taxonomy no competitor models.
			"CREATE TABLE {$p( self::SERVICES )} (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				technician_id BIGINT UNSIGNED NOT NULL,
				name         VARCHAR(191)    NOT NULL,
				category     VARCHAR(64)     NULL,
				duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
				price_minor  INT UNSIGNED    NOT NULL DEFAULT 0,
				is_free_estimate TINYINT(1)  NOT NULL DEFAULT 0,
				is_emergency_available TINYINT(1) NOT NULL DEFAULT 0,
				deposit_type  VARCHAR(10)     NOT NULL DEFAULT 'none',
				deposit_value INT UNSIGNED    NOT NULL DEFAULT 0,
				status       VARCHAR(20)     NOT NULL DEFAULT 'active',
				sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY idx_technician (technician_id, sort_order)
			) {$charset};",

			// Recurring weekly rules. Minutes-from-midnight in the technician's own zone.
			"CREATE TABLE {$p( self::AVAILABILITY )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				technician_id BIGINT UNSIGNED NOT NULL,
				weekday    TINYINT UNSIGNED NOT NULL,
				start_min  SMALLINT UNSIGNED NOT NULL,
				end_min    SMALLINT UNSIGNED NOT NULL,
				valid_from DATE            NULL,
				valid_to   DATE            NULL,
				PRIMARY KEY (id),
				KEY idx_technician_day (technician_id, weekday)
			) {$charset};",

			// One-off overrides: holidays, extra hours.
			"CREATE TABLE {$p( self::EXCEPTIONS )} (
				id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				technician_id BIGINT UNSIGNED NOT NULL,
				on_date   DATE            NOT NULL,
				kind      VARCHAR(12)     NOT NULL DEFAULT 'closed',
				start_min SMALLINT UNSIGNED NULL,
				end_min   SMALLINT UNSIGNED NULL,
				note      VARCHAR(191)    NULL,
				PRIMARY KEY (id),
				KEY idx_technician_date (technician_id, on_date)
			) {$charset};",

			// A recurring job: several visits that live and die together.
			// interval_weeks is the cadence between qualifying weeks -- 1 is
			// every matching weekday every week (today's only behaviour before
			// this column existed), 2 biweekly, 4 ~monthly, 13 ~quarterly, 26
			// ~biannual, matching how maintenance-plan contracts are actually
			// sold. The default keeps every pre-existing and newly-created
			// weekly row byte-for-byte equivalent to the old always-weekly loop.
			"CREATE TABLE {$p( self::SERIES )} (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				technician_id BIGINT UNSIGNED NOT NULL,
				customer_id BIGINT UNSIGNED NOT NULL,
				rrule       VARCHAR(255)    NOT NULL,
				total_count SMALLINT UNSIGNED NOT NULL,
				interval_weeks SMALLINT UNSIGNED NOT NULL DEFAULT 1,
				created_at  DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_technician (technician_id)
			) {$charset};",

			// The heart of it. start_utc is always UTC; never a local wall clock.
			"CREATE TABLE {$p( self::BOOKINGS )} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				technician_id BIGINT UNSIGNED NOT NULL,
				customer_id   BIGINT UNSIGNED NOT NULL,
				service_id    BIGINT UNSIGNED NULL,
				series_id     BIGINT UNSIGNED NULL,
				series_index  SMALLINT UNSIGNED NULL,
				start_utc     DATETIME        NOT NULL,
				end_utc       DATETIME        NOT NULL,
				customer_tz   VARCHAR(64)     NOT NULL DEFAULT 'UTC',
				status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
				is_emergency  TINYINT(1)      NOT NULL DEFAULT 0,
				price_minor   INT UNSIGNED    NOT NULL DEFAULT 0,
				deposit_minor INT UNSIGNED    NOT NULL DEFAULT 0,
				balance_minor INT UNSIGNED    NOT NULL DEFAULT 0,
				currency      CHAR(3)         NOT NULL DEFAULT 'USD',
				credit_id     BIGINT UNSIGNED NULL,
				payment_ref   VARCHAR(191)    NULL,
				meeting_ref   VARCHAR(191)    NULL,
				meeting_token CHAR(64)        NULL,
				notes         TEXT            NULL,
				address_line1 VARCHAR(191)    NOT NULL DEFAULT '',
				address_line2 VARCHAR(191)    NULL,
				address_city  VARCHAR(96)     NOT NULL DEFAULT '',
				address_state VARCHAR(64)     NOT NULL DEFAULT '',
				address_zip   VARCHAR(16)     NOT NULL DEFAULT '',
				photos        LONGTEXT        NULL,
				created_at    DATETIME        NOT NULL,
				updated_at    DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_technician_start (technician_id, start_utc),
				KEY idx_technician_range (technician_id, start_utc, status),
				KEY idx_technician_end (technician_id, end_utc),
				KEY idx_customer (customer_id, start_utc),
				KEY idx_series (series_id, series_index)
			) {$charset};",

			// Short-lived reservation held while a customer is paying.
			"CREATE TABLE {$p( self::LOCKS )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				technician_id BIGINT UNSIGNED NOT NULL,
				owner_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
				start_utc  DATETIME        NOT NULL,
				token      CHAR(64)        NOT NULL,
				expires_at DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_lock (technician_id, start_utc),
				KEY idx_expiry (expires_at),
				KEY idx_owner (owner_id, expires_at)
			) {$charset};",

			// Prepaid service packages. A package with a price starts life
			// 'pending' and only becomes 'active' (spendable, counted in a
			// balance) once PaymentService::apply_event() confirms the charge --
			// the existing free/admin-issued path still inserts straight to
			// 'active' via the column default, so nothing about that flow changes.
			"CREATE TABLE {$p( self::CREDITS )} (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_id    BIGINT UNSIGNED NOT NULL,
				technician_id BIGINT UNSIGNED NULL,
				service_id  BIGINT UNSIGNED NULL,
				total       SMALLINT UNSIGNED NOT NULL,
				used        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				price_minor INT UNSIGNED    NOT NULL DEFAULT 0,
				currency    CHAR(3)         NOT NULL DEFAULT 'USD',
				status      VARCHAR(20)     NOT NULL DEFAULT 'active',
				payment_ref VARCHAR(191)    NULL,
				expires_at  DATETIME        NULL,
				reminded_at DATETIME        NULL,
				created_at  DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_owner (owner_id, expires_at),
				KEY idx_status_expiry (status, expires_at)
			) {$charset};",

			"CREATE TABLE {$p( self::REVIEWS )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
				technician_id BIGINT UNSIGNED NOT NULL,
				author_id  BIGINT UNSIGNED NOT NULL,
				rating     TINYINT UNSIGNED NOT NULL,
				body       TEXT            NULL,
				status     VARCHAR(20)     NOT NULL DEFAULT 'pending',
				created_at DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_booking (booking_id),
				KEY idx_technician (technician_id, status)
			) {$charset};",

			// Every privileged action, for support and for incident response.
			"CREATE TABLE {$p( self::AUDIT )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				actor_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
				action     VARCHAR(64)     NOT NULL,
				object_type VARCHAR(32)    NOT NULL,
				object_id  BIGINT UNSIGNED NOT NULL DEFAULT 0,
				ip_hash    CHAR(64)        NULL,
				meta       LONGTEXT        NULL,
				created_at DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_object (object_type, object_id),
				KEY idx_actor (actor_id, created_at)
			) {$charset};",

			// Payment attempts against a booking OR a Service Plan (credit)
			// purchase -- exactly one of booking_id/credit_id is ever set on a
			// given row, never both, and it is set once at creation and never
			// changed. Amount is copied from the booking/credit row at start
			// time so a client cannot underpay by editing the request.
			"CREATE TABLE {$p( self::PAYMENTS )} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id    BIGINT UNSIGNED NULL,
				credit_id     BIGINT UNSIGNED NULL,
				gateway       VARCHAR(32)     NOT NULL,
				reference     VARCHAR(191)    NULL,
				amount_minor  INT UNSIGNED    NOT NULL,
				currency      CHAR(3)         NOT NULL,
				status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
				idempotency   VARCHAR(191)    NULL,
				meta          LONGTEXT        NULL,
				created_at    DATETIME        NOT NULL,
				updated_at    DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_booking (booking_id, status),
				KEY idx_credit (credit_id, status),
				KEY idx_reference (gateway, reference),
				KEY idx_idempotency (gateway, idempotency)
			) {$charset};",

			// Durable webhook idempotency — survives cache flushes.
			"CREATE TABLE {$p( self::WEBHOOKS )} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				gateway       VARCHAR(32)     NOT NULL,
				event_key     VARCHAR(191)    NOT NULL,
				booking_id    BIGINT UNSIGNED NOT NULL DEFAULT 0,
				payload_hash  CHAR(64)        NULL,
				created_at    DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_event (gateway, event_key)
			) {$charset};",
		);
	}
}
