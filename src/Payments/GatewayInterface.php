<?php
/**
 * Contract every payment gateway implements.
 *
 * PlumberSlot never sees a card number. A gateway hands back a redirect URL and
 * later confirms through a signed webhook, which keeps the plugin's PCI scope
 * at zero and keeps card data out of the WordPress database entirely.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Payments;

use WP_Error;

defined( 'ABSPATH' ) || exit;

interface GatewayInterface {

	/**
	 * Machine id, for example 'stripe' or 'bkash'.
	 */
	public function id(): string;

	/**
	 * Name shown at checkout.
	 */
	public function label(): string;

	public function is_configured(): bool;

	/**
	 * Start a payment.
	 *
	 * @return array{url:string, reference:string}|WP_Error Redirect URL + provider reference.
	 */
	public function start(
		int $booking_id,
		int $amount_minor,
		string $currency,
		string $success_url,
		string $cancel_url
	): array|WP_Error;

	/**
	 * Verify a webhook signature.
	 *
	 * @param string                $raw_body Unparsed request body.
	 * @param array<string, string> $headers  Request headers.
	 */
	public function verify_webhook( string $raw_body, array $headers ): bool;

	/**
	 * Read a verified webhook.
	 *
	 * @return array{booking_id:int, status:string, reference:string, idempotency_key:string, amount_minor?:int, currency?:string}|WP_Error
	 */
	public function parse_webhook( string $raw_body ): array|WP_Error;

	/**
	 * Refund a captured payment. Callers must supply a stable idempotency key.
	 */
	public function refund( string $reference, int $amount_minor, string $idempotency_key = '' ): bool|WP_Error;
}
