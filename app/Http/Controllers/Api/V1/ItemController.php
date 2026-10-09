<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Inventory\InventoryQueries;
use App\Actions\Inventory\SaveItem;
use App\Http\Requests\ListItemsRequest;
use App\Http\Requests\SaveItemBranchSettingsRequest;
use App\Http\Requests\SaveItemRequest;
use App\Http\Resources\ItemCollection;
use App\Http\Resources\ItemResource;
use App\Models\Branch;
use App\Models\Item;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The shop's own inventory items (parts, consumables, retail goods,
 * ingredients, fees). Staff only; organization-wide master data, with each
 * branch's settings and stock alongside.
 */
final class ItemController
{
    /**
     * List items
     *
     * `inventory:view`. By SKU; active items unless `include_inactive`. Each
     * item carries, for every branch the caller may see, its reorder point,
     * bin, the price that applies there and what is on hand.
     */
    public function index(ListItemsRequest $request, InventoryQueries $queries): ItemCollection
    {
        Gate::authorize('viewAny', Item::class);

        return new ItemCollection($queries->items($request->filters(), $request->perPage()));
    }

    /**
     * Add an item
     *
     * `inventory:manage`. The SKU (and barcode) are unique in the organization.
     * A service fee is never stocked.
     */
    public function store(SaveItemRequest $request, SaveItem $save, InventoryQueries $queries): JsonResponse
    {
        Gate::authorize('create', Item::class);

        return (new ItemResource($queries->freshItem($save->create($request->itemAttributes()))))->response()->setStatusCode(201);
    }

    /**
     * Show an item
     */
    public function show(Item $item, InventoryQueries $queries): ItemResource
    {
        Gate::authorize('view', $item);

        return new ItemResource($queries->freshItem($item));
    }

    /**
     * Update an item
     *
     * `inventory:manage`. Once stock has moved in an item its stock unit and
     * whether it is stocked are fixed. Items are never deleted: set
     * `is_active` to false.
     */
    public function update(SaveItemRequest $request, Item $item, SaveItem $save, InventoryQueries $queries): ItemResource
    {
        Gate::authorize('update', $item);

        return new ItemResource($queries->freshItem($save->update($item, $request->itemAttributes())));
    }

    /**
     * Set an item's settings for a branch
     *
     * `inventory:manage` in that branch. Reorder point and quantity (stock
     * units), the bin it is kept in, and a price that overrides the item's
     * for this branch. Send `null` to clear one.
     */
    public function branchSettings(SaveItemBranchSettingsRequest $request, Item $item, Branch $branch, SaveItem $save, InventoryQueries $queries): ItemResource
    {
        Gate::authorize('setBranchSettings', [$item, $branch->id]);
        $save->setBranchSettings($item, $branch->id, $request->settings());

        return new ItemResource($queries->freshItem($item));
    }
}
