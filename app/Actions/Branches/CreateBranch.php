<?php

declare(strict_types=1);

namespace App\Actions\Branches;

use App\Actions\Audit\AuditTrail;
use App\Models\Branch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CreateBranch
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * Opens a branch with every module off; switch them on per branch.
     *
     * @param  array<string, mixed>  $attributes  validated by StoreBranchRequest
     */
    public function handle(array $attributes): Branch
    {
        return DB::transaction(function () use ($attributes): Branch {
            if (Branch::query()->where('slug', $attributes['slug'] ?? null)->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['slug' => 'Another branch already uses this slug.']);
            }

            $branch = new Branch;
            $branch->forceFill($attributes)->save();
            $this->audit->record($branch, 'created', null, AuditTrail::snapshot($branch));

            return $branch;
        });
    }
}
