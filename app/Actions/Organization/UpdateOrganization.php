<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Audit\AuditTrail;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;

final class UpdateOrganization
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by UpdateOrganizationRequest
     */
    public function handle(Organization $organization, array $attributes): Organization
    {
        return DB::transaction(function () use ($organization, $attributes): Organization {
            $locked = Organization::query()->lockForUpdate()->findOrFail($organization->id);
            $before = AuditTrail::snapshot($locked);

            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }
}
