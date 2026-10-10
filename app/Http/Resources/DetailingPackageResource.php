<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Detailing\ServiceDuration;
use App\Domain\Detailing\SizeClass;
use App\Models\DetailingPackage;
use App\Models\DetailingPackageMaterial;
use App\Models\DetailingPackagePrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A detailing package: one row whatever the size, a price per size class it
 * is offered in, and (for staff) its bill of materials. `duration` is how the
 * API reads the typed duration, so no screen parses it.
 *
 * @property DetailingPackage $resource
 */
final class DetailingPackageResource extends JsonResource
{
    public function __construct(DetailingPackage $resource, private readonly bool $withMaterials = true)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $package = $this->resource;
        $package->loadMissing(['prices', 'category']);
        $order = array_flip(SizeClass::values());

        return [
            'id' => $package->id,
            'branch_id' => $package->branch_id,
            'category' => $package->category === null ? null : ['id' => $package->category->id, 'name' => $package->category->name, 'slug' => $package->category->slug, 'color' => $package->category->color],
            'name' => $package->name,
            'description' => $package->description,
            'duration_text' => $package->duration_text,
            'duration' => self::duration($package->duration_text),
            'inclusions' => $package->inclusions,
            'is_popular' => $package->is_popular,
            'min_detailers' => $package->min_detailers,
            'recommended_detailers' => $package->recommended_detailers,
            'applies_to' => $package->applies_to,
            'is_active' => $package->is_active,
            'position' => $package->position,
            'prices' => array_values($package->prices
                ->sortBy(fn (DetailingPackagePrice $price): int => $order[$price->size_class->value])
                ->map(fn (DetailingPackagePrice $price): array => [
                    'size_class' => $price->size_class->value,
                    'size_label' => $price->size_class->label(),
                    'price_cents' => $price->price_cents,
                    'duration_text' => $price->duration_text,
                    'duration' => self::duration($price->duration_text ?? $package->duration_text),
                ])->all()),
            'materials' => $this->withMaterials ? array_values($package->loadMissing('materials.item')->materials->map(fn (DetailingPackageMaterial $line): array => [
                'id' => $line->id,
                'item_id' => $line->item_id,
                'item_name' => $line->item->name,
                'sku' => $line->item->sku,
                'uom' => $line->item->uom,
                'quantity' => (string) $line->quantity,
                'scales_with_size' => $line->scales_with_size,
            ])->all()) : null,
        ];
    }

    /**
     * @return array{slot_budget: int, minutes: int, days: int, interpreted: string}
     */
    public static function duration(string $text): array
    {
        $slots = ServiceDuration::slotBudget($text);

        return [
            'slot_budget' => $slots,
            'minutes' => $slots * 30,
            'days' => ServiceDuration::days($text),
            'interpreted' => ServiceDuration::describe($text),
        ];
    }
}
