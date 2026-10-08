<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class VehicleOwnershipCollection extends ApiResourceCollection
{
    public $collects = VehicleOwnershipResource::class;
}
