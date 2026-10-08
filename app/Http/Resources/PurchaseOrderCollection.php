<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class PurchaseOrderCollection extends ApiResourceCollection
{
    public $collects = PurchaseOrderResource::class;
}
