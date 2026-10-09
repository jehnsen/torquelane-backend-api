<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class GoodsReceiptCollection extends ApiResourceCollection
{
    public $collects = GoodsReceiptResource::class;
}
