<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

use App\Domain\Access\Capability;

/**
 * The machine's answer: legal (and the capability it needs, resolved by the
 * caller — the machine stays pure), or refused with the reason to show.
 */
final readonly class TransitionCheck
{
    private function __construct(
        public bool $ok,
        public ?Capability $capability,
        public ?string $reason,
    ) {}

    public static function allow(?Capability $capability): self
    {
        return new self(true, $capability, null);
    }

    public static function deny(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
