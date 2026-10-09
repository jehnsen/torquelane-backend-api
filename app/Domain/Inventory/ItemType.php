<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** What an item is. A service fee is never stocked. */
enum ItemType: string
{
    case Part = 'part';
    case Consumable = 'consumable';
    case Retail = 'retail';
    case Ingredient = 'ingredient';
    case ServiceFee = 'service_fee';
}
