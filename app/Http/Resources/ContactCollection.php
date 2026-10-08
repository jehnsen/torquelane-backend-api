<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ContactCollection extends ApiResourceCollection
{
    public $collects = ContactResource::class;
}
