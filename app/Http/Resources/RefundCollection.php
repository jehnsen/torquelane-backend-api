<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class RefundCollection extends ApiResourceCollection
{
    public $collects = RefundResource::class;
}
