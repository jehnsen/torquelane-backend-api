<?php

declare(strict_types=1);

namespace App\Domain\Shop;

final readonly class AccountRollup
{
    public function __construct(
        public AccountRef $account,
        public int $vehicleCount,
        public int $openWorkOrders,
        public float|int|null $avgApprovalHours,
        public int $spendThisPeriodCents,
        public int $outstandingCents,
    ) {}
}
