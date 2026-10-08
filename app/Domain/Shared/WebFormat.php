<?php

declare(strict_types=1);

namespace App\Domain\Shared;

/**
 * The frontend's display formatters (../web/lib/utils.ts) for the strings the
 * API now produces itself: alert titles and bodies, odometer warnings. The
 * golden fixtures compare these verbatim.
 */
final class WebFormat
{
    /** `formatKm`: `23,000 km`. */
    public static function km(float|int $value): string
    {
        return number_format((float) JsMath::round($value), 0, '.', ',').' km';
    }

    /** `formatDate`: `08 Oct 2026` (date-fns `dd MMM yyyy`). */
    public static function date(string $isoDate): string
    {
        return Calendar::parseDate($isoDate)->format('d M Y');
    }

    /** `formatDayDelta`: `Due today`, `5 days overdue`, `in 1 day`. */
    public static function dayDelta(int $days): string
    {
        if ($days === 0) {
            return 'Due today';
        }
        if ($days < 0) {
            $n = abs($days);

            return sprintf('%d %s overdue', $n, $n === 1 ? 'day' : 'days');
        }

        return sprintf('in %d %s', $days, $days === 1 ? 'day' : 'days');
    }
}
