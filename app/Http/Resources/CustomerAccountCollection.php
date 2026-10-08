<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class CustomerAccountCollection extends ApiResourceCollection
{
    public $collects = CustomerAccountResource::class;
}
