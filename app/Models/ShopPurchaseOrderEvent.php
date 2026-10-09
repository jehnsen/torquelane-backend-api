<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\StoredOrderStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only history of a shop purchase order's decisions.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $shop_purchase_order_id
 * @property StoredOrderStatus $status
 * @property CarbonImmutable $at
 * @property string|null $actor_id
 * @property string $actor_name
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
final class ShopPurchaseOrderEvent extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => StoredOrderStatus::class,
            'at' => 'immutable_datetime',
        ];
    }
}
