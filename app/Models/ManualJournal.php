<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The document behind a typed-in journal entry (a journal voucher): who
 * wrote it, when it is dated, and why. Its lines are the journal entry's own.
 * Posted once; reversed at most once; never edited or deleted (trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property CarbonImmutable $entry_date
 * @property string $reference
 * @property string $memo
 * @property string $status
 * @property int $total_cents
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable|null $reversed_at
 * @property string|null $reversed_by_name
 * @property string|null $reversal_reason
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ManualJournal extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const string POSTED = 'posted';

    public const string REVERSED = 'reversed';

    protected $attributes = ['status' => self::POSTED, 'reference' => ''];

    protected function casts(): array
    {
        return [
            'entry_date' => 'immutable_date',
            'total_cents' => 'integer',
            'reversed_at' => 'immutable_datetime',
        ];
    }
}
