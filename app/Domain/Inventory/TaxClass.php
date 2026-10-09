<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

/** How an item is taxed (carried for invoicing; Phase 3 billing applies one VAT rate to the order). */
enum TaxClass: string
{
    case Vatable = 'vatable';
    case VatExempt = 'vat_exempt';
    case ZeroRated = 'zero_rated';
}
