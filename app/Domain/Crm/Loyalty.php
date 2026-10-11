<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/** Loyalty points: whole points per so many centavos paid, never a fraction. */
final class Loyalty
{
    public static function pointsFor(int $amountCents, int $centsPerPoint): int
    {
        return $amountCents <= 0 || $centsPerPoint < 1 ? 0 : intdiv($amountCents, $centsPerPoint);
    }
}
