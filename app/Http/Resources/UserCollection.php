<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class UserCollection extends ApiResourceCollection
{
    public $collects = UserResource::class;
}
