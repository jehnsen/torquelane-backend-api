<?php

declare(strict_types=1);

namespace App\Domain\Tax;

use InvalidArgumentException;

/** A calendar quarter, the period BIR's quarterly returns and a 2307 cover. Dates are business dates (Y-m-d). */
final readonly class Quarter
{
    public function __construct(public int $year, public int $quarter)
    {
        if ($quarter < 1 || $quarter > 4 || $year < 2000 || $year > 2999) {
            throw new InvalidArgumentException("There is no quarter {$quarter} of {$year}.");
        }
    }

    /** The quarter a business date falls in. */
    public static function of(string $date): self
    {
        return new self((int) substr($date, 0, 4), intdiv((int) substr($date, 5, 2) - 1, 3) + 1);
    }

    public function startsOn(): string
    {
        return sprintf('%04d-%02d-01', $this->year, ($this->quarter - 1) * 3 + 1);
    }

    public function endsOn(): string
    {
        $month = $this->quarter * 3;

        return sprintf('%04d-%02d-%02d', $this->year, $month, in_array($month, [6, 9], true) ? 30 : 31);
    }

    /** 1, 2 or 3: which month of the quarter a date in it falls in (the columns of a 2307). */
    public function monthOf(string $date): int
    {
        if ($date < $this->startsOn() || $date > $this->endsOn()) {
            throw new InvalidArgumentException("{$date} is outside {$this->label()}.");
        }

        return ((int) substr($date, 5, 2) - 1) % 3 + 1;
    }

    public function label(): string
    {
        return "Q{$this->quarter} {$this->year}";
    }
}
