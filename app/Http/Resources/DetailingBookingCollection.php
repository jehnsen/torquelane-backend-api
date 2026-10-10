<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class DetailingBookingCollection extends ApiResourceCollection
{
    public $collects = DetailingBookingResource::class;
}
