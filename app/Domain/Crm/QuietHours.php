<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Domain\Shared\Calendar;
use DateTimeImmutable;

/**
 * The hours no reminder, campaign or feedback request leaves in, as minutes
 * of the Manila day (R9). The window may wrap midnight (21:00 to 08:00). Equal
 * start and end means there are no quiet hours.
 */
final readonly class QuietHours
{
    public function __construct(public int $startMinutes, public int $endMinutes) {}

    public function isQuiet(DateTimeImmutable $at): bool
    {
        if ($this->startMinutes === $this->endMinutes) {
            return false;
        }
        $minute = self::minuteOfDay($at);

        return $this->startMinutes < $this->endMinutes
            ? $minute >= $this->startMinutes && $minute < $this->endMinutes
            : $minute >= $this->startMinutes || $minute < $this->endMinutes;
    }

    /** `$at` itself when it is not quiet; otherwise the moment the window ends. */
    public function nextAllowed(DateTimeImmutable $at): DateTimeImmutable
    {
        if (! $this->isQuiet($at)) {
            return $at;
        }
        $local = Calendar::local($at);
        $end = $local->setTime(intdiv($this->endMinutes, 60), $this->endMinutes % 60);

        return $end > $local ? $end : $end->modify('+1 day');
    }

    private static function minuteOfDay(DateTimeImmutable $at): int
    {
        $local = Calendar::local($at);

        return (int) $local->format('G') * 60 + (int) $local->format('i');
    }
}
