<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class StockCountCollection extends ApiResourceCollection
{
    public $collects = StockCountResource::class;
}
