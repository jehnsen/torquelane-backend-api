<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Detailing\SizeClass;
use App\Models\DetailingAddon;
use App\Models\DetailingAddonPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property DetailingAddon $resource
 */
final class DetailingAddonResource extends JsonResource
{
    public function __construct(DetailingAddon $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $addon = $this->resource;
        $addon->loadMissing('prices');
        $order = array_flip(SizeClass::values());

        return [
            'id' => $addon->id,
            'branch_id' => $addon->branch_id,
            'name' => $addon->name,
            'is_active' => $addon->is_active,
            'position' => $addon->position,
            'prices' => array_values($addon->prices
                ->sortBy(fn (DetailingAddonPrice $price): int => $order[$price->size_class->value])
                ->map(fn (DetailingAddonPrice $price): array => [
                    'size_class' => $price->size_class->value,
                    'size_label' => $price->size_class->label(),
                    'price_cents' => $price->price_cents,
                ])->all()),
        ];
    }
}
