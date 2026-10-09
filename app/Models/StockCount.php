<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Inventory\CountStatus;
use App\Tenancy\BelongsToOrganization;
use App\Tenancy\VisibleInStaffBranches;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A count sheet for one location. Counted quantities are entered while it is
 * open; posting it turns each variance into an adjustment move with a reason.
 * A posted or cancelled count never changes (triggers).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $location_id
 * @property string $reference
 * @property CountStatus $status
 * @property string|null $reason
 * @property string $notes
 * @property CarbonImmutable $created_on
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable|null $posted_at
 * @property string|null $posted_by_name
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancelled_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, StockCountLine> $lines
 */
final class StockCount extends Model
{
    use BelongsToOrganization;
    use HasUlids;
    use VisibleInStaffBranches;

    protected $attributes = ['status' => 'open', 'notes' => ''];

    protected function casts(): array
    {
        return [
            'status' => CountStatus::class,
            'created_on' => 'immutable_date',
            'posted_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return HasMany<StockCountLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(StockCountLine::class)->orderBy('created_at')->orderBy('id');
    }
}
