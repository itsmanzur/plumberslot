CREATE TABLE {{prefix}}plumberslot_technicians (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 user_id BIGINT UNSIGNED NOT NULL,
 slug VARCHAR(96) NOT NULL,
 display_name VARCHAR(191) NOT NULL,
 timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
 bio TEXT NULL,
 hourly_rate_minor INT UNSIGNED NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'USD',
 payout_share_pct TINYINT UNSIGNED NOT NULL DEFAULT 100,
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_user (user_id),
 UNIQUE KEY uq_slug (slug),
 KEY idx_status (status)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_services (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 technician_id BIGINT UNSIGNED NOT NULL,
 name VARCHAR(191) NOT NULL,
 level VARCHAR(64) NULL,
 curriculum VARCHAR(64) NULL,
 duration_min SMALLINT UNSIGNED NOT NULL DEFAULT 60,
 price_minor INT UNSIGNED NOT NULL DEFAULT 0,
 is_free_estimate TINYINT(1) NOT NULL DEFAULT 0,
 status VARCHAR(20) NOT NULL DEFAULT 'active',
 sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 PRIMARY KEY (id),
 KEY idx_technician (technician_id, sort_order)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_availability (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 technician_id BIGINT UNSIGNED NOT NULL,
 weekday TINYINT UNSIGNED NOT NULL,
 start_min SMALLINT UNSIGNED NOT NULL,
 end_min SMALLINT UNSIGNED NOT NULL,
 valid_from DATE NULL,
 valid_to DATE NULL,
 PRIMARY KEY (id),
 KEY idx_technician_day (technician_id, weekday)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_exceptions (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 technician_id BIGINT UNSIGNED NOT NULL,
 on_date DATE NOT NULL,
 kind VARCHAR(12) NOT NULL DEFAULT 'closed',
 start_min SMALLINT UNSIGNED NULL,
 end_min SMALLINT UNSIGNED NULL,
 note VARCHAR(191) NULL,
 PRIMARY KEY (id),
 KEY idx_technician_date (technician_id, on_date)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_series (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 technician_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 rrule VARCHAR(255) NOT NULL,
 total_count SMALLINT UNSIGNED NOT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_technician (technician_id)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_bookings (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 technician_id BIGINT UNSIGNED NOT NULL,
 customer_id BIGINT UNSIGNED NOT NULL,
 service_id BIGINT UNSIGNED NULL,
 series_id BIGINT UNSIGNED NULL,
 series_index SMALLINT UNSIGNED NULL,
 start_utc DATETIME NOT NULL,
 end_utc DATETIME NOT NULL,
 customer_tz VARCHAR(64) NOT NULL DEFAULT 'UTC',
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 price_minor INT UNSIGNED NOT NULL DEFAULT 0,
 currency CHAR(3) NOT NULL DEFAULT 'USD',
 credit_id BIGINT UNSIGNED NULL,
 payment_ref VARCHAR(191) NULL,
 meeting_ref VARCHAR(191) NULL,
 meeting_token CHAR(64) NULL,
 notes TEXT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_technician_start (technician_id, start_utc),
 KEY idx_technician_range (technician_id, start_utc, status),
 KEY idx_customer (customer_id, start_utc),
 KEY idx_series (series_id, series_index)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_slot_locks (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 technician_id BIGINT UNSIGNED NOT NULL,
 owner_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 start_utc DATETIME NOT NULL,
 token CHAR(64) NOT NULL,
 expires_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_lock (technician_id, start_utc),
 KEY idx_expiry (expires_at),
 KEY idx_owner (owner_id, expires_at)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_credits (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 owner_id BIGINT UNSIGNED NOT NULL,
 technician_id BIGINT UNSIGNED NULL,
 service_id BIGINT UNSIGNED NULL,
 total SMALLINT UNSIGNED NOT NULL,
 used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
 price_minor INT UNSIGNED NOT NULL DEFAULT 0,
 expires_at DATETIME NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_owner (owner_id, expires_at)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_reviews (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 booking_id BIGINT UNSIGNED NOT NULL,
 technician_id BIGINT UNSIGNED NOT NULL,
 author_id BIGINT UNSIGNED NOT NULL,
 rating TINYINT UNSIGNED NOT NULL,
 body TEXT NULL,
 status VARCHAR(20) NOT NULL DEFAULT 'pending',
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 UNIQUE KEY uq_booking (booking_id),
 KEY idx_technician (technician_id, status)
) {{charset}};

CREATE TABLE {{prefix}}plumberslot_audit_log (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
 actor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 action VARCHAR(64) NOT NULL,
 object_type VARCHAR(32) NOT NULL,
 object_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
 ip_hash CHAR(64) NULL,
 meta LONGTEXT NULL,
 created_at DATETIME NOT NULL,
 PRIMARY KEY (id),
 KEY idx_object (object_type, object_id),
 KEY idx_actor (actor_id, created_at)
) {{charset}};
