<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class JournalEntryCollection extends ApiResourceCollection
{
    public $collects = JournalEntryResource::class;
}
