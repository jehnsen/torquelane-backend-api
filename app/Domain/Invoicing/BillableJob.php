<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

/** A closed work order as the invoice reads it: its approved lines and its flat misc fee. */
final readonly class BillableJob
{
    /**
     * @param  list<BillableJobLine>  $lines  approved lines only, in order
     */
    public function __construct(
        public string $id,
        public string $reference,
        public string $title,
        public array $lines,
        public int $miscFeeCents,
    ) {}
}
