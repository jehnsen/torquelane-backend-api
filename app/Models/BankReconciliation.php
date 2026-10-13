<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One bank (or wallet, or card acquirer) statement agreed with the books:
 * the cash-side account, the statement's closing date and balance, and the
 * journal lines the statement shows (`lines`). Open while it is being worked;
 * completed only when the statement balance equals what has cleared, and
 * final after that (trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $account_id
 * @property CarbonImmutable $statement_date
 * @property int $statement_balance_cents
 * @property string $status
 * @property int|null $cleared_balance_cents
 * @property int|null $book_balance_cents
 * @property string $notes
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable|null $completed_at
 * @property string|null $completed_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Account $account
 * @property-read Collection<int, BankReconciliationLine> $lines
 */
final class BankReconciliation extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const string OPEN = 'open';

    public const string COMPLETED = 'completed';

    protected $attributes = ['status' => self::OPEN, 'notes' => ''];

    protected function casts(): array
    {
        return [
            'statement_date' => 'immutable_date',
            'statement_balance_cents' => 'integer',
            'cleared_balance_cents' => 'integer',
            'book_balance_cents' => 'integer',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return HasMany<BankReconciliationLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(BankReconciliationLine::class);
    }
}
