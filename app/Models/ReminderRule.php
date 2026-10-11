<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\Reminders\ReminderKind;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Whether one kind of service reminder is sent, with which template, and how far ahead.
 *
 * @property string $id
 * @property string $organization_id
 * @property ReminderKind $kind
 * @property bool $enabled
 * @property string $template_id
 * @property int|null $lead_days
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read MessageTemplate $template
 */
final class ReminderRule extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['enabled' => true];

    protected function casts(): array
    {
        return [
            'kind' => ReminderKind::class,
            'enabled' => 'boolean',
            'lead_days' => 'integer',
        ];
    }

    /** @return BelongsTo<MessageTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(MessageTemplate::class, 'template_id');
    }
}
