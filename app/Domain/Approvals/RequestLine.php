<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use DateTimeImmutable;

/** A work-order line as the approvals queue reads it (cost: the stored price). */
final readonly class RequestLine
{
    public function __construct(
        public string $id,
        public int $costCents,
        public LineApprovalStatus $status,
        public ?DateTimeImmutable $approvedAt,
    ) {}
}
