<?php

declare(strict_types=1);

namespace App\Domain\CheckIn;

use App\Domain\Fleet\Pms;
use App\Domain\Fleet\PmsItem;
use App\Domain\Fleet\VehicleHealth;
use DateTimeImmutable;

/**
 * Port of ../web/lib/checkin.ts: a plate or VIN → the counter form, so the
 * counter never asks for what the fleet already holds.
 *
 * Matching is exact on a normalised form (case, spaces and dashes ignored);
 * a prefix match would hydrate the wrong customer. The candidates are the
 * caller's SCOPED vehicles — a lookup over the raw fleet would confirm that
 * a sibling account's vehicle exists.
 */
final class CheckIn
{
    public const int MIN_LOOKUP_LENGTH = 3;

    public const int VIN_LENGTH = 17;

    public static function normalisePlate(string $input): string
    {
        return mb_strtoupper((string) preg_replace('/[\s-]/u', '', $input));
    }

    public static function normaliseVin(string $input): string
    {
        return mb_strtoupper((string) preg_replace('/\s/u', '', $input));
    }

    /**
     * @param  list<CheckInCandidate>  $candidates
     */
    public static function lookup(string $input, array $candidates, DateTimeImmutable $today): CheckInResult
    {
        $raw = trim($input);
        if (mb_strlen($raw) < self::MIN_LOOKUP_LENGTH) {
            return CheckInResult::idle();
        }

        $plate = self::normalisePlate($raw);
        $vin = self::normaliseVin($raw);

        $byPlate = null;
        foreach ($candidates as $candidate) {
            if (self::normalisePlate($candidate->vehicle->plateNumber) === $plate) {
                $byPlate = $candidate;
                break;
            }
        }
        $matched = $byPlate;
        if ($matched === null) {
            foreach ($candidates as $candidate) {
                if (self::normaliseVin($candidate->vin) === $vin) {
                    $matched = $candidate;
                    break;
                }
            }
        }

        if ($matched === null) {
            $looksLikeVin = mb_strlen($vin) === self::VIN_LENGTH;

            return CheckInResult::new($looksLikeVin ? '' : $plate, $looksLikeVin ? $vin : null);
        }

        return CheckInResult::existing(
            $byPlate !== null ? 'plate' : 'vin',
            $matched,
            Pms::odometerAgeDays($matched->vehicle, $today),
            Pms::isOdometerStale($matched->vehicle, $today),
        );
    }

    /**
     * ../web's form hydration, exactly: an existing vehicle pre-fills its last
     * reading and flags a stale one for confirmation. (The check-in ENDPOINT
     * goes further and never pre-fills a stale reading at all — see
     * CheckInLookup.)
     */
    public static function hydrate(CheckInResult $result, ?CheckInCustomer $customer): CheckInForm
    {
        if ($result->outcome === 'idle') {
            return new CheckInForm;
        }
        if ($result->outcome === 'new' || $result->candidate === null) {
            return new CheckInForm(plateNumber: $result->plateNumber, vin: $result->vin ?? '');
        }

        $c = $result->candidate;

        return new CheckInForm(
            vehicleId: $c->vehicle->id,
            plateNumber: $c->vehicle->plateNumber,
            vin: $c->vin,
            make: $c->make,
            model: $c->model,
            year: $c->year,
            vehicleClass: $c->vehicleClass,
            fuelType: $c->fuelType,
            customerName: $customer->name ?? '',
            customerContact: $customer->contactName ?? '',
            customerEmail: $customer->contactEmail ?? '',
            assignedTo: $c->vehicle->assignedTo,
            odometer: $result->lastOdometer,
            odometerNeedsConfirmation: $result->odometerStale,
            isExistingVehicle: true,
        );
    }

    /**
     * What is already due, offered while the vehicle is on the premises.
     *
     * @return list<PmsItem>
     */
    public static function suggestedWork(?VehicleHealth $health, int $limit = 5): array
    {
        if ($health === null) {
            return [];
        }

        return array_slice(array_values(array_filter($health->items, fn (PmsItem $item): bool => $item->status !== 'ok')), 0, $limit);
    }
}
