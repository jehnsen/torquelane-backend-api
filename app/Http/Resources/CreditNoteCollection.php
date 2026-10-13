<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class CreditNoteCollection extends ApiResourceCollection
{
    public $collects = CreditNoteResource::class;
}
