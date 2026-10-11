<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * What a membership tier does to a detailing bill: a percentage off each
 * line, and the tier's free add-ons at no charge. The discount is per line
 * amount, rounded half up to a centavo; it is the invoice that totals (R6).
 */
final readonly class MembershipBenefits
{
    /**
     * @param  list<string>  $freeAddonIds
     */
    public function __construct(
        public string $tierName,
        public string $discountPct,
        public bool $priorityBooking,
        public array $freeAddonIds,
    ) {}

    /** The discount on a line worth `$grossCents`. A free add-on is discounted in full. */
    public function discountCents(int $grossCents, ?string $addonId = null): int
    {
        if ($addonId !== null && in_array($addonId, $this->freeAddonIds, true)) {
            return $grossCents;
        }

        return BigDecimal::of($grossCents)->multipliedBy($this->discountPct)->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
    }
}
