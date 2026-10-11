<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use DateTimeImmutable;

/** One thing that happened with a customer, as their timeline shows it. */
final readonly class TimelineEntry
{
    public function __construct(
        public DateTimeImmutable $at,
        /** work_order | invoice | payment | booking | message | note | task | consent | feedback | membership | package */
        public string $kind,
        public string $id,
        public string $title,
        public string $detail = '',
        public ?string $href = null,
        public ?string $branchId = null,
        public ?int $amountCents = null,
    ) {}
}
