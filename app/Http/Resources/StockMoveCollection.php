<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class StockMoveCollection extends ApiResourceCollection
{
    public $collects = StockMoveResource::class;
}
