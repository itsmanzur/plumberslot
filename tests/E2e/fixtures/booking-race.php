<?php
/**
 * Seed, inspect, and remove the live booking-race fixture.
 *
 * This intentionally uses a direct database connection. Loading wp-load.php
 * from a generic CLI process is unreliable for Local's per-site MySQL port,
 * while the browser requests still exercise the complete WordPress/REST stack.
 */

declare( strict_types = 1 );

if ( 'cli' !== PHP_SAPI ) {
	fwrite( STDERR, "This fixture may only run from the command line.\n" );
	exit( 1 );
}

mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

const FIXTURE_TUTOR_LOGIN = 'plumberslot_e2e_farhana';
const FIXTURE_ALICE_LOGIN = 'plumberslot_e2e_alice';
const FIXTURE_BOB_LOGIN   = 'plumberslot_e2e_bob';
const FIXTURE_PARENT_LOGIN = 'plumberslot_e2e_parent';
const FIXTURE_TUTOR_SLUG  = 'plumberslot-e2e-farhana';
const FIXTURE_PAGE_SLUG   = 'plumberslot-e2e-booking-race';
const FIXTURE_PASSWORD    = 'PlumberSlot-E2E-only-2026!';

/**
 * Read an environment variable with a local-development fallback.
 */
function fixture_env( string $name, string $fallback ): string {
	$value = getenv( $name );

	return is_string( $value ) && '' !== $value ? $value : $fallback;
}

/**
 * Keep concurrent Playwright projects in separate reserved fixture rows.
 */
function fixture_key(): string {
	$key = fixture_env( 'PLUMBERSLOT_E2E_FIXTURE_KEY', '' );

	if ( 1 !== preg_match( '/^[a-z0-9_\-]*$/', $key ) ) {
		throw new RuntimeException( 'Unsafe fixture key.' );
	}

	return $key;
}

function fixture_login( string $base ): string {
	$key = str_replace( '-', '_', fixture_key() );

	return '' === $key ? $base : $base . '_' . $key;
}

function fixture_slug( string $base ): string {
	$key = str_replace( '_', '-', fixture_key() );

	return '' === $key ? $base : $base . '-' . $key;
}

function fixture_backup_option_name(): string {
	return 'plumberslot_e2e_backup_' . str_replace( '-', '_', fixture_key() );
}

/** Preserve global options changed by the onboarding endpoint. */
function fixture_backup_onboarding_options( mysqli $db, string $prefix ): void {
	$options = $prefix . 'options';
	$names   = array( 'plumberslot_settings', 'plumberslot_setup_analytics' );
	$backup  = array();

	foreach ( $names as $name ) {
		$stmt = $db->prepare( "SELECT option_value FROM {$options} WHERE option_name = ? LIMIT 1" );
		$stmt->bind_param( 's', $name );
		$stmt->execute();
		$row = $stmt->get_result()->fetch_assoc();
		$stmt->close();
		$backup[ $name ] = array(
			'exists' => is_array( $row ),
			'value'  => is_array( $row ) ? (string) $row['option_value'] : '',
		);
	}

	$name  = fixture_backup_option_name();
	$value = json_encode( $backup, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );
	$stmt  = $db->prepare(
		"INSERT INTO {$options} (option_name, option_value, autoload)
		 VALUES (?, ?, 'no')
		 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'"
	);
	$stmt->bind_param( 'ss', $name, $value );
	$stmt->execute();
	$stmt->close();
}

/** Restore global options after onboarding coverage. */
function fixture_restore_onboarding_options( mysqli $db, string $prefix ): void {
	$options = $prefix . 'options';
	$name    = fixture_backup_option_name();
	$stmt    = $db->prepare( "SELECT option_value FROM {$options} WHERE option_name = ? LIMIT 1" );
	$stmt->bind_param( 's', $name );
	$stmt->execute();
	$row = $stmt->get_result()->fetch_assoc();
	$stmt->close();

	if ( is_array( $row ) ) {
		$backup = json_decode( (string) $row['option_value'], true, 512, JSON_THROW_ON_ERROR );
		foreach ( $backup as $option_name => $state ) {
			if ( ! empty( $state['exists'] ) ) {
				$value = (string) $state['value'];
				$stmt  = $db->prepare(
					"INSERT INTO {$options} (option_name, option_value, autoload)
					 VALUES (?, ?, 'no')
					 ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)"
				);
				$stmt->bind_param( 'ss', $option_name, $value );
			} else {
				$stmt = $db->prepare( "DELETE FROM {$options} WHERE option_name = ?" );
				$stmt->bind_param( 's', $option_name );
			}
			$stmt->execute();
			$stmt->close();
		}
	}

	$stmt = $db->prepare( "DELETE FROM {$options} WHERE option_name = ?" );
	$stmt->bind_param( 's', $name );
	$stmt->execute();
	$stmt->close();
}

/**
 * Emit one machine-readable response.
 *
 * @param array<string, mixed> $payload Response data.
 */
function fixture_response( array $payload ): never {
	echo wp_json_encode_compatible( $payload ) . PHP_EOL;
	exit( 0 );
}

/**
 * JSON encoding without booting WordPress.
 *
 * @param array<string, mixed> $payload Response data.
 */
function wp_json_encode_compatible( array $payload ): string {
	$json = json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR );

	return $json;
}

/**
 * Generate a WordPress 6.8+ compatible password hash.
 */
function fixture_password_hash( string $password ): string {
	$prehash = base64_encode( hash_hmac( 'sha384', trim( $password ), 'wp-sha384', true ) );

	return '$wp' . password_hash( $prehash, PASSWORD_BCRYPT );
}

/**
 * Delete only rows carrying the fixture's reserved identifiers.
 */
function fixture_cleanup( mysqli $db, string $prefix ): void {
	$tutors      = $prefix . 'plumberslot_tutors';
	$subjects    = $prefix . 'plumberslot_subjects';
	$bookings    = $prefix . 'plumberslot_bookings';
	$locks       = $prefix . 'plumberslot_slot_locks';
	$availability = $prefix . 'plumberslot_availability';
	$audit       = $prefix . 'plumberslot_audit_log';
	$credits     = $prefix . 'plumberslot_credits';
	$relations   = $prefix . 'plumberslot_relations';
	$posts       = $prefix . 'posts';
	$postmeta    = $prefix . 'postmeta';
	$users       = $prefix . 'users';
	$usermeta    = $prefix . 'usermeta';

	$stmt = $db->prepare( "SELECT id FROM {$tutors} WHERE slug = ?" );
	$slug = fixture_slug( FIXTURE_TUTOR_SLUG );
	$stmt->bind_param( 's', $slug );
	$stmt->execute();
	$tutor_id = (int) ( $stmt->get_result()->fetch_assoc()['id'] ?? 0 );
	$stmt->close();

	if ( $tutor_id > 0 ) {
		$object_type = 'tutor';
		$stmt        = $db->prepare( "DELETE FROM {$audit} WHERE object_type = ? AND object_id = ?" );
		$stmt->bind_param( 'si', $object_type, $tutor_id );
		$stmt->execute();
		$stmt->close();

		$stmt = $db->prepare( "SELECT id FROM {$bookings} WHERE tutor_id = ?" );
		$stmt->bind_param( 'i', $tutor_id );
		$stmt->execute();
		$booking_ids = array_column( $stmt->get_result()->fetch_all( MYSQLI_ASSOC ), 'id' );
		$stmt->close();

		foreach ( $booking_ids as $booking_id ) {
			$id          = (int) $booking_id;
			$object_type = 'booking';
			$stmt        = $db->prepare( "DELETE FROM {$audit} WHERE object_type = ? AND object_id = ?" );
			$stmt->bind_param( 'si', $object_type, $id );
			$stmt->execute();
			$stmt->close();

			try {
				$actions = $prefix . 'actionscheduler_actions';
				$groups  = $prefix . 'actionscheduler_groups';
				$logs    = $prefix . 'actionscheduler_logs';
				$hook    = 'plumberslot_send_reminder';
				$group   = 'plumberslot';
				$needle  = '[' . $id . ',%';
				$stmt    = $db->prepare(
					"SELECT a.action_id
					 FROM {$actions} a
					 INNER JOIN {$groups} g ON g.group_id = a.group_id
					 WHERE a.hook = ? AND g.slug = ? AND a.args LIKE ?"
				);
				$stmt->bind_param( 'sss', $hook, $group, $needle );
				$stmt->execute();
				$action_ids = array_column( $stmt->get_result()->fetch_all( MYSQLI_ASSOC ), 'action_id' );
				$stmt->close();

				foreach ( $action_ids as $action_id ) {
					$id   = (int) $action_id;
					$stmt = $db->prepare( "DELETE FROM {$logs} WHERE action_id = ?" );
					$stmt->bind_param( 'i', $id );
					$stmt->execute();
					$stmt->close();

					$stmt = $db->prepare( "DELETE FROM {$actions} WHERE action_id = ?" );
					$stmt->bind_param( 'i', $id );
					$stmt->execute();
					$stmt->close();
				}
			} catch ( mysqli_sql_exception ) {
				// Action Scheduler is optional and may not have created tables.
			}
		}

		foreach ( array( $bookings, $locks, $availability, $subjects ) as $table ) {
			$stmt = $db->prepare( "DELETE FROM {$table} WHERE tutor_id = ?" );
			$stmt->bind_param( 'i', $tutor_id );
			$stmt->execute();
			$stmt->close();
		}
	}

	$stmt = $db->prepare( "DELETE FROM {$tutors} WHERE slug = ?" );
	$stmt->bind_param( 's', $slug );
	$stmt->execute();
	$stmt->close();

	$page_slug = fixture_slug( FIXTURE_PAGE_SLUG );
	$stmt      = $db->prepare( "SELECT ID FROM {$posts} WHERE post_name = ? AND post_type = 'page'" );
	$stmt->bind_param( 's', $page_slug );
	$stmt->execute();
	$page_ids = array_column( $stmt->get_result()->fetch_all( MYSQLI_ASSOC ), 'ID' );
	$stmt->close();

	foreach ( $page_ids as $page_id ) {
		$id   = (int) $page_id;
		$stmt = $db->prepare( "DELETE FROM {$postmeta} WHERE post_id = ?" );
		$stmt->bind_param( 'i', $id );
		$stmt->execute();
		$stmt->close();
	}

	$stmt = $db->prepare( "DELETE FROM {$posts} WHERE post_name = ? AND post_type = 'page'" );
	$stmt->bind_param( 's', $page_slug );
	$stmt->execute();
	$stmt->close();

	$logins = array(
		fixture_login( FIXTURE_TUTOR_LOGIN ),
		fixture_login( FIXTURE_ALICE_LOGIN ),
		fixture_login( FIXTURE_BOB_LOGIN ),
		fixture_login( FIXTURE_PARENT_LOGIN ),
	);

	foreach ( $logins as $login ) {
		$stmt = $db->prepare( "SELECT ID FROM {$users} WHERE user_login = ?" );
		$stmt->bind_param( 's', $login );
		$stmt->execute();
		$user_id = (int) ( $stmt->get_result()->fetch_assoc()['ID'] ?? 0 );
		$stmt->close();

		if ( $user_id > 0 ) {
			$stmt = $db->prepare( "DELETE FROM {$relations} WHERE parent_id = ? OR student_id = ?" );
			$stmt->bind_param( 'ii', $user_id, $user_id );
			$stmt->execute();
			$stmt->close();

			$stmt = $db->prepare( "DELETE FROM {$credits} WHERE owner_id = ?" );
			$stmt->bind_param( 'i', $user_id );
			$stmt->execute();
			$stmt->close();

			$stmt = $db->prepare( "DELETE FROM {$usermeta} WHERE user_id = ?" );
			$stmt->bind_param( 'i', $user_id );
			$stmt->execute();
			$stmt->close();
		}

		$stmt = $db->prepare( "DELETE FROM {$users} WHERE user_login = ?" );
		$stmt->bind_param( 's', $login );
		$stmt->execute();
		$stmt->close();
	}

	fixture_restore_onboarding_options( $db, $prefix );
}

/**
 * Insert one fixture user and its student/tutor role.
 */
function fixture_user( mysqli $db, string $prefix, string $login, string $role ): int {
	$table       = $prefix . 'users';
	$meta_table  = $prefix . 'usermeta';
	$password    = fixture_password_hash( FIXTURE_PASSWORD );
	$nicename    = str_replace( '_', '-', $login );
	$email       = $login . '@example.test';
	$registered  = gmdate( 'Y-m-d H:i:s' );
	$display     = ucwords( str_replace( array( 'plumberslot_e2e_', '_' ), array( '', ' ' ), $login ) );

	$stmt = $db->prepare(
		"INSERT INTO {$table}
			(user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_activation_key, user_status, display_name)
		 VALUES (?, ?, ?, ?, '', ?, '', 0, ?)"
	);
	$stmt->bind_param( 'ssssss', $login, $password, $nicename, $email, $registered, $display );
	$stmt->execute();
	$user_id = (int) $db->insert_id;
	$stmt->close();

	$capability_key   = $prefix . 'capabilities';
	$capability_value = serialize( array( $role => true ) );
	$stmt             = $db->prepare( "INSERT INTO {$meta_table} (user_id, meta_key, meta_value) VALUES (?, ?, ?)" );
	$stmt->bind_param( 'iss', $user_id, $capability_key, $capability_value );
	$stmt->execute();
	$stmt->close();

	$level_key = $prefix . 'user_level';
	$level     = '0';
	$stmt      = $db->prepare( "INSERT INTO {$meta_table} (user_id, meta_key, meta_value) VALUES (?, ?, ?)" );
	$stmt->bind_param( 'iss', $user_id, $level_key, $level );
	$stmt->execute();
	$stmt->close();

	return $user_id;
}

$action        = $argv[1] ?? '';
$payment_state = $argv[2] ?? '';
$prefix        = fixture_env( 'PLUMBERSLOT_E2E_DB_PREFIX', 'wp_' );

if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $prefix ) ) {
	fwrite( STDERR, "Unsafe database prefix.\n" );
	exit( 1 );
}

try {
	$db = new mysqli(
		fixture_env( 'PLUMBERSLOT_E2E_DB_HOST', '127.0.0.1' ),
		fixture_env( 'PLUMBERSLOT_E2E_DB_USER', 'root' ),
		fixture_env( 'PLUMBERSLOT_E2E_DB_PASSWORD', 'root' ),
		fixture_env( 'PLUMBERSLOT_E2E_DB_NAME', 'local' ),
		(int) fixture_env( 'PLUMBERSLOT_E2E_DB_PORT', '10156' )
	);
	$db->set_charset( 'utf8mb4' );

	if ( 'cleanup' === $action ) {
		fixture_cleanup( $db, $prefix );
		fixture_response( array( 'cleaned' => true ) );
	}

	if ( 'inspect' === $action ) {
		$table = $prefix . 'plumberslot_bookings';
		$stmt  = $db->prepare(
			"SELECT COUNT(*) AS total
			 FROM {$table} b
			 INNER JOIN {$prefix}plumberslot_tutors t ON t.id = b.tutor_id
			 WHERE t.slug = ?"
		);
		$slug = fixture_slug( FIXTURE_TUTOR_SLUG );
		$stmt->bind_param( 's', $slug );
		$stmt->execute();
		$count = (int) $stmt->get_result()->fetch_assoc()['total'];
		$stmt->close();

		$stmt = $db->prepare(
			"SELECT b.id, b.student_id, b.subject_id, b.status, b.start_utc,
			        b.price_minor, b.payment_ref
			 FROM {$table} b
			 INNER JOIN {$prefix}plumberslot_tutors t ON t.id = b.tutor_id
			 WHERE t.slug = ?
			 ORDER BY b.id DESC
			 LIMIT 1"
		);
		$stmt->bind_param( 's', $slug );
		$stmt->execute();
		$booking = $stmt->get_result()->fetch_assoc() ?: null;
		$stmt->close();

		$stmt = $db->prepare(
			"SELECT b.id, b.student_id, b.subject_id, b.status, b.start_utc,
			        b.price_minor, b.payment_ref
			 FROM {$table} b
			 INNER JOIN {$prefix}plumberslot_tutors t ON t.id = b.tutor_id
			 WHERE t.slug = ?
			 ORDER BY b.id ASC"
		);
		$stmt->bind_param( 's', $slug );
		$stmt->execute();
		$bookings = $stmt->get_result()->fetch_all( MYSQLI_ASSOC );
		$stmt->close();
		fixture_response(
			array(
				'bookingCount' => $count,
				'booking'      => $booking,
				'bookings'     => $bookings,
			)
		);
	}

	if ( 'inspect-onboarding' === $action ) {
		$slug         = fixture_slug( FIXTURE_TUTOR_SLUG );
		$tutor_login  = fixture_login( FIXTURE_TUTOR_LOGIN );
		$tutors       = $prefix . 'plumberslot_tutors';
		$subjects     = $prefix . 'plumberslot_subjects';
		$availability = $prefix . 'plumberslot_availability';
		$audit        = $prefix . 'plumberslot_audit_log';
		$users        = $prefix . 'users';
		$usermeta     = $prefix . 'usermeta';
		$options      = $prefix . 'options';
		$posts        = $prefix . 'posts';

		$stmt = $db->prepare( "SELECT id, user_id, slug, status FROM {$tutors} WHERE slug = ? LIMIT 1" );
		$stmt->bind_param( 's', $slug );
		$stmt->execute();
		$tutor = $stmt->get_result()->fetch_assoc() ?: null;
		$stmt->close();
		$tutor_id = (int) ( $tutor['id'] ?? 0 );

		$stmt = $db->prepare( "SELECT ID FROM {$users} WHERE user_login = ? LIMIT 1" );
		$stmt->bind_param( 's', $tutor_login );
		$stmt->execute();
		$user_id = (int) ( $stmt->get_result()->fetch_assoc()['ID'] ?? 0 );
		$stmt->close();

		$completed_key = 'plumberslot_setup_completed';
		$stmt          = $db->prepare( "SELECT meta_value FROM {$usermeta} WHERE user_id = ? AND meta_key = ? LIMIT 1" );
		$stmt->bind_param( 'is', $user_id, $completed_key );
		$stmt->execute();
		$completed = (string) ( $stmt->get_result()->fetch_assoc()['meta_value'] ?? '' );
		$stmt->close();

		$stmt = $db->prepare( "SELECT name FROM {$subjects} WHERE tutor_id = ? ORDER BY id ASC" );
		$stmt->bind_param( 'i', $tutor_id );
		$stmt->execute();
		$subject_names = array_column( $stmt->get_result()->fetch_all( MYSQLI_ASSOC ), 'name' );
		$stmt->close();

		$stmt = $db->prepare( "SELECT COUNT(*) AS total FROM {$availability} WHERE tutor_id = ?" );
		$stmt->bind_param( 'i', $tutor_id );
		$stmt->execute();
		$availability_count = (int) $stmt->get_result()->fetch_assoc()['total'];
		$stmt->close();

		$settings_name = 'plumberslot_settings';
		$stmt          = $db->prepare( "SELECT option_value FROM {$options} WHERE option_name = ? LIMIT 1" );
		$stmt->bind_param( 's', $settings_name );
		$stmt->execute();
		$settings_value = (string) ( $stmt->get_result()->fetch_assoc()['option_value'] ?? '' );
		$stmt->close();
		$settings = unserialize( $settings_value, array( 'allowed_classes' => false ) );
		$settings = is_array( $settings ) ? $settings : array();

		$page_slug = fixture_slug( FIXTURE_PAGE_SLUG );
		$stmt      = $db->prepare( "SELECT post_content FROM {$posts} WHERE post_name = ? AND post_type = 'page' LIMIT 1" );
		$stmt->bind_param( 's', $page_slug );
		$stmt->execute();
		$page_content = (string) ( $stmt->get_result()->fetch_assoc()['post_content'] ?? '' );
		$stmt->close();

		$object_type = 'tutor';
		$audit_action = 'setup.completed';
		$stmt = $db->prepare( "SELECT COUNT(*) AS total FROM {$audit} WHERE object_type = ? AND object_id = ? AND action = ?" );
		$stmt->bind_param( 'sis', $object_type, $tutor_id, $audit_action );
		$stmt->execute();
		$audit_count = (int) $stmt->get_result()->fetch_assoc()['total'];
		$stmt->close();

		fixture_response(
			array(
				'tutor'             => $tutor,
				'completed'         => $completed,
				'subjects'          => $subject_names,
				'availabilityCount' => $availability_count,
				'setupMode'         => (string) ( $settings['setup_mode'] ?? '' ),
				'paymentsEnabled'   => ! empty( $settings['payments_enabled'] ),
				'pageContent'       => $page_content,
				'auditCount'        => $audit_count,
			)
		);
	}

	if ( ! in_array( $action, array( 'seed', 'seed-bengali', 'seed-payment', 'seed-lifecycle', 'seed-onboarding', 'seed-parent-dashboard' ), true ) ) {
		throw new RuntimeException( 'Expected a supported seed, inspect, or cleanup action.' );
	}

	if ( 'seed-payment' === $action && ! in_array( $payment_state, array( 'success', 'cancel', 'failure' ), true ) ) {
		throw new RuntimeException( 'Expected success, cancel, or failure payment state.' );
	}

	fixture_cleanup( $db, $prefix );
	$db->begin_transaction();

	try {
		$tutor_login = fixture_login( FIXTURE_TUTOR_LOGIN );
		$alice_login = fixture_login( FIXTURE_ALICE_LOGIN );
		$bob_login   = fixture_login( FIXTURE_BOB_LOGIN );
		$tutor_role  = in_array( $action, array( 'seed-lifecycle', 'seed-onboarding' ), true ) ? 'administrator' : 'plumberslot_tutor';
		$tutor_user  = fixture_user( $db, $prefix, $tutor_login, $tutor_role );
		$alice_user  = fixture_user( $db, $prefix, $alice_login, 'plumberslot_student' );
		fixture_user( $db, $prefix, $bob_login, 'plumberslot_student' );
		$parent_user = 'seed-parent-dashboard' === $action
			? fixture_user( $db, $prefix, fixture_login( FIXTURE_PARENT_LOGIN ), 'plumberslot_parent' )
			: 0;

		$now         = gmdate( 'Y-m-d H:i:s' );
		$tutors      = $prefix . 'plumberslot_tutors';
		$display     = 'seed-bengali' === $action ? 'ফারহানা রহমান' : 'Farhana E2E';
		$timezone    = 'UTC';
		$currency    = 'USD';
		$status      = 'active';
		$slug        = fixture_slug( FIXTURE_TUTOR_SLUG );
		$stmt        = $db->prepare(
			"INSERT INTO {$tutors}
				(user_id, slug, display_name, timezone, hourly_rate_minor, currency, status, created_at, updated_at)
			 VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?)"
		);
		$stmt->bind_param( 'isssssss', $tutor_user, $slug, $display, $timezone, $currency, $status, $now, $now );
		$stmt->execute();
		$tutor_id = (int) $db->insert_id;
		$stmt->close();

		$subjects = $prefix . 'plumberslot_subjects';
		$name     = 'seed-bengali' === $action ? 'বাংলা ভাষা ও সাহিত্য' : 'English E2E';
		$level    = 'seed-bengali' === $action ? 'প্রাথমিক · জাতীয় শিক্ষাক্রম' : 'Beginner';
		$duration = 60;
		$price    = in_array( $action, array( 'seed-payment', 'seed-parent-dashboard' ), true ) ? 2500 : 0;
		$trial    = 0;
		$status   = 'active';
		$order    = 0;
		$stmt     = $db->prepare(
			"INSERT INTO {$subjects}
				(tutor_id, name, level, duration_min, price_minor, is_trial, status, sort_order)
			 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
		);
		$stmt->bind_param( 'issiiisi', $tutor_id, $name, $level, $duration, $price, $trial, $status, $order );
		$stmt->execute();
		$subject_id = (int) $db->insert_id;
		$stmt->close();

		$start      = ( new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) ) )->modify( '+3 days' )->setTime( 10, 0 );
		$start_sql  = $start->format( 'Y-m-d H:i:s' );
		$weekday    = (int) $start->format( 'w' );
		$start_min  = 600;
		$end_min    = 'seed-lifecycle' === $action ? 840 : 720;
		$availability = $prefix . 'plumberslot_availability';
		$stmt       = $db->prepare(
			"INSERT INTO {$availability} (tutor_id, weekday, start_min, end_min) VALUES (?, ?, ?, ?)"
		);
		$stmt->bind_param( 'iiii', $tutor_id, $weekday, $start_min, $end_min );
		$stmt->execute();
		$stmt->close();

		$posts        = $prefix . 'posts';
		$content      = 'seed-parent-dashboard' === $action
			? '[plumberslot_dashboard view="parent"]'
			: '[plumberslot tutor="' . $slug . '"]';
		$title        = 'seed-bengali' === $action ? 'বাংলা পাঠ বুকিং' : 'PlumberSlot booking race';
		$page_slug    = fixture_slug( FIXTURE_PAGE_SLUG );
		$post_status  = 'publish';
		$comment      = 'closed';
		$ping         = 'closed';
		$empty        = '';
		$post_type    = 'page';
		$stmt         = $db->prepare(
			"INSERT INTO {$posts}
				(post_author, post_date, post_date_gmt, post_content, post_title, post_excerpt,
				 post_status, comment_status, ping_status, post_password, post_name, to_ping,
				 pinged, post_modified, post_modified_gmt, post_content_filtered, post_parent,
				 guid, menu_order, post_type, post_mime_type, comment_count)
			 VALUES (0, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, 0, ?, ?, 0)"
		);
		$stmt->bind_param(
			'ssssssssssssssssss',
			$now,
			$now,
			$content,
			$title,
			$empty,
			$post_status,
			$comment,
			$ping,
			$empty,
			$page_slug,
			$empty,
			$empty,
			$now,
			$now,
			$empty,
			$empty,
			$post_type,
			$empty
		);
		$stmt->execute();
		$page_id = (int) $db->insert_id;
		$stmt->close();

		if ( 'seed-onboarding' === $action ) {
			fixture_backup_onboarding_options( $db, $prefix );
			$options       = $prefix . 'options';
			$settings_name = 'plumberslot_settings';
			$stmt          = $db->prepare( "SELECT option_value FROM {$options} WHERE option_name = ? LIMIT 1" );
			$stmt->bind_param( 's', $settings_name );
			$stmt->execute();
			$settings_value = (string) ( $stmt->get_result()->fetch_assoc()['option_value'] ?? '' );
			$stmt->close();
			$settings                    = unserialize( $settings_value, array( 'allowed_classes' => false ) );
			$settings                    = is_array( $settings ) ? $settings : array();
			$settings['booking_page_id'] = $page_id;
			$settings_value              = serialize( $settings );
			$stmt                        = $db->prepare( "UPDATE {$options} SET option_value = ? WHERE option_name = ?" );
			$stmt->bind_param( 'ss', $settings_value, $settings_name );
			$stmt->execute();
			$stmt->close();
		}

		$credit_id = 0;
		if ( 'seed-parent-dashboard' === $action ) {
			$relations = $prefix . 'plumberslot_relations';
			$relation  = 'guardian';
			$confirmed = 1;
			$stmt      = $db->prepare(
				"INSERT INTO {$relations} (parent_id, student_id, relation, confirmed, created_at)
				 VALUES (?, ?, ?, ?, ?)"
			);
			$stmt->bind_param( 'iisis', $parent_user, $alice_user, $relation, $confirmed, $now );
			$stmt->execute();
			$stmt->close();

			$credits       = $prefix . 'plumberslot_credits';
			$total         = 10;
			$used          = 3;
			$package_price = 20000;
			$expires_at    = $start->modify( '+180 days' )->format( 'Y-m-d H:i:s' );
			$stmt          = $db->prepare(
				"INSERT INTO {$credits}
					(owner_id, tutor_id, subject_id, total, used, price_minor, expires_at, created_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
			);
			$stmt->bind_param(
				'iiiiiiss',
				$parent_user,
				$tutor_id,
				$subject_id,
				$total,
				$used,
				$package_price,
				$expires_at,
				$now
			);
			$stmt->execute();
			$credit_id = (int) $db->insert_id;
			$stmt->close();
		}

		$booking_id = 0;
		if ( in_array( $action, array( 'seed-payment', 'seed-lifecycle', 'seed-parent-dashboard' ), true ) ) {
			$bookings       = $prefix . 'plumberslot_bookings';
			$end_sql        = $start->modify( '+60 minutes' )->format( 'Y-m-d H:i:s' );
			$student_tz     = 'UTC';
			$booking_status = in_array( $action, array( 'seed-lifecycle', 'seed-parent-dashboard' ), true ) || 'success' === $payment_state ? 'confirmed' : ( 'cancel' === $payment_state ? 'pending_payment' : 'payment_failed' );
			$payment_ref    = 'success' === $payment_state ? 'plumberslot-e2e-paid-' . fixture_key() : '';
			$stmt           = $db->prepare(
				"INSERT INTO {$bookings}
					(tutor_id, student_id, subject_id, start_utc, end_utc, student_tz,
					 status, price_minor, currency, payment_ref, created_at, updated_at)
				 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
			);
			$stmt->bind_param(
				'iiissssissss',
				$tutor_id,
				$alice_user,
				$subject_id,
				$start_sql,
				$end_sql,
				$student_tz,
				$booking_status,
				$price,
				$currency,
				$payment_ref,
				$now,
				$now
			);
			$stmt->execute();
			$booking_id = (int) $db->insert_id;
			$stmt->close();

			if ( 'seed-parent-dashboard' === $action ) {
				$notes = 'Strong progress with reading comprehension and vocabulary.';
				$stmt  = $db->prepare(
					"UPDATE {$bookings} SET parent_id = ?, credit_id = ?, notes = ? WHERE id = ?"
				);
				$stmt->bind_param( 'iisi', $parent_user, $credit_id, $notes, $booking_id );
				$stmt->execute();
				$stmt->close();
			}
		}

		$db->commit();
	} catch ( Throwable $error ) {
		$db->rollback();
		throw $error;
	}

	fixture_response(
		array(
			'tutorId'    => $tutor_id,
			'subjectId'  => $subject_id,
			'bookingId'  => $booking_id,
			'paymentState' => $payment_state,
			'start'      => $start->format( DATE_ATOM ),
			'startSql'   => $start_sql,
			'pagePath'   => '/?page_id=' . $page_id,
			'password'   => FIXTURE_PASSWORD,
			'tutorSlug'  => $slug,
			'tutorLogin' => $tutor_login,
			'aliceLogin' => $alice_login,
			'bobLogin'   => $bob_login,
			'parentLogin' => fixture_login( FIXTURE_PARENT_LOGIN ),
		)
	);
} catch ( Throwable $error ) {
	fwrite( STDERR, $error->getMessage() . PHP_EOL );
	exit( 1 );
}
