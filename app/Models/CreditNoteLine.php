<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Inventory\TaxClass;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of a credit note. Append-only. It credits an invoice line (some
 * or all of it), or is an allowance on the invoice as a whole.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $credit_note_id
 * @property int $position
 * @property string|null $invoice_line_id
 * @property string $description
 * @property BigDecimal $quantity
 * @property int $unit_price_cents
 * @property TaxClass $tax_class
 * @property int $line_total_cents
 * @property CarbonImmutable $created_at
 */
final class CreditNoteLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'quantity' => DecimalCast::class,
            'unit_price_cents' => 'integer',
            'tax_class' => TaxClass::class,
            'line_total_cents' => 'integer',
        ];
    }
}
