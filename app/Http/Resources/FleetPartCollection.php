<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class FleetPartCollection extends ApiResourceCollection
{
    public $collects = FleetPartResource::class;
}
