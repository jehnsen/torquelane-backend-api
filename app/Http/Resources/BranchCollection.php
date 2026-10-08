<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class BranchCollection extends ApiResourceCollection
{
    public $collects = BranchResource::class;
}
