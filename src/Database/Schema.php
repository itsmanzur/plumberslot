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

	public const TUTORS       = 'plumberslot_tutors';
	public const SUBJECTS     = 'plumberslot_subjects';
	public const AVAILABILITY = 'plumberslot_availability';
	public const EXCEPTIONS   = 'plumberslot_exceptions';
	public const BOOKINGS     = 'plumberslot_bookings';
	public const SERIES       = 'plumberslot_series';
	public const LOCKS        = 'plumberslot_slot_locks';
	public const CREDITS      = 'plumberslot_credits';
	public const RELATIONS    = 'plumberslot_relations';
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
			self::TUTORS,
			self::SUBJECTS,
			self::AVAILABILITY,
			self::EXCEPTIONS,
			self::BOOKINGS,
			self::SERIES,
			self::LOCKS,
			self::CREDITS,
			self::RELATIONS,
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

			// A tutor is a WordPress user plus teaching metadata.
			"CREATE TABLE {$p( self::TUTORS )} (
				id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				user_id          BIGINT UNSIGNED NOT NULL,
				slug             VARCHAR(96)     NOT NULL,
				display_name     VARCHAR(191)    NOT NULL,
				timezone         VARCHAR(64)     NOT NULL DEFAULT 'UTC',
				bio              TEXT            NULL,
				hourly_rate_minor INT UNSIGNED   NOT NULL DEFAULT 0,
				currency         CHAR(3)         NOT NULL DEFAULT 'USD',
				payout_share_pct TINYINT UNSIGNED NOT NULL DEFAULT 100,
				status           VARCHAR(20)     NOT NULL DEFAULT 'active',
				created_at       DATETIME        NOT NULL,
				updated_at       DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_user (user_id),
				UNIQUE KEY uq_slug (slug),
				KEY idx_status (status)
			) {$charset};",

			// Subject -> level -> curriculum, the taxonomy no competitor models.
			"CREATE TABLE {$p( self::SUBJECTS )} (
				id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tutor_id     BIGINT UNSIGNED NOT NULL,
				name         VARCHAR(191)    NOT NULL,
				level        VARCHAR(64)     NULL,
				curriculum   VARCHAR(64)     NULL,
				duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
				price_minor  INT UNSIGNED    NOT NULL DEFAULT 0,
				is_trial     TINYINT(1)      NOT NULL DEFAULT 0,
				status       VARCHAR(20)     NOT NULL DEFAULT 'active',
				sort_order   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				PRIMARY KEY (id),
				KEY idx_tutor (tutor_id, sort_order)
			) {$charset};",

			// Recurring weekly rules. Minutes-from-midnight in the tutor's own zone.
			"CREATE TABLE {$p( self::AVAILABILITY )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tutor_id   BIGINT UNSIGNED NOT NULL,
				weekday    TINYINT UNSIGNED NOT NULL,
				start_min  SMALLINT UNSIGNED NOT NULL,
				end_min    SMALLINT UNSIGNED NOT NULL,
				valid_from DATE            NULL,
				valid_to   DATE            NULL,
				PRIMARY KEY (id),
				KEY idx_tutor_day (tutor_id, weekday)
			) {$charset};",

			// One-off overrides: holidays, extra hours.
			"CREATE TABLE {$p( self::EXCEPTIONS )} (
				id        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tutor_id  BIGINT UNSIGNED NOT NULL,
				on_date   DATE            NOT NULL,
				kind      VARCHAR(12)     NOT NULL DEFAULT 'closed',
				start_min SMALLINT UNSIGNED NULL,
				end_min   SMALLINT UNSIGNED NULL,
				note      VARCHAR(191)    NULL,
				PRIMARY KEY (id),
				KEY idx_tutor_date (tutor_id, on_date)
			) {$charset};",

			// A recurring course: twelve lessons that live and die together.
			"CREATE TABLE {$p( self::SERIES )} (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tutor_id    BIGINT UNSIGNED NOT NULL,
				student_id  BIGINT UNSIGNED NOT NULL,
				rrule       VARCHAR(255)    NOT NULL,
				total_count SMALLINT UNSIGNED NOT NULL,
				created_at  DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_tutor (tutor_id)
			) {$charset};",

			// The heart of it. start_utc is always UTC; never a local wall clock.
			"CREATE TABLE {$p( self::BOOKINGS )} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tutor_id      BIGINT UNSIGNED NOT NULL,
				student_id    BIGINT UNSIGNED NOT NULL,
				parent_id     BIGINT UNSIGNED NULL,
				subject_id    BIGINT UNSIGNED NULL,
				series_id     BIGINT UNSIGNED NULL,
				series_index  SMALLINT UNSIGNED NULL,
				start_utc     DATETIME        NOT NULL,
				end_utc       DATETIME        NOT NULL,
				student_tz    VARCHAR(64)     NOT NULL DEFAULT 'UTC',
				status        VARCHAR(20)     NOT NULL DEFAULT 'pending',
				price_minor   INT UNSIGNED    NOT NULL DEFAULT 0,
				currency      CHAR(3)         NOT NULL DEFAULT 'USD',
				credit_id     BIGINT UNSIGNED NULL,
				payment_ref   VARCHAR(191)    NULL,
				meeting_ref   VARCHAR(191)    NULL,
				meeting_token CHAR(64)        NULL,
				notes         TEXT            NULL,
				created_at    DATETIME        NOT NULL,
				updated_at    DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_tutor_start (tutor_id, start_utc),
				KEY idx_tutor_range (tutor_id, start_utc, status),
				KEY idx_tutor_end (tutor_id, end_utc),
				KEY idx_student (student_id, start_utc),
				KEY idx_parent (parent_id, start_utc),
				KEY idx_series (series_id, series_index)
			) {$charset};",

			// Short-lived reservation held while a student is paying.
			"CREATE TABLE {$p( self::LOCKS )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				tutor_id   BIGINT UNSIGNED NOT NULL,
				owner_id   BIGINT UNSIGNED NOT NULL DEFAULT 0,
				start_utc  DATETIME        NOT NULL,
				token      CHAR(64)        NOT NULL,
				expires_at DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_lock (tutor_id, start_utc),
				KEY idx_expiry (expires_at),
				KEY idx_owner (owner_id, expires_at)
			) {$charset};",

			// Prepaid lesson packages.
			"CREATE TABLE {$p( self::CREDITS )} (
				id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				owner_id    BIGINT UNSIGNED NOT NULL,
				tutor_id    BIGINT UNSIGNED NULL,
				subject_id  BIGINT UNSIGNED NULL,
				total       SMALLINT UNSIGNED NOT NULL,
				used        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
				price_minor INT UNSIGNED    NOT NULL DEFAULT 0,
				expires_at  DATETIME        NULL,
				created_at  DATETIME        NOT NULL,
				PRIMARY KEY (id),
				KEY idx_owner (owner_id, expires_at)
			) {$charset};",

			// Parent -> child. The relationship every competitor is missing.
			"CREATE TABLE {$p( self::RELATIONS )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				parent_id  BIGINT UNSIGNED NOT NULL,
				student_id BIGINT UNSIGNED NOT NULL,
				relation   VARCHAR(32)     NOT NULL DEFAULT 'guardian',
				confirmed  TINYINT(1)      NOT NULL DEFAULT 0,
				created_at DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_pair (parent_id, student_id),
				KEY idx_student (student_id)
			) {$charset};",

			"CREATE TABLE {$p( self::REVIEWS )} (
				id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id BIGINT UNSIGNED NOT NULL,
				tutor_id   BIGINT UNSIGNED NOT NULL,
				author_id  BIGINT UNSIGNED NOT NULL,
				rating     TINYINT UNSIGNED NOT NULL,
				body       TEXT            NULL,
				status     VARCHAR(20)     NOT NULL DEFAULT 'pending',
				created_at DATETIME        NOT NULL,
				PRIMARY KEY (id),
				UNIQUE KEY uq_booking (booking_id),
				KEY idx_tutor (tutor_id, status)
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

			// Payment attempts against a booking. Amount is copied from the booking
			// at start time so a client cannot underpay by editing the request.
			"CREATE TABLE {$p( self::PAYMENTS )} (
				id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				booking_id    BIGINT UNSIGNED NOT NULL,
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
