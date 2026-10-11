<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use InvalidArgumentException;

/**
 * A prepaid package's money. The price is taken up front and owed as service
 * (Unearned Revenue); each credit redeemed earns its share.
 *
 * Shares are worked out cumulatively: after `n` of `total` credits, exactly
 * `floor(price × n ÷ total)` has been earned, and the last credit earns
 * whatever is left. So the redemptions of a package always add up to its
 * price, to the centavo, whatever the division (R6: rounded once).
 */
final class Prepaid
{
    public static function earnedThrough(int $priceCents, int $creditsTotal, int $creditsUsed): int
    {
        if ($creditsTotal < 1 || $creditsUsed < 0 || $creditsUsed > $creditsTotal || $priceCents < 0) {
            throw new InvalidArgumentException('Credits used run from none to all of a package.');
        }

        return $creditsUsed === $creditsTotal ? $priceCents : intdiv($priceCents * $creditsUsed, $creditsTotal);
    }

    /** What redeeming `$credits` more earns, when `$usedBefore` are already used. */
    public static function redemptionCents(int $priceCents, int $creditsTotal, int $usedBefore, int $credits): int
    {
        if ($credits < 1) {
            throw new InvalidArgumentException('A redemption uses at least one credit.');
        }

        return self::earnedThrough($priceCents, $creditsTotal, $usedBefore + $credits) - self::earnedThrough($priceCents, $creditsTotal, $usedBefore);
    }

    /** What is still owed as service: the Unearned Revenue the package carries. */
    public static function unearnedCents(int $priceCents, int $creditsTotal, int $creditsUsed): int
    {
        return $priceCents - self::earnedThrough($priceCents, $creditsTotal, $creditsUsed);
    }
}
