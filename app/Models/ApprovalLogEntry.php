<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Approvals\ApprovalAction;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Append-only approval log (triggers refuse UPDATE and DELETE).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $work_order_id
 * @property string|null $line_id
 * @property ApprovalAction $action
 * @property string|null $actor_id
 * @property string $actor_name
 * @property CarbonImmutable $at
 * @property string|null $note
 * @property int $amount_at_time_cents
 * @property CarbonImmutable $created_at
 */
final class ApprovalLogEntry extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $table = 'approval_log';

    protected function casts(): array
    {
        return [
            'action' => ApprovalAction::class,
            'at' => 'immutable_datetime',
            'amount_at_time_cents' => 'integer',
        ];
    }
}
