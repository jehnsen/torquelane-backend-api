<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Models\Branch;
use App\Models\StockLocation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * A branch's stock locations. Every branch has exactly one 'store' (a partial
 * unique index holds it); it is created with the branch, and here on first
 * use for a branch that predates it or was made some other way.
 */
final class StockLocations
{
    public function storeOf(string $branchId): StockLocation
    {
        $existing = StockLocation::query()->where('branch_id', $branchId)->where('kind', 'store')->first();
        if ($existing instanceof StockLocation) {
            return $existing;
        }

        $branch = Branch::query()->findOrFail($branchId);
        $now = CarbonImmutable::now('UTC');
        StockLocation::query()->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'kind' => 'store',
            'name' => $branch->name.' store',
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StockLocation::query()->where('branch_id', $branchId)->where('kind', 'store')->firstOrFail();
    }
}
