<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A before or after photo of an inspection. The file is a `Document` on the
 * private disk, filed under the booking's account when it was uploaded.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $inspection_id
 * @property string $customer_account_id
 * @property string $document_id
 * @property string $phase
 * @property string|null $caption
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Document $document
 */
final class DetailingInspectionPhoto extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const array PHASES = ['before', 'after'];

    protected $attributes = ['position' => 0];

    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
