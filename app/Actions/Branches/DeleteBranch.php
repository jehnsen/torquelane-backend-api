<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\ConflictException;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\DocumentSeries;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\Technician;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a branch that holds nothing. Anything attached (bays, technicians,
 * pinned staff, document series) makes it a 409: mark it inactive instead.
 *
 * Pinned staff matter most: a user pinned only to this branch would, once the
 * pivot row cascaded away, have no pins left, which means EVERY branch. A
 * delete must never widen anyone's access.
 */
final class DeleteBranch
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Branch $branch): void
    {
        DB::transaction(function () use ($branch): void {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            $attached = array_filter([
                'bays' => Bay::query()->where('branch_id', $locked->id)->exists(),
                'technicians' => Technician::query()->where('branch_id', $locked->id)->exists(),
                'pinned staff' => DB::table('branch_user')->where('branch_id', $locked->id)->exists(),
                'document series' => DocumentSeries::query()->where('branch_id', $locked->id)->exists(),
                'stock history' => StockMove::query()->where('branch_id', $locked->id)->exists(),
            ]);
            if ($attached !== []) {
                throw new ConflictException(
                    'This branch still has '.implode(', ', array_keys($attached)).'. Mark it inactive instead.',
                    ['attached' => array_keys($attached)],
                );
            }

            // An empty store goes with its branch.
            StockLocation::query()->where('branch_id', $locked->id)->delete();
            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }
}
