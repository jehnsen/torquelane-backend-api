<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ConsentCollection extends ApiResourceCollection
{
    public $collects = ConsentResource::class;
}
