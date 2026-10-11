<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

/** A compliance document with an expiry date. */
final readonly class ExpiringDocument
{
    public function __construct(
        public string $accountId,
        public string $documentId,
        public string $name,
        public string $kindLabel,
        public ?string $plateNumber,
        public string $expiresOn,
    ) {}
}
