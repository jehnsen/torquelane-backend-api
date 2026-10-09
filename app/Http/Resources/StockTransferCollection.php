<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class StockTransferCollection extends ApiResourceCollection
{
    public $collects = StockTransferResource::class;
}
