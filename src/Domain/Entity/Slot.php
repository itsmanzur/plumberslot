<?php
/**
 * A single bookable moment.
 *
 * @package PlumberSlot
 */

declare( strict_types = 1 );

namespace PlumberSlot\Domain\Entity;

use DateTimeImmutable;

defined( 'ABSPATH' ) || exit;

final class Slot implements \JsonSerializable {

	public const STATE_OPEN   = 'open';
	public const STATE_BOOKED = 'booked';
	public const STATE_HELD   = 'held';

	public function __construct(
		public readonly DateTimeImmutable $start,
		public readonly string $state = self::STATE_OPEN
	) {}

	public function is_open(): bool {
		return self::STATE_OPEN === $this->state;
	}

	/**
	 * @return array{start: string, state: string}
	 */
	public function jsonSerialize(): array {
		return array(
			'start' => $this->start->format( DATE_ATOM ),
			'state' => $this->state,
		);
	}
}
