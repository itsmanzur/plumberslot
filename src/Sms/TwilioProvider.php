<?php
/**
 * Twilio SMS delivery -- the concrete listener for SmsChannel's
 * plumberslot_send_sms hook, which stayed unimplemented until this class.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Sms;

use PlumberSlot\Support\AuditLog;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\Settings;

defined( 'ABSPATH' ) || exit;

final class TwilioProvider {

	private const API_MESSAGES = 'https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json';

	public function register(): void {
		add_action( 'plumberslot_send_sms', array( $this, 'send' ), 10, 2 );
	}

	public function is_configured(): bool {
		return '' !== $this->account_sid() && '' !== $this->auth_token() && '' !== $this->from_number();
	}

	public function send( string $number, string $message ): void {
		$number = trim( $number );

		if ( '' === $number || ! $this->is_configured() ) {
			return;
		}

		$response = wp_remote_post(
			sprintf( self::API_MESSAGES, rawurlencode( $this->account_sid() ) ),
			array(
				'timeout' => 15,
				'headers' => array(
					// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic authentication requires RFC 7617 encoding.
					'Authorization' => 'Basic ' . base64_encode( $this->account_sid() . ':' . $this->auth_token() ),
				),
				'body'    => array(
					'To'   => $number,
					'From' => $this->from_number(),
					'Body' => $message,
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			AuditLog::record( 'sms.send_failed', 'booking', 0, array( 'gateway' => 'twilio' ) );

			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			AuditLog::record( 'sms.send_failed', 'booking', 0, array( 'gateway' => 'twilio' ) );
		}
	}

	private function account_sid(): string {
		return Settings::string( 'twilio_account_sid' );
	}

	private function auth_token(): string {
		return (string) ( Crypto::decrypt( Settings::string( 'twilio_auth_token' ) ) ?? '' );
	}

	private function from_number(): string {
		return Settings::string( 'twilio_from_number' );
	}
}
