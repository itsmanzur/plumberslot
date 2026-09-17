<?php
/**
 * Phase 9 — Twilio SMS delivery tests.
 *
 * @package PlumberSlot\Tests
 */

declare( strict_types = 1 );

namespace PlumberSlot\Tests\Integration;

use PlumberSlot\Database\Schema;
use PlumberSlot\Notifications\Channel\SmsChannel;
use PlumberSlot\Sms\TwilioProvider;
use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Settings;

/**
 * @group integration
 */
final class Phase9SmsTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}

		Settings::update(
			array(
				'twilio_account_sid' => '',
				'twilio_auth_token'  => '',
				'twilio_from_number' => '',
			)
		);
		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// TwilioProvider
	// -----------------------------------------------------------------------

	public function test_configured_provider_posts_to_twilio_with_basic_auth_and_message_fields(): void {
		$this->configure_twilio();

		$captured = null;
		$http     = static function ( $preempt, array $args, string $url ) use ( &$captured ) {
			$captured = array(
				'url'  => $url,
				'args' => $args,
			);

			return array(
				'headers'  => array(),
				'body'     => wp_json_encode( array( 'sid' => 'SM_fixture' ) ),
				'response' => array(
					'code'    => 201,
					'message' => 'Created',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $http, 10, 3 );
		try {
			( new TwilioProvider() )->send( '+15559876543', 'Your technician is on the way.' );
		} finally {
			remove_filter( 'pre_http_request', $http, 10 );
		}

		$this->assertIsArray( $captured );
		$this->assertSame(
			'https://api.twilio.com/2010-04-01/Accounts/AC_fixture_sid/Messages.json',
			$captured['url']
		);
		$this->assertSame(
			'Basic ' . base64_encode( 'AC_fixture_sid:auth_token_fixture' ),
			$captured['args']['headers']['Authorization']
		);
		$this->assertSame(
			array(
				'To'   => '+15559876543',
				'From' => '+15550000000',
				'Body' => 'Your technician is on the way.',
			),
			$captured['args']['body']
		);

		// A clean 2xx delivery must never leave an audit trail behind.
		$this->assertSame( 0, $this->audit_count( 'sms.send_failed' ) );
	}

	public function test_unconfigured_provider_sends_nothing(): void {
		// No twilio_* settings at all -- the provider must not even attempt
		// an HTTP call, let alone leak credentials into one.
		$called = false;
		$http   = static function ( $preempt ) use ( &$called ) {
			$called = true;

			return $preempt;
		};

		add_filter( 'pre_http_request', $http );
		try {
			( new TwilioProvider() )->send( '+15559876543', 'Hello' );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertFalse( $called, 'An unconfigured Twilio provider must never place an HTTP call.' );
	}

	public function test_partially_configured_provider_sends_nothing(): void {
		Settings::update(
			array(
				'twilio_account_sid' => 'AC_fixture_sid',
				'twilio_from_number' => '+15550000000',
				// twilio_auth_token deliberately left blank.
			)
		);

		$called = false;
		$http   = static function ( $preempt ) use ( &$called ) {
			$called = true;

			return $preempt;
		};

		add_filter( 'pre_http_request', $http );
		try {
			( new TwilioProvider() )->send( '+15559876543', 'Hello' );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertFalse( $called );
	}

	public function test_empty_number_sends_nothing_even_when_configured(): void {
		$this->configure_twilio();

		$called = false;
		$http   = static function ( $preempt ) use ( &$called ) {
			$called = true;

			return $preempt;
		};

		add_filter( 'pre_http_request', $http );
		try {
			( new TwilioProvider() )->send( '   ', 'Hello' );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertFalse( $called );
	}

	public function test_non_2xx_twilio_response_is_audit_logged_not_thrown(): void {
		$this->configure_twilio();

		$http = static function (): array {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'code'    => 20003,
						'message' => 'Authentication Error',
					)
				),
				'response' => array(
					'code'    => 401,
					'message' => 'Unauthorized',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $http );
		try {
			( new TwilioProvider() )->send( '+15559876543', 'Hello' );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertSame( 1, $this->audit_count( 'sms.send_failed' ) );
	}

	public function test_wp_error_from_http_layer_is_audit_logged_not_thrown(): void {
		$this->configure_twilio();

		$http = static function () {
			return new \WP_Error( 'http_request_failed', 'Could not resolve host.' );
		};

		add_filter( 'pre_http_request', $http );
		try {
			// Must not throw: a bad SMS send must never break the caller.
			( new TwilioProvider() )->send( '+15559876543', 'Hello' );
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertSame( 1, $this->audit_count( 'sms.send_failed' ) );
	}

	public function test_send_hooks_the_plumberslot_send_sms_action(): void {
		$this->configure_twilio();

		$captured = null;
		$http     = static function ( $preempt, array $args ) use ( &$captured ) {
			$captured = $args['body'];

			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 201,
					'message' => 'Created',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		( new TwilioProvider() )->register();

		add_filter( 'pre_http_request', $http, 10, 2 );
		try {
			do_action( 'plumberslot_send_sms', '+15551112222', 'On my way.', (object) array() );
		} finally {
			remove_filter( 'pre_http_request', $http, 10 );
			remove_all_actions( 'plumberslot_send_sms' );
		}

		$this->assertIsArray( $captured );
		$this->assertSame( '+15551112222', $captured['To'] );
		$this->assertSame( 'On my way.', $captured['Body'] );
	}

	// -----------------------------------------------------------------------
	// SmsChannel
	// -----------------------------------------------------------------------

	public function test_sms_channel_enables_on_the_way_event(): void {
		Settings::update( array( 'sms_enabled' => true ) );
		$channel = new SmsChannel();

		$this->assertTrue( $channel->is_enabled( 'booking_on_the_way' ) );
		$this->assertTrue( $channel->is_enabled( 'reminder_1h' ) );
		$this->assertTrue( $channel->is_enabled( 'booking_cancelled' ) );
		$this->assertFalse( $channel->is_enabled( 'booking_created' ) );

		Settings::update( array( 'sms_enabled' => false ) );
	}

	public function test_sms_channel_on_the_way_message_is_not_cancellation_copy(): void {
		Settings::update( array( 'sms_enabled' => true ) );
		$channel = new SmsChannel();
		$user_id = self::factory()->user->create();

		$captured = array();
		add_action(
			'plumberslot_send_sms',
			static function ( string $number, string $message ) use ( &$captured ): void {
				$captured[] = $message;
			},
			10,
			2
		);

		update_user_meta( $user_id, '_plumberslot_mobile', '+15551234567' );

		try {
			$channel->send( 'booking_on_the_way', $user_id, (object) array() );
			$channel->send( 'booking_cancelled', $user_id, (object) array() );
		} finally {
			remove_all_actions( 'plumberslot_send_sms' );
			Settings::update( array( 'sms_enabled' => false ) );
		}

		$this->assertCount( 2, $captured );
		$this->assertSame( 'Your technician is on the way.', $captured[0] );
		$this->assertSame( 'Your appointment has been cancelled.', $captured[1] );
		$this->assertNotSame( $captured[0], $captured[1] );
	}

	private function configure_twilio(): void {
		Settings::update(
			array(
				'twilio_account_sid' => 'AC_fixture_sid',
				'twilio_auth_token'  => Crypto::encrypt( 'auth_token_fixture' ),
				'twilio_from_number' => '+15550000000',
			)
		);
	}

	private function audit_count( string $action ): int {
		return count(
			array_filter(
				AuditLog::recent( 50 ),
				static fn ( object $row ): bool => $action === (string) $row->action
			)
		);
	}
}
