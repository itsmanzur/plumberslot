<?php
/**
 * GDPR export, erasure and suggested policy text.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Privacy;

use PlumberSlot\Database\Repository\BookingRepository;
use PlumberSlot\Database\Schema;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Money;
use PlumberSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class PrivacyHooks {

	private const PAGE_SIZE = 20;

	public function __construct( private readonly BookingRepository $bookings ) {}

	public function register(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'add_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'add_eraser' ) );
		add_action( 'admin_init', array( $this, 'add_policy_content' ) );
	}

	/**
	 * @param array<string, array<string, mixed>> $exporters Registered exporters.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_exporter( array $exporters ): array {
		$exporters['plumberslot'] = array(
			'exporter_friendly_name' => __( 'PlumberSlot lessons', 'plumberslot' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<string, array<string, mixed>> $erasers Registered erasers.
	 * @return array<string, array<string, mixed>>
	 */
	public function add_eraser( array $erasers ): array {
		$erasers['plumberslot'] = array(
			'eraser_friendly_name' => __( 'PlumberSlot lessons', 'plumberslot' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array{data: list<array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}>, done: bool}
	 */
	public function export( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		if ( 1 === max( 1, $page ) ) {
			AuditLog::record( 'privacy.exported', 'user', (int) $user->ID );
		}

		$page    = max( 1, $page );
		$markers = $this->record_markers(
			(int) $user->ID,
			false,
			self::PAGE_SIZE + 1,
			( $page - 1 ) * self::PAGE_SIZE
		);
		$done    = count( $markers ) <= self::PAGE_SIZE;
		$data    = array();

		foreach ( array_slice( $markers, 0, self::PAGE_SIZE ) as $marker ) {
			$item = $this->export_item( $marker, (int) $user->ID );
			if ( null !== $item ) {
				$data[] = $item;
			}
		}

		return array(
			'data' => $data,
			'done' => $done,
		);
	}

	/**
	 * Anonymise identifying fields while retaining dates, amounts, statuses and
	 * transaction references required for accounting and dispute records.
	 *
	 * The eraser always processes the first remaining batch. WordPress increases
	 * the page number between callbacks, but an offset would skip records after
	 * the previous batch no longer matches the user id.
	 *
	 * @return array{items_removed: bool, items_retained: bool, messages: list<string>, done: bool}
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- pagination argument is required by the WordPress eraser callback contract.
	public function erase( string $email, int $page = 1 ): array {
		$user = get_user_by( 'email', $email );

		if ( ! $user ) {
			return $this->empty_erase_result();
		}

		$markers  = $this->record_markers( (int) $user->ID, true, self::PAGE_SIZE, 0 );
		$removed  = false;
		$retained = false;

		foreach ( $markers as $marker ) {
			$result   = $this->erase_record( $marker, (int) $user->ID );
			$removed  = $removed || $result['removed'];
			$retained = $retained || $result['retained'];
		}

		$done     = array() === $this->record_markers( (int) $user->ID, true, 1, 0 );
		$messages = array();

		if ( $retained ) {
			$messages[] = __( 'PlumberSlot retained anonymized lesson dates, amounts, statuses and transaction references for accounting, tax and dispute records.', 'plumberslot' );
		}

		if ( $done && ( $removed || $retained ) ) {
			AuditLog::record(
				'privacy.erased',
				'user',
				(int) $user->ID,
				array( 'retained_accounting' => $retained )
			);
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => $done,
		);
	}

	public function add_policy_content(): void {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content( __( 'PlumberSlot', 'plumberslot' ), $this->policy_text() );
		}
	}

	public function policy_text(): string {
		return '<p>' . esc_html__( 'PlumberSlot stores lesson dates and times, participant and paying-adult account identifiers, timezone, booking notes, reviews, payment status and meeting-provider references to schedule and deliver lessons.', 'plumberslot' ) . '</p>'
			. '<p>' . esc_html__( 'Lesson reminders may be sent to the student, paying parent and tutor. Meeting emails contain a signed PlumberSlot join link rather than the provider’s private meeting URL.', 'plumberslot' ) . '</p>'
			. '<p>' . esc_html__( 'When an optional online payment is selected, PlumberSlot sends the booking reference, amount, currency and return URLs to Stripe or bKash. Payment account, card, OTP and PIN details are entered on the provider’s hosted pages and are not stored by PlumberSlot.', 'plumberslot' ) . '</p>'
			. '<p>' . esc_html__( 'When a meeting provider is connected, PlumberSlot sends the student display name, lesson schedule and duration, and a booking-derived reference to Google Calendar and Meet or to Zoom so that the meeting can be created and retrieved.', 'plumberslot' ) . '</p>'
			. '<p>' . esc_html__( 'Email uses the site’s WordPress mail configuration. If the site connects an SMS add-on, the add-on receives the recipient mobile number, reminder or cancellation message and booking record; consult the site’s selected delivery provider policies.', 'plumberslot' ) . '</p>'
			. '<p>' . esc_html__( 'When a verified erasure request is processed, PlumberSlot removes relationships and anonymizes account identifiers, notes, review text and security metadata. Lesson dates, amounts, statuses and transaction references may be retained where required for accounting, tax, fraud prevention or dispute resolution.', 'plumberslot' ) . '</p>';
	}

	/**
	 * @return list<object{record_type:string,id:int,sort_at:string}>
	 */
	private function record_markers( int $user_id, bool $for_erasure, int $limit, int $offset ): array {
		global $wpdb;

		$bookings  = Schema::table( Schema::BOOKINGS );
		$relations = Schema::table( Schema::RELATIONS );
		$reviews   = Schema::table( Schema::REVIEWS );
		$queries   = array(
			"SELECT 'booking' AS record_type, id, created_at AS sort_at FROM {$bookings} WHERE student_id = %d OR parent_id = %d",
			"SELECT 'relation' AS record_type, id, created_at AS sort_at FROM {$relations} WHERE parent_id = %d OR student_id = %d",
			"SELECT 'review' AS record_type, id, created_at AS sort_at FROM {$reviews} WHERE author_id = %d",
		);
		$params    = array( $user_id, $user_id, $user_id, $user_id, $user_id );

		if ( $for_erasure ) {
			$series    = Schema::table( Schema::SERIES );
			$credits   = Schema::table( Schema::CREDITS );
			$audit     = Schema::table( Schema::AUDIT );
			$queries[] = "SELECT 'series' AS record_type, id, created_at AS sort_at FROM {$series} WHERE student_id = %d";
			$queries[] = "SELECT 'credit' AS record_type, id, created_at AS sort_at FROM {$credits} WHERE owner_id = %d";
			$queries[] = "SELECT 'audit' AS record_type, id, created_at AS sort_at FROM {$audit} WHERE actor_id = %d";
			$params[]  = $user_id;
			$params[]  = $user_id;
			$params[]  = $user_id;
		}

		$sql      = implode( ' UNION ALL ', $queries ) . ' ORDER BY sort_at ASC, record_type ASC, id ASC LIMIT %d OFFSET %d';
		$params[] = max( 1, $limit );
		$params[] = max( 0, $offset );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table names come from the schema whitelist; values are prepared.
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * @return array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}|null
	 */
	private function export_item( object $marker, int $user_id ): ?array {
		return match ( (string) $marker->record_type ) {
			'booking'  => $this->export_booking( (int) $marker->id, $user_id ),
			'relation' => $this->export_relation( (int) $marker->id, $user_id ),
			'review'   => $this->export_review( (int) $marker->id ),
			default    => null,
		};
	}

	/**
	 * @return array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}|null
	 */
	private function export_booking( int $booking_id, int $user_id ): ?array {
		$booking = $this->bookings->find( $booking_id );

		if ( ! $booking ) {
			return null;
		}

		$roles = array();
		if ( $user_id === (int) $booking->student_id ) {
			$roles[] = __( 'Student', 'plumberslot' );
		}
		if ( $user_id === (int) $booking->parent_id ) {
			$roles[] = __( 'Paying parent', 'plumberslot' );
		}

		$data = array(
			$this->export_field( __( 'Role', 'plumberslot' ), implode( ', ', $roles ) ),
			$this->export_field( __( 'When', 'plumberslot' ), Time::for_human( Time::from_sql( (string) $booking->start_utc ), (string) $booking->student_tz ) ),
			$this->export_field( __( 'Status', 'plumberslot' ), (string) $booking->status ),
			$this->export_field( __( 'Amount', 'plumberslot' ), Money::format( (int) $booking->price_minor, (string) $booking->currency ) ),
			$this->export_field( __( 'Notes', 'plumberslot' ), (string) $booking->notes ),
		);

		if ( ! empty( $booking->payment_ref ) ) {
			$data[] = $this->export_field( __( 'Payment reference', 'plumberslot' ), (string) $booking->payment_ref );
		}

		return array(
			'group_id'    => 'plumberslot-bookings',
			'group_label' => __( 'Lessons', 'plumberslot' ),
			'item_id'     => 'booking-' . $booking->id,
			'data'        => $data,
		);
	}

	/**
	 * @return array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}|null
	 */
	private function export_relation( int $relation_id, int $user_id ): ?array {
		$relation = $this->find_row( Schema::RELATIONS, $relation_id );

		if ( ! $relation ) {
			return null;
		}

		$is_parent = $user_id === (int) $relation->parent_id;

		return array(
			'group_id'    => 'plumberslot-relations',
			'group_label' => __( 'Family relationships', 'plumberslot' ),
			'item_id'     => 'relation-' . $relation->id,
			'data'        => array(
				$this->export_field( __( 'Role', 'plumberslot' ), $is_parent ? __( 'Parent', 'plumberslot' ) : __( 'Student', 'plumberslot' ) ),
				$this->export_field( __( 'Relationship', 'plumberslot' ), (string) $relation->relation ),
				$this->export_field( __( 'Related account ID', 'plumberslot' ), (string) ( $is_parent ? $relation->student_id : $relation->parent_id ) ),
				$this->export_field( __( 'Confirmed', 'plumberslot' ), (int) $relation->confirmed ? __( 'Yes', 'plumberslot' ) : __( 'No', 'plumberslot' ) ),
			),
		);
	}

	/**
	 * @return array{group_id:string, group_label:string, item_id:string, data:list<array{name:string, value:string}>}|null
	 */
	private function export_review( int $review_id ): ?array {
		$review = $this->find_row( Schema::REVIEWS, $review_id );

		if ( ! $review ) {
			return null;
		}

		return array(
			'group_id'    => 'plumberslot-reviews',
			'group_label' => __( 'Lesson reviews', 'plumberslot' ),
			'item_id'     => 'review-' . $review->id,
			'data'        => array(
				$this->export_field( __( 'Rating', 'plumberslot' ), (string) $review->rating ),
				$this->export_field( __( 'Review', 'plumberslot' ), (string) $review->body ),
				$this->export_field( __( 'Status', 'plumberslot' ), (string) $review->status ),
				$this->export_field( __( 'Booking ID', 'plumberslot' ), (string) $review->booking_id ),
			),
		);
	}

	/**
	 * @return array{removed:bool,retained:bool}
	 */
	private function erase_record( object $marker, int $user_id ): array {
		global $wpdb;

		$id      = (int) $marker->id;
		$type    = (string) $marker->record_type;
		$changed = 0;

		switch ( $type ) {
			case 'booking':
				$table = Schema::table( Schema::BOOKINGS );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the schema whitelist; values are prepared.
				$changed = (int) $wpdb->query(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the schema whitelist; values are prepared below.
						'UPDATE ' . $table . ' SET student_id = IF(student_id = %d, 0, student_id), parent_id = IF(parent_id = %d, NULL, parent_id), notes = NULL, updated_at = %s WHERE id = %d',
						$user_id,
						$user_id,
						gmdate( 'Y-m-d H:i:s' ),
						$id
					)
				);
				break;

			case 'relation':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- relation id comes from a prepared internal query.
				$changed = (int) $wpdb->delete( Schema::table( Schema::RELATIONS ), array( 'id' => $id ), array( '%d' ) );
				break;

			case 'review':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- review id comes from a prepared internal query.
				$changed = (int) $wpdb->update(
					Schema::table( Schema::REVIEWS ),
					array(
						'author_id' => 0,
						'body'      => null,
					),
					array( 'id' => $id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
				break;

			case 'series':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- series id comes from a prepared internal query.
				$changed = (int) $wpdb->update( Schema::table( Schema::SERIES ), array( 'student_id' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
				break;

			case 'credit':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- credit id comes from a prepared internal query.
				$changed = (int) $wpdb->update( Schema::table( Schema::CREDITS ), array( 'owner_id' => 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
				break;

			case 'audit':
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- audit id comes from a prepared internal query.
				$changed = (int) $wpdb->update(
					Schema::table( Schema::AUDIT ),
					array(
						'actor_id' => 0,
						'ip_hash'  => null,
						'meta'     => null,
					),
					array( 'id' => $id ),
					array( '%d', '%s', '%s' ),
					array( '%d' )
				);
				break;
		}

		return array(
			'removed'  => $changed > 0,
			'retained' => $changed > 0 && 'relation' !== $type,
		);
	}

	private function find_row( string $table_key, int $id ): ?object {
		global $wpdb;

		$table = Schema::table( $table_key );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- table name comes from the schema whitelist; id is prepared.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE id = %d', $id ) );

		return $row ? $row : null;
	}

	/**
	 * @return array{name:string,value:string}
	 */
	private function export_field( string $name, string $value ): array {
		return array(
			'name'  => $name,
			'value' => $value,
		);
	}

	/**
	 * @return array{items_removed: false, items_retained: false, messages: list<string>, done: true}
	 */
	private function empty_erase_result(): array {
		return array(
			'items_removed'  => false,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}
}
