<?php
/**
 * Settings schema and sanitisation.
 *
 * Eight settings are visible; the other forty live behind Advanced. A technician
 * should be able to read the whole first screen without scrolling.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Admin;

use PlumberSlot\Support\Capabilities;
use PlumberSlot\Support\Crypto;
use PlumberSlot\Support\SecretMasker;
use PlumberSlot\Support\Time;

defined( 'ABSPATH' ) || exit;

final class SettingsRegistry {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
	}

	public function register_route(): void {
		register_rest_route(
			'plumberslot/v1',
			'/settings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( $this, 'read' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( $this, 'write' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);
	}

	public function can_manage(): bool {
		return current_user_can( Capabilities::MANAGE_ALL );
	}

	public function read(): \WP_REST_Response {
		$values = \PlumberSlot\Support\Settings::all();

		// Secrets are write-only over the API; the UI shows a masked placeholder.
		$values = SecretMasker::settings( $values );

		$values['payments_status'] = \PlumberSlot\Support\PaymentsStatus::snapshot();

		return new \WP_REST_Response( $values );
	}

	public function write( \WP_REST_Request $request ): \WP_REST_Response {
		$clean = $this->sanitize( (array) $request->get_json_params() );

		\PlumberSlot\Support\Settings::update( $clean );
		\PlumberSlot\Support\AuditLog::record( 'settings.updated', 'settings', 0, array( 'keys' => array_keys( $clean ) ) );

		return new \WP_REST_Response( array( 'saved' => true ) );
	}

	/**
	 * @param array<string, mixed> $input Raw values.
	 * @return array<string, mixed>
	 */
	private function sanitize( array $input ): array {
		$out = array();

		$integers = array(
			'slot_granularity_minutes'     => array( 5, 120 ),
			'default_lesson_minutes'       => array( 15, 480 ),
			'buffer_minutes'               => array( 0, 120 ),
			'lead_time_minutes'            => array( 0, 20160 ),
			'hold_window_minutes'          => array( 2, 60 ),
			'slot_cache_ttl'               => array( 0, 3600 ),
			'reschedule_window_minutes'    => array( 0, 20160 ),
			'credit_package_size'          => array( 1, 100 ),
			'credit_package_price_minor'   => array( 0, 100000000 ),
			'credit_expiry_days'           => array( 0, 3650 ),
			'credit_refund_window_minutes' => array( 0, 20160 ),
		);

		foreach ( $integers as $key => [$min, $max] ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = max( $min, min( $max, absint( $input[ $key ] ) ) );
			}
		}

		foreach ( array(
			'auto_confirm',
			'credit_rollover_enabled',
			'sms_enabled',
			'bkash_sandbox',
			'delete_data_on_uninstall',
			'allow_customer_reschedule',
			'offer_free_estimate',
			'show_customer_timezone',
			'reminder_email_24h',
			'notify_technician_on_book',
			'payments_enabled',
		) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$out[ $key ] = (bool) $input[ $key ];
			}
		}

		foreach ( array( 'stripe_publishable_key', 'bkash_app_key', 'bkash_username', 'zoom_account_id', 'zoom_client_id', 'google_client_id', 'google_meet_calendar_id', 'sms_provider', 'default_currency' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				if ( SecretMasker::is_masked_setting( $key, $input[ $key ] ) ) {
					continue;
				}
				$out[ $key ] = sanitize_text_field( (string) $input[ $key ] );
			}
		}

		if ( isset( $input['service_area_zips'] ) ) {
			$out['service_area_zips'] = sanitize_textarea_field( (string) $input['service_area_zips'] );
		}

		if ( isset( $input['timezone'] ) && Time::is_valid_zone( (string) $input['timezone'] ) ) {
			$out['timezone'] = (string) $input['timezone'];
		}

		// Secrets are encrypted before they touch the options table, and a
		// masked placeholder coming back from the UI means "leave it alone".
		foreach ( SecretMasker::encrypted_setting_keys() as $key ) {
			if ( ! isset( $input[ $key ] ) || SecretMasker::MASK === $input[ $key ] || '' === $input[ $key ] ) {
				continue;
			}

			$out[ $key ] = Crypto::encrypt( sanitize_text_field( (string) $input[ $key ] ) );
		}

		return $out;
	}
}
