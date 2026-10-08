<?php

declare(strict_types=1);

namespace App\Domain\CheckIn;

/**
 * idle (too little typed), new (no vehicle in scope matches: carry the typed
 * identifier into a new-vehicle form), or existing (with the last reading
 * and whether it is too old to trust).
 */
final readonly class CheckInResult
{
    public function __construct(
        /** idle | new | existing */
        public string $outcome,
        /** plate | vin, for existing */
        public ?string $matchedOn = null,
        public ?CheckInCandidate $candidate = null,
        public string $plateNumber = '',
        public ?string $vin = null,
        public float|int|null $lastOdometer = null,
        public ?string $lastOdometerReadAt = null,
        public ?int $odometerAgeDays = null,
        public bool $odometerStale = false,
    ) {}

    public static function idle(): self
    {
        return new self('idle');
    }

    public static function new(string $plateNumber, ?string $vin): self
    {
        return new self('new', plateNumber: $plateNumber, vin: $vin);
    }

    public static function existing(string $matchedOn, CheckInCandidate $candidate, int $ageDays, bool $stale): self
    {
        return new self(
            'existing',
            matchedOn: $matchedOn,
            candidate: $candidate,
            lastOdometer: $candidate->vehicle->odometer,
            lastOdometerReadAt: $candidate->vehicle->odometerReadAt,
            odometerAgeDays: $ageDays,
            odometerStale: $stale,
        );
    }
}
