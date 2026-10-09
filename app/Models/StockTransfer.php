<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One document that takes goods out of one location and into another: both
 * moves, one transaction. Append-only (R7); undone by a reversing transfer
 * that names it. Seen by staff of either branch.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $reference
 * @property string $from_branch_id
 * @property string $from_location_id
 * @property string $to_branch_id
 * @property string $to_location_id
 * @property string|null $reverses_transfer_id
 * @property string $notes
 * @property CarbonImmutable $transferred_on
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, StockTransferLine> $lines
 * @property-read StockTransfer|null $reversal
 */
final class StockTransfer extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['notes' => ''];

    protected function casts(): array
    {
        return ['transferred_on' => 'immutable_date'];
    }

    /**
     * Staff of either branch the goods moved between.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $branches = $context->isStaff() ? $context->allowedBranchIds : [];
        $query->where(fn (Builder $either) => $either
            ->whereIn($this->qualifyColumn('from_branch_id'), $branches)
            ->orWhereIn($this->qualifyColumn('to_branch_id'), $branches));
    }

    /**
     * The transfer that undid this one, if any.
     *
     * @return HasOne<StockTransfer, $this>
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transfer_id');
    }

    /**
     * @return HasMany<StockTransferLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockTransferLine::class)->orderBy('position')->orderBy('id');
    }
}
