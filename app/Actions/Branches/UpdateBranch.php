<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Actions\Audit\AuditTrail;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpdateBranch
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by UpdateBranchRequest
     */
    public function handle(Branch $branch, array $attributes): Branch
    {
        return DB::transaction(function () use ($branch, $attributes): Branch {
            $locked = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            if (array_key_exists('slug', $attributes)
                && Branch::query()->where('slug', $attributes['slug'])->whereKeyNot($locked->id)->exists()) {
                throw ValidationException::withMessages(['slug' => 'Another branch already uses this slug.']);
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}
