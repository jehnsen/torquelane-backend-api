<?php

declare(strict_types=1);

namespace App\Domain\Documents;

/** A document as compliance and alert derivation need it. */
final readonly class DocumentFacts
{
    public function __construct(
        public string $id,
        public string $name,
        public DocumentKind $kind,
        public ?string $vehicleId,
        /** Y-m-d */
        public ?string $expiresOn,
    ) {}
}
