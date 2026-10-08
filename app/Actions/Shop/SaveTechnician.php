<?php

declare(strict_types=1);

namespace App\Actions\Shop;

use App\Actions\Audit\AuditTrail;
use App\Models\Bay;
use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Technicians belong to one branch for life. A home bay must be in the same
 * branch (also a composite foreign key); a linked login must be a staff user
 * of the same organization, linked to at most one technician.
 */
final class SaveTechnician
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveTechnicianRequest, branch_id included
     */
    public function create(array $attributes): Technician
    {
        return DB::transaction(function () use ($attributes): Technician {
            $this->assertConsistent($attributes, null);

            $technician = new Technician;
            $technician->forceFill($attributes)->save();
            $this->audit->record($technician, 'created', null, AuditTrail::snapshot($technician));

            return $technician;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveTechnicianRequest
     */
    public function update(Technician $technician, array $attributes): Technician
    {
        return DB::transaction(function () use ($technician, $attributes): Technician {
            $locked = Technician::query()->lockForUpdate()->findOrFail($technician->id);
            $this->assertConsistent(['branch_id' => $locked->branch_id] + $attributes, $locked->id);

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertConsistent(array $attributes, ?string $except): void
    {
        $branchId = $attributes['branch_id'] ?? null;

        if (array_key_exists('name', $attributes) && Technician::query()
            ->where('branch_id', $branchId)
            ->where('name', $attributes['name'])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists()) {
            throw ValidationException::withMessages(['name' => 'This branch already has a technician with that name.']);
        }

        $bayId = $attributes['home_bay_id'] ?? null;
        if ($bayId !== null && ! Bay::query()->whereKey($bayId)->where('branch_id', $branchId)->exists()) {
            throw ValidationException::withMessages(['home_bay_id' => 'The home bay must be in the technician\'s own branch.']);
        }

        $userId = $attributes['user_id'] ?? null;
        if ($userId !== null) {
            if (! User::query()->whereKey($userId)->where('side', 'staff')->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Link a staff account from your organization.']);
            }
            if (Technician::query()->where('user_id', $userId)->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists()) {
                throw ValidationException::withMessages(['user_id' => 'That account is already linked to another technician.']);
            }
        }
    }
}
