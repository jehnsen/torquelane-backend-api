<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/**
 * Where an account stands against its credit limit: what it owes on open
 * invoices, less the credit it holds (payments not yet allocated). Over the
 * limit WARNS; it never blocks new work (the override is logged).
 */
final readonly class CreditPosition
{
    public function __construct(
        /** Null = no limit set. */
        public ?int $creditLimitCents,
        /** Balance of the open invoices. */
        public int $outstandingCents,
        /** Payments received and not allocated. */
        public int $creditCents,
    ) {}

    public function exposureCents(): int
    {
        return $this->outstandingCents - $this->creditCents;
    }

    public function isOverLimit(): bool
    {
        return $this->creditLimitCents !== null && $this->exposureCents() > $this->creditLimitCents;
    }

    /** Headroom left under the limit (negative when over); null with no limit. */
    public function availableCents(): ?int
    {
        return $this->creditLimitCents === null ? null : $this->creditLimitCents - $this->exposureCents();
    }
}
