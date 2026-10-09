<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Inventory\StockLedger;
use App\Models\Item;
use Brick\Math\BigDecimal;

/**
 * Shapes the inventory resources share. Money is integer centavos, a
 * quantity a decimal string with three places ("10.000").
 */
final class InventoryJson
{
    /**
     * An item as another record refers to it.
     *
     * @return array<string, mixed>
     */
    public static function item(Item $item): array
    {
        return [
            'id' => $item->id,
            'sku' => $item->sku,
            'name' => $item->name,
            'uom' => $item->uom,
            'item_type' => $item->item_type->value,
        ];
    }

    /** One balance's value: on hand × average, rounded half-up once. */
    public static function value(BigDecimal $onHand, int $avgCostCents): int
    {
        return StockLedger::roundCents($onHand->multipliedBy($avgCostCents));
    }

    public static function quantity(?BigDecimal $quantity): ?string
    {
        return $quantity === null ? null : (string) $quantity->toScale(3);
    }
}
