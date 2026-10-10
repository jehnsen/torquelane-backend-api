<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\PeriodStatus;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An accounting month. Created on the first posting into it; open until
 * closed (once the month is over and the close checklist passes); a closed
 * period takes no new entry and is never edited again.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $period_key
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property PeriodStatus $status
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closed_by
 * @property string|null $closed_by_name
 * @property array<string, mixed>|null $close_checklist
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Period extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'status' => PeriodStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'closed_at' => 'immutable_datetime',
            'close_checklist' => 'array',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === PeriodStatus::Open;
    }
}
