<?php

declare(strict_types=1);

namespace App\Domain\Ledger\Postings;

use App\Domain\Ledger\LedgerEvent;

/** What an event tells the ledger: enough to build its entry and nothing about storage. */
interface PostingFacts
{
    public function event(): LedgerEvent;
}
