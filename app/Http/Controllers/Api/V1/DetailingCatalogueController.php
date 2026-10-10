<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Detailing\DetailingBranches;
use App\Actions\Detailing\DetailingQueries;
use App\Actions\Detailing\ManageCatalogue;
use App\Http\Requests\Detailing\BranchRequest;
use App\Http\Requests\Detailing\ReorderRequest;
use App\Http\Requests\Detailing\SaveAddonRequest;
use App\Http\Requests\Detailing\SaveCategoryRequest;
use App\Http\Requests\Detailing\SavePackageRequest;
use App\Http\Resources\DetailingAddonResource;
use App\Http\Resources\DetailingCategoryResource;
use App\Http\Resources\DetailingPackageResource;
use App\Models\DetailingAddon;
use App\Models\DetailingCategory;
use App\Models\DetailingPackage;
use App\Models\Technician;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A detailing branch's menu (staff): categories, packages priced per size
 * class with their bill of materials, and add-ons priced per size class.
 * Every staff role reads it; `detailing:manage` changes it.
 */
final class DetailingCatalogueController
{
    public function __construct(
        private readonly DetailingBranches $branches,
        private readonly ManageCatalogue $catalogue,
    ) {}

    /**
     * The menu
     *
     * Categories, packages (with prices and materials) and add-ons of the
     * branch, in their set order, with the branch's detailers. Inactive ones
     * only with `include_inactive=1`.
     */
    public function index(BranchRequest $request, DetailingQueries $queries): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewIn', [DetailingPackage::class, $branch->id]);
        $menu = $queries->catalogue($branch, $request->includeInactive());

        return new JsonResponse(['data' => [
            'branch_id' => $branch->id,
            'categories' => DetailingCategoryResource::collection($menu['categories'])->resolve($request),
            'packages' => array_values($menu['packages']->map(fn (DetailingPackage $package): array => (new DetailingPackageResource($package))->resolve($request))->all()),
            'addons' => DetailingAddonResource::collection($menu['addons'])->resolve($request),
            'detailers' => array_map(fn (Technician $technician): array => ['id' => $technician->id, 'name' => $technician->name], $queries->detailers($branch)),
        ]]);
    }

    /**
     * Read a duration
     *
     * How the API reads a typed duration (`text`): slots, minutes, days and
     * the "Interpreted as …" line, so a typo is caught before it is saved.
     */
    public function duration(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', DetailingPackage::class);
        $text = $request->query('text');

        return new JsonResponse(['data' => ['text' => is_string($text) ? $text : ''] + DetailingPackageResource::duration(is_string($text) ? mb_substr($text, 0, 64) : '')]);
    }

    /**
     * Add a package
     */
    public function storePackage(SavePackageRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingPackage::class, $branch->id]);

        return (new DetailingPackageResource($this->catalogue->savePackage($branch, null, $request->package())))->response()->setStatusCode(201);
    }

    /**
     * Edit a package
     *
     * The whole package: its prices are replaced by the list sent, and its
     * materials too when `materials` is sent.
     */
    public function updatePackage(SavePackageRequest $request, DetailingPackage $detailingPackage): DetailingPackageResource
    {
        Gate::authorize('manageIn', [DetailingPackage::class, $detailingPackage->branch_id]);

        return new DetailingPackageResource($this->catalogue->savePackage($this->branches->resolve($detailingPackage->branch_id), $detailingPackage, $request->package()));
    }

    /**
     * Delete a package
     *
     * A package that bookings name is 409: switch it off instead.
     */
    public function destroyPackage(DetailingPackage $detailingPackage): Response
    {
        Gate::authorize('manageIn', [DetailingPackage::class, $detailingPackage->branch_id]);
        $this->catalogue->deletePackage($detailingPackage);

        return response()->noContent();
    }

    /**
     * Add a category
     */
    public function storeCategory(SaveCategoryRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingPackage::class, $branch->id]);

        return (new DetailingCategoryResource($this->catalogue->saveCategory($branch, null, $request->category())))->response()->setStatusCode(201);
    }

    /**
     * Edit a category
     */
    public function updateCategory(SaveCategoryRequest $request, DetailingCategory $detailingCategory): DetailingCategoryResource
    {
        Gate::authorize('manageIn', [DetailingPackage::class, $detailingCategory->branch_id]);

        return new DetailingCategoryResource($this->catalogue->saveCategory($this->branches->resolve($detailingCategory->branch_id), $detailingCategory, $request->category()));
    }

    /**
     * Delete a category
     *
     * One that still has packages is 409.
     */
    public function destroyCategory(DetailingCategory $detailingCategory): Response
    {
        Gate::authorize('manageIn', [DetailingPackage::class, $detailingCategory->branch_id]);
        $this->catalogue->deleteCategory($detailingCategory);

        return response()->noContent();
    }

    /**
     * Add an add-on
     */
    public function storeAddon(SaveAddonRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingPackage::class, $branch->id]);

        return (new DetailingAddonResource($this->catalogue->saveAddon($branch, null, $request->addon())))->response()->setStatusCode(201);
    }

    /**
     * Edit an add-on
     */
    public function updateAddon(SaveAddonRequest $request, DetailingAddon $detailingAddon): DetailingAddonResource
    {
        Gate::authorize('manageIn', [DetailingPackage::class, $detailingAddon->branch_id]);

        return new DetailingAddonResource($this->catalogue->saveAddon($this->branches->resolve($detailingAddon->branch_id), $detailingAddon, $request->addon()));
    }

    /**
     * Delete an add-on
     *
     * One that bookings carry is 409: switch it off instead.
     */
    public function destroyAddon(DetailingAddon $detailingAddon): Response
    {
        Gate::authorize('manageIn', [DetailingPackage::class, $detailingAddon->branch_id]);
        $this->catalogue->deleteAddon($detailingAddon);

        return response()->noContent();
    }

    /**
     * Reorder the menu
     *
     * `kind` is `packages`, `categories` or `addons`; `ids` in the order wanted.
     */
    public function reorder(ReorderRequest $request): Response
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('manageIn', [DetailingPackage::class, $branch->id]);
        $this->catalogue->reorder($branch, match ($request->kind()) {
            'categories' => DetailingCategory::class,
            'addons' => DetailingAddon::class,
            default => DetailingPackage::class,
        }, $request->ids());

        return response()->noContent();
    }
}
