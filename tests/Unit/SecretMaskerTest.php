<?php
/**
 * Secret response masking tests.
 *
 * @package TutorSlot\Tests
 */

declare( strict_types = 1 );

namespace TutorSlot\Tests\Unit;

use PHPUnit\Framework\TestCase;
use TutorSlot\Support\SecretMasker;

final class SecretMaskerTest extends TestCase {

	public function test_redacts_nested_secret_fields_without_breaking_operational_tokens(): void {
		$redacted = SecretMasker::redact(
			array(
				'access_token'  => 'oauth-access-token',
				'nested'        => array(
					'password'      => 'merchant-password',
					'client_secret' => 'oauth-client-secret',
					'empty_secret'  => '',
				),
				'lock_token'    => 'required-booking-lock-token',
				'meeting_token' => 'required-meeting-token',
				'payment_ref'   => 'payment-reference',
				'provider'      => (object) array( 'api_key' => 'provider-api-key' ),
			)
		);

		$this->assertSame( SecretMasker::MASK, $redacted['access_token'] );
		$this->assertSame( SecretMasker::MASK, $redacted['nested']['password'] );
		$this->assertSame( SecretMasker::MASK, $redacted['nested']['client_secret'] );
		$this->assertSame( '', $redacted['nested']['empty_secret'] );
		$this->assertSame( 'required-booking-lock-token', $redacted['lock_token'] );
		$this->assertSame( 'required-meeting-token', $redacted['meeting_token'] );
		$this->assertSame( 'payment-reference', $redacted['payment_ref'] );
		$this->assertInstanceOf( \stdClass::class, $redacted['provider'] );
		$this->assertSame( SecretMasker::MASK, $redacted['provider']->api_key );
	}

	public function test_redact_preserves_json_serializable_response_shape(): void {
		$value = new class() implements \JsonSerializable {
			/** @return array<string, string> */
			public function jsonSerialize(): array {
				return array(
					'start'   => '2026-08-26T10:00:00+00:00',
					'api_key' => 'fixture-secret',
				);
			}
		};

		$this->assertSame(
			array(
				'start'   => '2026-08-26T10:00:00+00:00',
				'api_key' => SecretMasker::MASK,
			),
			SecretMasker::redact( $value )
		);
	}

	public function test_masks_write_only_settings_and_preserves_public_identifiers(): void {
		$settings = SecretMasker::settings(
			array(
				'stripe_secret_key'      => 'encrypted-secret',
				'bkash_app_key'          => 'merchant-app-key',
				'bkash_username'         => 'merchant-user',
				'stripe_publishable_key' => 'pk_live_public',
				'zoom_client_id'         => 'zoom-public-client-id',
			)
		);

		$this->assertSame( SecretMasker::MASK, $settings['stripe_secret_key'] );
		$this->assertSame( SecretMasker::MASK, $settings['bkash_app_key'] );
		$this->assertSame( SecretMasker::MASK, $settings['bkash_username'] );
		$this->assertSame( 'pk_live_public', $settings['stripe_publishable_key'] );
		$this->assertSame( 'zoom-public-client-id', $settings['zoom_client_id'] );
	}
}
