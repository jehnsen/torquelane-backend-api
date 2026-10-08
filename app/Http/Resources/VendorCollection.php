<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class VendorCollection extends ApiResourceCollection
{
    public $collects = VendorResource::class;
}
