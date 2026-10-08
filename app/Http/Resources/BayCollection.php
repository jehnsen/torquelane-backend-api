<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class BayCollection extends ApiResourceCollection
{
    public $collects = BayResource::class;
}
