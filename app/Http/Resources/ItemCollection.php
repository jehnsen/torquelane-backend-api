<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ItemCollection extends ApiResourceCollection
{
    public $collects = ItemResource::class;
}
