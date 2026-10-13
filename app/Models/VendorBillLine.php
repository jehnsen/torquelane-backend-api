<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Inventory\TaxClass;
use App\Domain\Payables\BillLineKind;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a vendor bill. Append-only. `line_total_cents` is as the vendor
 * billed it; `net_cents` is its share of the bill net of VAT (what it
 * debits). A line billing goods received into stock keeps what the receipt
 * credited GR/IR with (`received_value_cents`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $vendor_bill_id
 * @property int $position
 * @property BillLineKind $kind
 * @property string $description
 * @property string|null $goods_receipt_line_id
 * @property string|null $account_id
 * @property BigDecimal $quantity
 * @property int $unit_cost_cents
 * @property TaxClass $tax_class
 * @property int $line_total_cents
 * @property int $net_cents
 * @property int|null $received_value_cents
 * @property CarbonImmutable $created_at
 * @property-read Account|null $account
 * @property-read GoodsReceiptLine|null $goodsReceiptLine
 */
final class VendorBillLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => BillLineKind::class,
            'quantity' => DecimalCast::class,
            'unit_cost_cents' => 'integer',
            'tax_class' => TaxClass::class,
            'line_total_cents' => 'integer',
            'net_cents' => 'integer',
            'received_value_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<GoodsReceiptLine, $this>
     */
    public function goodsReceiptLine(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiptLine::class);
    }
}
