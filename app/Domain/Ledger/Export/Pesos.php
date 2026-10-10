<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Export;

/** Centavos as a plain decimal ("-1234.50"), without a float anywhere. */
final class Pesos
{
    public static function plain(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }
}
