<?php

declare(strict_types=1);

namespace App\Actions\Equipment;

use App\Actions\Audit\AuditTrail;
use App\Actions\Inventory\PostStockMove;
use App\Actions\Inventory\StockLocations;
use App\Domain\Equipment\EquipmentVocabulary;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Exceptions\ConflictException;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\DetailingBooking;
use App\Models\DetailingPackage;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceLog;
use App\Models\EquipmentMaintenancePart;
use App\Models\EquipmentPackageProfile;
use App\Models\EquipmentUsageLog;
use App\Models\Item;
use App\Models\User;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The equipment register and what is logged against it: the source's
 * `upsertEquipment`, `updateEquipmentStatus`, `logEquipmentUsage`,
 * `logEquipmentService` and `setServiceEquipmentProfile`.
 *
 * Nothing here writes a meter. A usage entry and a service entry are facts on
 * record; the monitor derives every figure from them when it is read.
 *
 * A service entry may take parts from the branch's store. They leave through
 * the stock ledger (R7), which books them to Equipment Repairs & Maintenance;
 * removing the entry puts them back the same way.
 */
final class ManageEquipment
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly TenantManager $tenancy,
        private readonly EquipmentQueries $queries,
        private readonly PostStockMove $ledger,
        private readonly StockLocations $locations,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated; only the keys present change on an edit
     */
    public function save(Branch $branch, ?Equipment $tool, array $data): Equipment
    {
        return DB::transaction(function () use ($branch, $tool, $data): Equipment {
            $before = $tool === null ? null : AuditTrail::snapshot($tool);
            $tool ??= (new Equipment)->forceFill(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id]);

            if (isset($data['name']) && is_string($data['name'])) {
                $name = trim($data['name']);
                $taken = Equipment::query()->where('branch_id', $branch->id)->whereRaw('lower(name) = ?', [mb_strtolower($name)])
                    ->when($tool->exists, fn ($query) => $query->whereKeyNot($tool->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['name' => 'Another tool of this branch has that name.']);
                }
                $data['name'] = $name;
            }
            if (isset($data['bay_id']) && ! Bay::query()->where('branch_id', $branch->id)->whereKey($data['bay_id'])->exists()) {
                throw ValidationException::withMessages(['bay_id' => 'That bay is not in this branch.']);
            }
            if (! $tool->exists && ! isset($data['position'])) {
                $max = Equipment::query()->where('branch_id', $branch->id)->max('position');
                $data['position'] = (is_int($max) ? $max : 0) + 10;
            }

            $tool->forceFill($data)->save();
            $this->audit->record($tool, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($tool));

            return $tool;
        });
    }

    /** Available, in use, under maintenance, down for repair, retired. */
    public function setStatus(Equipment $tool, string $status): Equipment
    {
        return DB::transaction(function () use ($tool, $status): Equipment {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($tool->id);
            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['status' => $status])->save();
            $this->audit->record($locked, 'status_changed', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /** A tool with nothing logged against it and no package mapped may be removed; any other is retired instead. */
    public function delete(Equipment $tool): void
    {
        DB::transaction(function () use ($tool): void {
            $used = EquipmentUsageLog::query()->where('equipment_id', $tool->id)->exists()
                || EquipmentMaintenanceLog::query()->where('equipment_id', $tool->id)->exists()
                || EquipmentPackageProfile::query()->where('equipment_id', $tool->id)->exists();
            if ($used) {
                throw new ConflictException("{$tool->name} has a history; retire it instead of deleting it.");
            }
            $this->audit->record($tool, 'deleted', AuditTrail::snapshot($tool), null);
            $tool->delete();
        });
    }

    /**
     * Runtime the booking sheet never saw.
     *
     * @param  array{hours?: string|null, cycles?: int|null, used_at?: string|null, detailing_booking_id?: string|null, note?: string|null}  $data
     */
    public function logUsage(Equipment $tool, array $data): EquipmentUsageLog
    {
        $hours = BigDecimal::of($data['hours'] ?? '0');
        $cycles = max(0, $data['cycles'] ?? 0);
        if (! $hours->isPositive() && $cycles <= 0) {
            throw ValidationException::withMessages(['hours' => 'Enter runtime hours or job cycles.']);
        }
        $usedAt = isset($data['used_at']) ? CarbonImmutable::parse($data['used_at'])->utc() : CarbonImmutable::now();
        if ($usedAt->isFuture()) {
            throw ValidationException::withMessages(['used_at' => 'Usage is logged once it has happened.']);
        }
        $bookingId = $data['detailing_booking_id'] ?? null;
        if ($bookingId !== null && ! DetailingBooking::query()->where('branch_id', $tool->branch_id)->whereKey($bookingId)->exists()) {
            throw ValidationException::withMessages(['detailing_booking_id' => 'That booking is not of this branch.']);
        }

        return DB::transaction(function () use ($tool, $data, $hours, $cycles, $usedAt, $bookingId): EquipmentUsageLog {
            $actor = $this->actor();
            $log = new EquipmentUsageLog;
            $log->forceFill([
                'organization_id' => $tool->organization_id,
                'branch_id' => $tool->branch_id,
                'equipment_id' => $tool->id,
                'detailing_booking_id' => $bookingId,
                'hours' => $hours,
                'cycles' => $cycles,
                'used_at' => $usedAt,
                'note' => self::text($data['note'] ?? null),
                'logged_by' => $actor->id,
                'logged_by_name' => $actor->name,
            ])->save();
            $this->audit->record($log, 'logged', null, AuditTrail::snapshot($log));

            return $log;
        });
    }

    public function deleteUsage(EquipmentUsageLog $log): void
    {
        DB::transaction(function () use ($log): void {
            $this->audit->record($log, 'deleted', AuditTrail::snapshot($log), null);
            $log->delete();
        });
    }

    /**
     * A service, a repair, an inspection, a calibration or a part replaced.
     * Unless it is told not to reset the service clock, it stamps the tool's
     * `last_service_at` with when the work was done. The tool's derived meters
     * as they read now are kept with the entry.
     *
     * `issued_parts` are taken from the branch's store as the entry is saved:
     * one `consumption` move each, at the average cost, dated now (stock
     * leaves the shelf when it is recorded, whenever the work was done).
     *
     * @param  array{kind?: string, performed_at?: string|null, performed_by?: string|null, cost_cents?: int, downtime_hours?: string|null, parts?: string|null, notes?: string|null, resets_service?: bool, issued_parts?: list<array{item_id: string, quantity: string}>}  $data
     */
    public function logService(Equipment $tool, array $data): EquipmentMaintenanceLog
    {
        $performedAt = isset($data['performed_at']) ? CarbonImmutable::parse($data['performed_at'])->utc() : CarbonImmutable::now();
        if ($performedAt->isFuture()) {
            throw ValidationException::withMessages(['performed_at' => 'A service is logged once it has been done.']);
        }
        $issued = $data['issued_parts'] ?? [];
        $seen = [];
        foreach ($issued as $index => $part) {
            if (isset($seen[$part['item_id']])) {
                throw ValidationException::withMessages(["issued_parts.{$index}.item_id" => 'That item is listed twice.']);
            }
            $seen[$part['item_id']] = true;
            $item = Item::query()->find($part['item_id']);
            if (! $item instanceof Item || ! $item->is_stocked) {
                throw ValidationException::withMessages(["issued_parts.{$index}.item_id" => 'Pick an item the stock room keeps.']);
            }
        }

        return DB::transaction(function () use ($tool, $data, $performedAt, $issued): EquipmentMaintenanceLog {
            $locked = Equipment::query()->lockForUpdate()->findOrFail($tool->id);
            $meters = $this->queries->lifetime($locked);
            $actor = $this->actor();
            $resets = $data['resets_service'] ?? true;
            $kind = $data['kind'] ?? 'preventive';

            $log = new EquipmentMaintenanceLog;
            $log->forceFill([
                'organization_id' => $locked->organization_id,
                'branch_id' => $locked->branch_id,
                'equipment_id' => $locked->id,
                'kind' => $kind,
                'performed_at' => $performedAt,
                'performed_by' => self::text($data['performed_by'] ?? null),
                'cost_cents' => $data['cost_cents'] ?? 0,
                'downtime_hours' => BigDecimal::of($data['downtime_hours'] ?? '0'),
                'parts' => self::text($data['parts'] ?? null),
                'notes' => self::text($data['notes'] ?? null),
                'meter_hours' => BigDecimal::of(sprintf('%.3F', $meters['hours'])),
                'meter_cycles' => $meters['cycles'],
                'resets_service' => $resets,
                'recorded_by' => $actor->id,
                'recorded_by_name' => $actor->name,
            ])->save();

            if ($issued !== []) {
                $location = $this->locations->storeOf($locked->branch_id);
                $this->ledger->lock(array_map(fn (array $part): array => ['location' => $location, 'item_id' => $part['item_id']], $issued));
                $partsCost = 0;
                foreach ($issued as $part) {
                    $move = $this->ledger->handle(
                        $location,
                        $part['item_id'],
                        new MoveRequest(MoveType::Consumption, BigDecimal::of($part['quantity'])->negated()),
                        StockSource::EquipmentMaintenance,
                        $log->id,
                        sprintf('%s: %s', $locked->name, EquipmentVocabulary::MAINTENANCE_KINDS[$kind] ?? $kind),
                    );
                    $value = $move->valueCents();
                    (new EquipmentMaintenancePart)->forceFill([
                        'organization_id' => $locked->organization_id,
                        'maintenance_log_id' => $log->id,
                        'item_id' => $part['item_id'],
                        'quantity' => $move->quantity->abs(),
                        'unit_cost_cents' => $move->unit_cost_cents,
                        'value_cents' => $value,
                        'stock_move_id' => $move->id,
                    ])->save();
                    $partsCost += $value;
                }
                $log->forceFill(['parts_cost_cents' => $partsCost])->save();
            }
            $this->audit->record($log, 'logged', null, AuditTrail::snapshot($log));

            if ($resets) {
                $before = AuditTrail::snapshot($locked);
                $locked->forceFill(['last_service_at' => $performedAt])->save();
                $this->audit->record($locked, 'serviced', $before, AuditTrail::snapshot($locked));
            }

            return $log;
        });
    }

    /**
     * As in the source, removing a service entry leaves the tool's service
     * clock where that entry set it. The parts it took go back on the shelf
     * at what they left at, and the books take the expense back.
     */
    public function deleteService(EquipmentMaintenanceLog $log): void
    {
        DB::transaction(function () use ($log): void {
            $locked = EquipmentMaintenanceLog::query()->lockForUpdate()->findOrFail($log->id);
            $parts = $locked->issuedParts()->get();
            if ($parts->isNotEmpty()) {
                $tool = Equipment::query()->findOrFail($locked->equipment_id);
                $location = $this->locations->storeOf($locked->branch_id);
                $this->ledger->lock(array_values($parts->map(fn (EquipmentMaintenancePart $part): array => ['location' => $location, 'item_id' => $part->item_id])->all()));
                foreach ($parts as $part) {
                    $this->ledger->handle(
                        $location,
                        $part->item_id,
                        new MoveRequest(MoveType::Return, $part->quantity, $part->unit_cost_cents),
                        StockSource::EquipmentMaintenance,
                        $locked->id,
                        "{$tool->name}: service entry removed",
                    );
                }
            }
            $this->audit->record($locked, 'deleted', AuditTrail::snapshot($locked), null);
            $locked->delete();
        });
    }

    /**
     * Replace a package's whole equipment profile.
     *
     * @param  list<array{equipment_id: string, hours_per_job: string}>  $lines
     */
    public function setProfile(DetailingPackage $package, array $lines): void
    {
        DB::transaction(function () use ($package, $lines): void {
            $ids = array_values(array_unique(array_map(fn (array $line): string => $line['equipment_id'], $lines)));
            $known = $ids === [] ? [] : Equipment::query()->where('branch_id', $package->branch_id)->whereIn('id', $ids)->pluck('id')->all();

            EquipmentPackageProfile::query()->where('package_id', $package->id)->delete();
            $seen = [];
            foreach ($lines as $index => $line) {
                if (! in_array($line['equipment_id'], $known, true)) {
                    throw ValidationException::withMessages(["lines.{$index}.equipment_id" => 'That tool is not of this package\'s branch.']);
                }
                if (isset($seen[$line['equipment_id']])) {
                    throw ValidationException::withMessages(["lines.{$index}.equipment_id" => 'That tool is listed twice.']);
                }
                $seen[$line['equipment_id']] = true;
                (new EquipmentPackageProfile)->forceFill([
                    'organization_id' => $package->organization_id,
                    'branch_id' => $package->branch_id,
                    'package_id' => $package->id,
                    'equipment_id' => $line['equipment_id'],
                    'hours_per_job' => BigDecimal::of($line['hours_per_job']),
                ])->save();
            }
            $this->audit->record($package, 'equipment_profile_set', null, ['lines' => $lines]);
        });
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }

    private static function text(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
