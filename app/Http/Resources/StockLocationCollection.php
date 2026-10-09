<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class StockLocationCollection extends ApiResourceCollection
{
    public $collects = StockLocationResource::class;
}
