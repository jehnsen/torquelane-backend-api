<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class VehicleCollection extends ApiResourceCollection
{
    public $collects = VehicleResource::class;
}
