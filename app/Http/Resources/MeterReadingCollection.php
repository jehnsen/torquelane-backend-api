<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class MeterReadingCollection extends ApiResourceCollection
{
    public $collects = MeterReadingResource::class;
}
