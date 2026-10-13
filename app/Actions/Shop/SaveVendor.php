<?php

declare(strict_types=1);

namespace App\Actions\Shop;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\ConflictException;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The provider's approved vendor list (../web store.ts addVendor /
 * updateVendor / deleteVendor). A name is unique in the organization.
 * Removing a vendor never touches past work: orders keep the vendor's name
 * as text.
 */
final class SaveVendor
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{name: string, is_active?: bool, registered_name?: string|null, tin?: string|null, address?: string|null, is_vat_registered?: bool, payment_terms_days?: int, default_atc_code?: string|null, default_withholding_rate_pct?: string|null}  $attributes
     */
    public function create(array $attributes): Vendor
    {
        return DB::transaction(function () use ($attributes): Vendor {
            $this->assertNameFree($attributes['name'], null);

            $vendor = new Vendor;
            $vendor->forceFill($attributes)->save();
            $this->audit->record($vendor, 'created', null, AuditTrail::snapshot($vendor));

            return $vendor;
        });
    }

    /**
     * @param  array{name?: string, is_active?: bool, registered_name?: string|null, tin?: string|null, address?: string|null, is_vat_registered?: bool, payment_terms_days?: int, default_atc_code?: string|null, default_withholding_rate_pct?: string|null}  $attributes
     */
    public function update(Vendor $vendor, array $attributes): Vendor
    {
        return DB::transaction(function () use ($vendor, $attributes): Vendor {
            $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            if (isset($attributes['name'])) {
                $this->assertNameFree($attributes['name'], $locked->id);
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    public function delete(Vendor $vendor): void
    {
        DB::transaction(function () use ($vendor): void {
            $locked = Vendor::query()->lockForUpdate()->findOrFail($vendor->id);
            // A vendor with bills or payments is part of the books: deactivate it instead.
            if (VendorBill::query()->where('vendor_id', $locked->id)->exists() || VendorPayment::query()->where('vendor_id', $locked->id)->exists()) {
                throw new ConflictException("{$locked->name} has bills or payments on record; deactivate the vendor instead of removing it.", ['reason' => 'vendor_in_use']);
            }
            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }

    private function assertNameFree(string $name, ?string $except): void
    {
        if (Vendor::query()->where('name', $name)->when($except !== null, fn ($query) => $query->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => 'A vendor with that name is already on the list.']);
        }
    }
}
