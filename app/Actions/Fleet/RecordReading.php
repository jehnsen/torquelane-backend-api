<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Audit\AuditTrail;
use App\Domain\Fleet\OdometerValidation;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\Num;
use App\Exceptions\ConflictException;
use App\Models\MeterReading;
use App\Models\Vehicle;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Appends an odometer reading, gated by the ported validation (exactly the
 * frontend's rules and messages):
 *  - below the current reading → refused;
 *  - implying over 3× or under 0.1× the vehicle's average daily distance →
 *    a warning, accepted only with `confirm_warning`;
 *  - otherwise accepted.
 * Plus the API's own date rules: not in the future, not before the current
 * reading's date (a back-dated reading would rewrite the derived rate).
 *
 * The vehicle row is locked, so two readings cannot both validate against
 * the same "current" value.
 */
final class RecordReading
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
        private readonly FleetQueries $fleet,
    ) {}

    public function handle(Vehicle $vehicle, string $value, ?string $readOn, bool $confirmWarning, string $source): MeterReading
    {
        $context = $this->tenancy->require();

        return DB::transaction(function () use ($context, $vehicle, $value, $readOn, $confirmWarning, $source): MeterReading {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            if ($locked->isArchived()) {
                throw new ConflictException('This vehicle is archived; it takes no new readings.');
            }

            $facts = $this->fleet->vehicleFacts($locked);
            $today = $this->fleet->todayDate();
            $readOn ??= $today;

            if ($readOn > $today) {
                throw ValidationException::withMessages(['read_on' => 'A reading cannot be dated in the future.']);
            }
            if ($readOn < $facts->odometerReadAt) {
                throw ValidationException::withMessages(['read_on' => 'A reading cannot be dated before the current one ('.$facts->odometerReadAt.').']);
            }

            $validation = OdometerValidation::validate($facts, Num::of(BigDecimal::of($value)), Calendar::parseDate($readOn));
            if ($validation->status === OdometerValidation::INVALID) {
                throw ValidationException::withMessages(['value' => (string) $validation->error]);
            }
            if ($validation->status === OdometerValidation::WARNING && ! $confirmWarning) {
                throw ValidationException::withMessages([
                    'value' => (string) $validation->warning,
                    'confirm_warning' => 'Check the reading, then send it again with confirm_warning to save it anyway.',
                ]);
            }

            $reading = new MeterReading;
            $reading->forceFill([
                'asset_type' => Vehicle::ASSET_TYPE,
                'asset_id' => $locked->id,
                'vehicle_id' => $locked->id,
                'meter_kind' => MeterKind::Km,
                'value' => $value,
                'read_on' => $readOn,
                'source' => $source,
                'recorded_by' => $context->userId,
            ])->save();

            $this->audit->record($reading, 'recorded', null, AuditTrail::snapshot($reading) + [
                'validation' => $validation->status,
                'implied_daily_km' => $validation->impliedDailyKm,
            ]);

            return $reading;
        });
    }

    /**
     * Voids a wrong reading by appending a correction row; nothing is edited
     * or deleted. The current reading and daily rate are re-derived from
     * what remains.
     */
    public function void(Vehicle $vehicle, MeterReading $reading, string $reason): MeterReading
    {
        $context = $this->tenancy->require();

        return DB::transaction(function () use ($context, $vehicle, $reading, $reason): MeterReading {
            $locked = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);

            if ($reading->vehicle_id !== $locked->id || $reading->isVoid()) {
                throw new ConflictException('Only a reading of this vehicle can be voided.');
            }
            if (MeterReading::query()->where('voids_reading_id', $reading->id)->exists()) {
                throw new ConflictException('This reading is already void.');
            }
            $remaining = $this->fleet->effectiveReadings([$locked->id])[$locked->id] ?? [];
            if (count($remaining) <= 1) {
                throw new ConflictException('A vehicle keeps at least one reading. Record the correct one first, then void this.');
            }

            $void = new MeterReading;
            $void->forceFill([
                'asset_type' => Vehicle::ASSET_TYPE,
                'asset_id' => $locked->id,
                'vehicle_id' => $locked->id,
                'meter_kind' => $reading->meter_kind,
                'value' => null,
                'read_on' => $this->fleet->todayDate(),
                'source' => 'correction',
                'recorded_by' => $context->userId,
                'voids_reading_id' => $reading->id,
                'void_reason' => $reason,
            ])->save();

            $this->audit->record($void, 'voided', AuditTrail::snapshot($reading), AuditTrail::snapshot($void));

            return $void;
        });
    }
}
