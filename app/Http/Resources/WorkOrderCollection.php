<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class WorkOrderCollection extends ApiResourceCollection
{
    public $collects = WorkOrderResource::class;
}
