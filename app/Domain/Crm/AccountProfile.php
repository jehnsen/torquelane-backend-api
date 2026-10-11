<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/** What a segment reads about one customer account. */
final readonly class AccountProfile
{
    /**
     * @param  list<string>  $tags
     * @param  list<string>  $makes  lower-cased
     * @param  list<string>  $sizeClasses
     * @param  list<string>  $branchIds  where the customer has been served
     */
    public function __construct(
        public string $accountId,
        public string $accountType,
        public array $tags,
        /** Everything paid, in centavos. */
        public int $spendCents,
        /** `Y-m-d` of the last finished job or booking; null when never served. */
        public ?string $lastVisitOn,
        public array $makes,
        public array $sizeClasses,
        public ?string $tierId,
        public array $branchIds,
    ) {}
}
