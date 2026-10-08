<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use DateTimeImmutable;

/**
 * One row of the append-only consent ledger, as the domain sees it.
 */
final readonly class ConsentDecision
{
    public function __construct(
        /** ULID: lexically ordered by creation, the tie-breaker for equal capture times. */
        public string $id,
        public ConsentPurpose $purpose,
        public bool $granted,
        public DateTimeImmutable $capturedAt,
        /** Null for an account-level decision; a contact's own otherwise. */
        public ?string $contactId = null,
    ) {}
}
