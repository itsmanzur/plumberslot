<?php
/**
 * Phase 8 secret response and persistence boundary tests.
 *
 * @package TutorSlot\Tests
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Integration;

use TutorSlot\Admin\SettingsRegistry;
use TutorSlot\Database\Schema;
use TutorSlot\Payments\StripeGateway;
use TutorSlot\Support\AuditLog;
use TutorSlot\Support\Crypto;
use TutorSlot\Support\SecretMasker;
use TutorSlot\Support\Settings;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @group integration
 */
final class Phase8SecretMaskingTest extends \WP_UnitTestCase {

	private string $encrypted_stripe_secret;

	public function set_up(): void {
		parent::set_up();
		Schema::create_all();

		$this->encrypted_stripe_secret = Crypto::encrypt( 'sk_live_private_fixture' );
		Settings::update(
			array(
				'stripe_secret_key'      => $this->encrypted_stripe_secret,
				'bkash_app_key'          => 'bkash-private-app-key',
				'bkash_username'         => 'bkash-private-user',
				'stripe_publishable_key' => 'pk_live_public_fixture',
			)
		);
	}

	public function tear_down(): void {
		global $wpdb;

		foreach ( Schema::all_keys() as $key ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- integration-test tables only.
			$wpdb->query( 'DELETE FROM ' . Schema::table( $key ) );
		}

		Settings::update(
			array(
				'stripe_secret_key'      => '',
				'bkash_app_key'          => '',
				'bkash_username'         => '',
				'stripe_publishable_key' => '',
			)
		);
		parent::tear_down();
	}

	public function test_settings_response_masks_secrets_and_masked_round_trip_preserves_them(): void {
		$registry = new SettingsRegistry();
		$data     = $registry->read()->get_data();

		$this->assertSame( SecretMasker::MASK, $data['stripe_secret_key'] );
		$this->assertSame( SecretMasker::MASK, $data['bkash_app_key'] );
		$this->assertSame( SecretMasker::MASK, $data['bkash_username'] );
		$this->assertSame( 'pk_live_public_fixture', $data['stripe_publishable_key'] );

		$request = new WP_REST_Request( 'POST', '/tutorslot/v1/settings' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body(
			wp_json_encode(
				array(
					'stripe_secret_key' => SecretMasker::MASK,
					'bkash_app_key'     => SecretMasker::MASK,
					'bkash_username'    => SecretMasker::MASK,
				)
			)
		);
		$registry->write( $request );

		$this->assertSame( $this->encrypted_stripe_secret, Settings::string( 'stripe_secret_key' ) );
		$this->assertSame( 'bkash-private-app-key', Settings::string( 'bkash_app_key' ) );
		$this->assertSame( 'bkash-private-user', Settings::string( 'bkash_username' ) );
	}

	public function test_final_rest_guard_masks_error_data_and_known_values(): void {
		$request  = new WP_REST_Request( 'GET', '/tutorslot/v1/security-fixture' );
		$response = new WP_REST_Response(
			array(
				'code'    => 'provider_error',
				'message' => 'Provider echoed sk_live_private_fixture and bkash-private-app-key.',
				'data'    => array(
					'access_token' => 'dynamic-access-token',
					'lock_token'   => 'required-lock-token',
				),
			)
		);

		SecretMasker::filter_response( $response, null, $request );
		$json = wp_json_encode( $response->get_data() );

		$this->assertStringNotContainsString( 'sk_live_private_fixture', $json );
		$this->assertStringNotContainsString( 'bkash-private-app-key', $json );
		$this->assertStringContainsString( SecretMasker::MASK, $json );
		$this->assertSame( 'required-lock-token', $response->get_data()['data']['lock_token'] );
	}

	public function test_audit_metadata_is_redacted_before_database_storage(): void {
		AuditLog::record(
			'security.secret_fixture',
			'settings',
			0,
			array(
				'authorization' => 'Bearer dynamic-token',
				'message'       => 'Failure echoed sk_live_private_fixture.',
				'lock_token'    => 'required-lock-token',
			)
		);

		$rows = AuditLog::recent( 1 );
		$meta = json_decode( (string) $rows[0]->meta, true );

		$this->assertSame( SecretMasker::MASK, $meta['authorization'] );
		$this->assertStringNotContainsString( 'sk_live_private_fixture', $meta['message'] );
		$this->assertSame( 'required-lock-token', $meta['lock_token'] );
	}

	public function test_payment_provider_error_payload_is_not_returned_to_the_caller(): void {
		$http = static function (): array {
			return array(
				'headers'  => array(),
				'body'     => wp_json_encode(
					array(
						'error' => array(
							'message' => 'Upstream echoed sk_live_private_fixture and access_token=dynamic-token.',
						),
					)
				),
				'response' => array(
					'code'    => 402,
					'message' => 'Payment Required',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};

		add_filter( 'pre_http_request', $http );
		try {
			$result = ( new StripeGateway() )->start(
				99,
				2500,
				'USD',
				'https://example.test/success',
				'https://example.test/cancel'
			);
		} finally {
			remove_filter( 'pre_http_request', $http );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'tutorslot_stripe_failed', $result->get_error_code() );
		$this->assertStringNotContainsString( 'sk_live_private_fixture', $result->get_error_message() );
		$this->assertStringNotContainsString( 'dynamic-token', $result->get_error_message() );
	}
}
