<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only (the table's triggers refuse UPDATE and DELETE). Written only by
 * App\Actions\Audit\AuditTrail.
 *
 * @property string $id
 * @property CarbonImmutable $occurred_at
 * @property string|null $request_id
 * @property string|null $actor_id
 * @property string|null $actor_role
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string|null $customer_account_id
 * @property string $entity_type
 * @property string $entity_id
 * @property string $action
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
final class AuditLog extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'occurred_at' => 'immutable_datetime',
            'before' => 'array',
            'after' => 'array',
        ];
    }
}
