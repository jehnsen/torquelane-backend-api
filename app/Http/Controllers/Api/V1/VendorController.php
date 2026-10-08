<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Directory\DirectoryQueries;
use App\Actions\Shop\SaveVendor;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveVendorRequest;
use App\Http\Resources\VendorCollection;
use App\Http\Resources\VendorResource;
use App\Models\Vendor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The provider's approved third-party vendors, organization-wide (staff).
 */
final class VendorController
{
    /**
     * List vendors
     *
     * By name.
     */
    public function index(PaginatedRequest $request, DirectoryQueries $queries): VendorCollection
    {
        Gate::authorize('viewAny', Vendor::class);

        return new VendorCollection($queries->vendors($request->perPage()));
    }

    /**
     * Add a vendor
     *
     * `settings:manage`. Names are unique in the organization.
     */
    public function store(SaveVendorRequest $request, SaveVendor $save): JsonResponse
    {
        Gate::authorize('create', Vendor::class);
        /** @var array{name: string, is_active?: bool} $attributes */
        $attributes = $request->vendorAttributes();

        return (new VendorResource($save->create($attributes)))->response()->setStatusCode(201);
    }

    /**
     * Show a vendor
     */
    public function show(Vendor $vendor): VendorResource
    {
        Gate::authorize('view', $vendor);

        return new VendorResource($vendor);
    }

    /**
     * Update a vendor
     */
    public function update(SaveVendorRequest $request, Vendor $vendor, SaveVendor $save): VendorResource
    {
        Gate::authorize('update', $vendor);

        return new VendorResource($save->update($vendor, $request->vendorAttributes()));
    }

    /**
     * Remove a vendor
     *
     * Past work orders keep the vendor's name.
     */
    public function destroy(Vendor $vendor, SaveVendor $save): Response
    {
        Gate::authorize('delete', $vendor);
        $save->delete($vendor);

        return response()->noContent();
    }
}
