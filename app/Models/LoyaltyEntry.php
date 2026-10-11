<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the append-only points ledger. A balance is the sum of an account's rows.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property int $points
 * @property string $reason
 * @property string|null $payment_id
 * @property string|null $note
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 */
final class LoyaltyEntry extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['created_by_name' => 'System'];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
        ];
    }
}
