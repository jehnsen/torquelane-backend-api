<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

use DomainException;

/** A requested allocation that does not fit; `index` is the row it is about (null: the whole request). */
final class AllocationRefused extends DomainException
{
    public function __construct(string $message, public readonly ?int $index = null)
    {
        parent::__construct($message);
    }
}
