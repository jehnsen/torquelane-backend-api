<?php

declare(strict_types=1);

namespace App\Domain\Payables;

/** What a vendor bill line is for: goods already received against a purchase order, or a straight expense. */
enum BillLineKind: string
{
    case Goods = 'goods';
    case Expense = 'expense';
}
