<?php

declare(strict_types=1);

namespace App\Actions\Parts;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\ConflictException;
use App\Models\CustomerAccount;
use App\Models\FleetPart;
use App\Models\FleetPartUsage;
use App\Models\PurchaseOrderLine;
use App\Models\ServiceTask;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer account's spare-parts catalogue: SKU unique within the account,
 * and the service tasks each part is consumed by (the forecast's input).
 *
 * Stock: an opening count on create; after that it changes only by receiving
 * a purchase order (App\Actions\PurchaseOrders\ProgressPurchaseOrder), never
 * by an edit.
 */
final class SaveFleetPart
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveFleetPartRequest
     * @param  list<array{service_task_id: string, quantity_per_service: int}>|null  $usages
     */
    public function create(CustomerAccount $account, array $attributes, ?array $usages): FleetPart
    {
        return DB::transaction(function () use ($account, $attributes, $usages): FleetPart {
            CustomerAccount::query()->lockForUpdate()->findOrFail($account->id);
            $this->assertSkuFree($account->id, $attributes['sku'] ?? null, null);

            $part = new FleetPart;
            $part->forceFill($attributes + [
                'customer_account_id' => $account->id,
                'position' => $this->nextPosition($account->id),
            ])->save();
            $this->writeUsages($part, $usages ?? []);
            $this->audit->record($part, 'created', null, self::snapshot($part));

            return $part->load('usages');
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveFleetPartRequest (no stock, no account)
     * @param  list<array{service_task_id: string, quantity_per_service: int}>|null  $usages  null: unchanged
     */
    public function update(FleetPart $part, array $attributes, ?array $usages): FleetPart
    {
        return DB::transaction(function () use ($part, $attributes, $usages): FleetPart {
            $locked = FleetPart::query()->lockForUpdate()->findOrFail($part->id);
            if (array_key_exists('sku', $attributes)) {
                $this->assertSkuFree($locked->customer_account_id, $attributes['sku'], $locked->id);
            }

            $before = self::snapshot($locked);
            $locked->forceFill($attributes)->save();
            if ($usages !== null) {
                $this->writeUsages($locked, $usages);
            }
            $this->audit->record($locked, 'updated', $before, self::snapshot($locked));

            return $locked->load('usages');
        });
    }

    /** A part never ordered may go; one on a purchase order is deactivated instead (409). */
    public function delete(FleetPart $part): void
    {
        DB::transaction(function () use ($part): void {
            $locked = FleetPart::query()->lockForUpdate()->findOrFail($part->id);
            if (PurchaseOrderLine::query()->where('fleet_part_id', $locked->id)->exists()) {
                throw new ConflictException('This part is on purchase orders. Deactivate it (is_active: false) instead.');
            }

            $before = self::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }

    /**
     * Replaces the part's usages, keeping the account's usage order: new
     * links go after every existing one.
     *
     * @param  list<array{service_task_id: string, quantity_per_service: int}>  $usages
     */
    private function writeUsages(FleetPart $part, array $usages): void
    {
        $taskIds = array_column($usages, 'service_task_id');
        $known = ServiceTask::query()->whereIn('id', $taskIds)->pluck('id')->all();
        foreach ($usages as $i => $usage) {
            if (! in_array($usage['service_task_id'], $known, true)) {
                throw ValidationException::withMessages(["usages.{$i}.service_task_id" => 'That service task is not in your catalogue.']);
            }
        }

        $existing = FleetPartUsage::query()->where('fleet_part_id', $part->id)->get()->keyBy('service_task_id');
        $next = $this->nextUsagePosition($part->customer_account_id);
        foreach ($usages as $usage) {
            $row = $existing->get($usage['service_task_id']) ?? new FleetPartUsage;
            $row->forceFill([
                'fleet_part_id' => $part->id,
                'service_task_id' => $usage['service_task_id'],
                'quantity_per_service' => $usage['quantity_per_service'],
                'position' => $row->exists ? $row->position : $next++,
            ])->save();
        }
        FleetPartUsage::query()->where('fleet_part_id', $part->id)->whereNotIn('service_task_id', $taskIds)->delete();
    }

    private function assertSkuFree(string $accountId, mixed $sku, ?string $except): void
    {
        if (is_string($sku) && FleetPart::query()->where('customer_account_id', $accountId)->where('sku', $sku)->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['sku' => 'This account already has a part with that SKU.']);
        }
    }

    private function nextPosition(string $accountId): int
    {
        $max = FleetPart::query()->where('customer_account_id', $accountId)->max('position');

        return is_numeric($max) ? (int) $max + 1 : 0;
    }

    private function nextUsagePosition(string $accountId): int
    {
        $max = FleetPartUsage::query()->whereIn('fleet_part_id', FleetPart::query()->select('id')->where('customer_account_id', $accountId))->max('position');

        return is_numeric($max) ? (int) $max + 1 : 0;
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(FleetPart $part): array
    {
        return AuditTrail::snapshot($part) + [
            'usages' => array_values($part->usages()->get()->map(fn (FleetPartUsage $u): array => ['service_task_id' => $u->service_task_id, 'quantity_per_service' => $u->quantity_per_service])->all()),
        ];
    }
}
