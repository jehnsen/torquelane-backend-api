<?php

declare(strict_types=1);

namespace App\Actions\Shop;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\ConflictException;
use App\Models\Bay;
use App\Models\Technician;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a bay or a technician. A bay that is some technician's home bay is a
 * 409; mark it inactive or move the technician first. (Later phases add work
 * orders that reference both; those will make them inactive-only.)
 */
final class DeleteShopRecord
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function bay(Bay $bay): void
    {
        DB::transaction(function () use ($bay): void {
            $locked = Bay::query()->lockForUpdate()->findOrFail($bay->id);
            if (Technician::query()->where('home_bay_id', $locked->id)->exists()) {
                throw new ConflictException('This bay is a technician\'s home bay. Reassign them or mark the bay inactive.');
            }
            // Work orders keep the bay they were booked into (the key restricts).
            if (WorkOrder::query()->where('bay_id', $locked->id)->exists()) {
                throw new ConflictException('Work orders were booked into this bay. Mark it inactive instead.');
            }

            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }

    public function technician(Technician $technician): void
    {
        DB::transaction(function () use ($technician): void {
            $locked = Technician::query()->lockForUpdate()->findOrFail($technician->id);
            // Work orders keep the technician who did them (the key restricts).
            if (WorkOrder::query()->where('technician_id', $locked->id)->exists()) {
                throw new ConflictException('This technician is on work orders. Mark them inactive instead.');
            }

            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }
}
