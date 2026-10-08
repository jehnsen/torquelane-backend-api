<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class TechnicianCollection extends ApiResourceCollection
{
    public $collects = TechnicianResource::class;
}
