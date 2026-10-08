<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ServiceTaskCollection extends ApiResourceCollection
{
    public $collects = ServiceTaskResource::class;
}
