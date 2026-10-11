<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An organization's CRM settings: quiet hours, cooldowns, limits, and whether feedback and loyalty points are on.
 *
 * @property string $id
 * @property string $organization_id
 * @property int $quiet_start_minutes
 * @property int $quiet_end_minutes
 * @property int $reminder_cooldown_days
 * @property int $lapsed_after_days
 * @property int $campaign_hourly_limit
 * @property bool $feedback_enabled
 * @property bool $loyalty_enabled
 * @property int $loyalty_cents_per_point
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CrmSetting extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $table = 'crm_settings';

    protected $attributes = ['quiet_start_minutes' => 1260, 'quiet_end_minutes' => 480, 'reminder_cooldown_days' => 14, 'lapsed_after_days' => 180, 'campaign_hourly_limit' => 200, 'feedback_enabled' => true, 'loyalty_enabled' => false, 'loyalty_cents_per_point' => 10000];

    protected function casts(): array
    {
        return [
            'quiet_start_minutes' => 'integer',
            'quiet_end_minutes' => 'integer',
            'reminder_cooldown_days' => 'integer',
            'lapsed_after_days' => 'integer',
            'campaign_hourly_limit' => 'integer',
            'feedback_enabled' => 'boolean',
            'loyalty_enabled' => 'boolean',
            'loyalty_cents_per_point' => 'integer',
        ];
    }
}
