<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/** Days past the due date, as an aging report buckets them. */
enum AgingBucket: string
{
    case Current = 'current';
    case Days1To30 = 'days_1_30';
    case Days31To60 = 'days_31_60';
    case Days61To90 = 'days_61_90';
    case Over90 = 'over_90';

    public static function forDaysPastDue(int $days): self
    {
        return match (true) {
            $days <= 0 => self::Current,
            $days <= 30 => self::Days1To30,
            $days <= 60 => self::Days31To60,
            $days <= 90 => self::Days61To90,
            default => self::Over90,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Current',
            self::Days1To30 => '1–30 days',
            self::Days31To60 => '31–60 days',
            self::Days61To90 => '61–90 days',
            self::Over90 => 'Over 90 days',
        };
    }
}
