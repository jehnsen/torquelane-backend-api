<?php

declare(strict_types=1);

namespace App\Actions\Shop;

use App\Actions\Audit\AuditTrail;
use App\Models\Bay;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bays belong to one branch for life: `branch_id` is set on create and never
 * changed, so moving a bay is retiring one and opening another.
 */
final class SaveBay
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveBayRequest, branch_id included
     */
    public function create(array $attributes): Bay
    {
        return DB::transaction(function () use ($attributes): Bay {
            $this->assertNameFree($attributes, null);

            $bay = new Bay;
            $bay->forceFill($attributes)->save();
            $this->audit->record($bay, 'created', null, AuditTrail::snapshot($bay));

            return $bay;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveBayRequest
     */
    public function update(Bay $bay, array $attributes): Bay
    {
        return DB::transaction(function () use ($bay, $attributes): Bay {
            $locked = Bay::query()->lockForUpdate()->findOrFail($bay->id);
            $this->assertNameFree(['branch_id' => $locked->branch_id] + $attributes, $locked->id);

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertNameFree(array $attributes, ?string $except): void
    {
        if (! array_key_exists('name', $attributes)) {
            return;
        }

        $taken = Bay::query()
            ->where('branch_id', $attributes['branch_id'] ?? null)
            ->where('name', $attributes['name'])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => 'This branch already has a bay with that name.']);
        }
    }
}
