<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Ledger\LedgerEvent;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One balanced, numbered double-entry posting. Append-only (R7): a
 * correction is a reversing entry that names this one. Seen by staff of
 * either branch it touches (a transfer touches two).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string|null $counter_branch_id
 * @property string $number
 * @property CarbonImmutable $entry_date
 * @property string $period_id
 * @property LedgerEvent $event
 * @property string $source_type
 * @property string $source_id
 * @property string $reference
 * @property string $memo
 * @property string|null $payment_method
 * @property string|null $reversal_of_id
 * @property int $total_cents
 * @property string|null $posted_by
 * @property string $posted_by_name
 * @property CarbonImmutable $posted_at
 * @property CarbonImmutable $created_at
 * @property-read Collection<int, JournalLine> $lines
 * @property-read Period $period
 * @property-read Branch $branch
 * @property-read JournalEntry|null $reversalOf
 * @property-read JournalEntry|null $reversedBy
 */
final class JournalEntry extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['reference' => '', 'memo' => ''];

    protected function casts(): array
    {
        return [
            'event' => LedgerEvent::class,
            'entry_date' => 'immutable_date',
            'total_cents' => 'integer',
            'posted_at' => 'immutable_datetime',
        ];
    }

    /**
     * Staff of either branch the entry touches.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $branches = $context->isStaff() ? $context->allowedBranchIds : [];
        $query->where(fn (Builder $either) => $either
            ->whereIn($this->qualifyColumn('branch_id'), $branches)
            ->orWhereIn($this->qualifyColumn('counter_branch_id'), $branches));
    }

    /**
     * @return HasMany<JournalLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * @return BelongsTo<Branch, $this>
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * The entry this one undoes.
     *
     * @return BelongsTo<JournalEntry, $this>
     */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    /**
     * The entry that undid this one.
     *
     * @return HasOne<JournalEntry, $this>
     */
    public function reversedBy(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
