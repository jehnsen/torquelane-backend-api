<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One side of one account in one branch. Append-only. Reports read these
 * within the caller's branches; a customer's receivable carries its
 * `customer_account_id`, and a stock line the move it posts.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $journal_entry_id
 * @property int $position
 * @property string $account_id
 * @property string $branch_id
 * @property int $debit_cents
 * @property int $credit_cents
 * @property string|null $customer_account_id
 * @property string|null $stock_move_id
 * @property string $memo
 * @property CarbonImmutable $created_at
 * @property-read Account $account
 * @property-read JournalEntry $entry
 */
final class JournalLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['debit_cents' => 0, 'credit_cents' => 0, 'memo' => ''];

    protected function casts(): array
    {
        return ['debit_cents' => 'integer', 'credit_cents' => 'integer', 'position' => 'integer'];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<JournalEntry, $this>
     */
    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
