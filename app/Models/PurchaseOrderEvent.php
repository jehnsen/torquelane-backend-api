<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\PurchaseOrders\PurchaseOrderStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only status history of a purchase order (triggers refuse UPDATE and
 * DELETE).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $purchase_order_id
 * @property PurchaseOrderStatus $status
 * @property CarbonImmutable $at
 * @property string|null $actor_id
 * @property string $actor_name
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
final class PurchaseOrderEvent extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'status' => PurchaseOrderStatus::class,
            'at' => 'immutable_datetime',
        ];
    }
}
