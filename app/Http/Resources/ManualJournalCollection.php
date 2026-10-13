<?php

declare(strict_types=1);

namespace App\Http\Resources;

final class ManualJournalCollection extends ApiResourceCollection
{
    public $collects = ManualJournalResource::class;
}
