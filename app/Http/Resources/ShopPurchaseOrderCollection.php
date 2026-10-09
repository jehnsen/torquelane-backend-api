<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ShopPurchaseOrderCollection extends ApiResourceCollection
{
    public $collects = ShopPurchaseOrderResource::class;
}
