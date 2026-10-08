<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use App\Domain\Shared\WebFormat;
use DateTimeImmutable;

/**
 * Port of ../web/lib/odometer-validation.ts `validateOdometerReading`. Gates
 * every reading: an `invalid` one is refused; a `warning` one is accepted
 * only when the caller confirms it. Messages are verbatim (golden-tested).
 */
final readonly class OdometerValidation
{
    public const string INVALID = 'invalid';

    public const string WARNING = 'warning';

    public const string OK = 'ok';

    private function __construct(
        public string $status,
        public ?string $error,
        public ?string $warning,
        /** km/day implied against the last reading; null when rejected. */
        public float|int|null $impliedDailyKm,
    ) {}

    public static function validate(VehicleFacts $vehicle, float|int $reading, DateTimeImmutable $readAt): self
    {
        if ($reading < $vehicle->odometer) {
            return new self(self::INVALID, sprintf(
                'Cannot be lower than the last recorded reading of %s on %s.',
                WebFormat::km($vehicle->odometer),
                WebFormat::date($vehicle->odometerReadAt),
            ), null, null);
        }

        // Same-day re-readings would otherwise divide by zero: a one-day gap.
        $daysSince = max(1, Calendar::differenceInCalendarDays($readAt, Calendar::parseDate($vehicle->odometerReadAt)));
        $implied = ($reading - $vehicle->odometer) / $daysSince;

        if ($vehicle->avgDailyKm > 0) {
            $high = $vehicle->avgDailyKm * FleetThresholds::ODOMETER_RATE_HIGH_MULTIPLIER;
            $low = $vehicle->avgDailyKm * FleetThresholds::ODOMETER_RATE_LOW_MULTIPLIER;

            if ($implied > $high) {
                return new self(self::WARNING, null, sprintf(
                    "This implies %s a day — over 3x this vehicle's average of %s a day. Double-check the reading before saving.",
                    WebFormat::km(JsMath::round($implied)),
                    WebFormat::km(JsMath::round($vehicle->avgDailyKm)),
                ), $implied);
            }

            if ($implied < $low) {
                return new self(self::WARNING, null, sprintf(
                    "This implies only %s a day — well under this vehicle's average of %s a day. That usually means an old reading was re-entered.",
                    WebFormat::km(JsMath::round($implied)),
                    WebFormat::km(JsMath::round($vehicle->avgDailyKm)),
                ), $implied);
            }
        }

        return new self(self::OK, null, null, $implied);
    }
}
