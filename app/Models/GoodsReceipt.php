<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\ReceiptStatus;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Goods taken in against a shop purchase order, in whole or in part. An issued
 * stock document (R7): never edited or deleted, only voided (once), which
 * reverses its moves.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $shop_purchase_order_id
 * @property string $reference
 * @property ReceiptStatus $status
 * @property CarbonImmutable $received_on
 * @property string|null $supplier_ref
 * @property string $notes
 * @property int $total_cents
 * @property string|null $received_by
 * @property string $received_by_name
 * @property CarbonImmutable|null $voided_at
 * @property string|null $voided_by_name
 * @property string|null $void_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, GoodsReceiptLine> $lines
 * @property-read ShopPurchaseOrder $order
 */
final class GoodsReceipt extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['status' => 'posted', 'notes' => '', 'total_cents' => 0];

    protected function casts(): array
    {
        return [
            'status' => ReceiptStatus::class,
            'received_on' => 'immutable_date',
            'total_cents' => 'integer',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<GoodsReceiptLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(GoodsReceiptLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<ShopPurchaseOrder, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(ShopPurchaseOrder::class, 'shop_purchase_order_id');
    }
}
