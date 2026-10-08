<?php

declare(strict_types=1);

namespace App\Domain\CheckIn;

/** What the counter form shows about the vehicle's owner. */
final readonly class CheckInCustomer
{
    public function __construct(
        public string $name,
        public string $contactName,
        public string $contactEmail,
    ) {}
}
