<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\LeadStage;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A B2B lead on the pipeline. Winning it converts it into a company account (`customer_account_id`).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string $company_name
 * @property string|null $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property LeadStage $stage
 * @property int $value_cents
 * @property string|null $owner_id
 * @property string|null $next_step
 * @property CarbonImmutable|null $next_step_on
 * @property string|null $source
 * @property bool $is_dealer
 * @property string|null $notes
 * @property string|null $lost_reason
 * @property string|null $customer_account_id
 * @property CarbonImmutable $stage_changed_at
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $owner
 */
final class CrmLead extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $table = 'crm_leads';

    protected $attributes = ['stage' => 'new', 'value_cents' => 0, 'is_dealer' => false];

    protected function casts(): array
    {
        return [
            'stage' => LeadStage::class,
            'value_cents' => 'integer',
            'next_step_on' => 'immutable_date',
            'is_dealer' => 'boolean',
            'stage_changed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
