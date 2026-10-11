<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A marketing message to a segment: a template, a time, and a throttle. Its recipients are rows of the message log.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $segment_id
 * @property string $template_id
 * @property string $status
 * @property CarbonImmutable|null $scheduled_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property int|null $hourly_limit
 * @property list<string>|null $audience
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CrmSegment $segment
 * @property-read MessageTemplate $template
 */
final class CrmCampaign extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $table = 'crm_campaigns';

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'immutable_datetime',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'hourly_limit' => 'integer',
            'audience' => 'array',
        ];
    }

    /** @return BelongsTo<CrmSegment, $this> */
    public function segment(): BelongsTo
    {
        return $this->belongsTo(CrmSegment::class, 'segment_id');
    }

    /** @return BelongsTo<MessageTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }
}
