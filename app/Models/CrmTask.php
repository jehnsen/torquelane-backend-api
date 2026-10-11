<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A follow-up about a customer account or a lead: who is to do it, by when, and when to be reminded.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $customer_account_id
 * @property string|null $lead_id
 * @property string|null $vehicle_id
 * @property string $title
 * @property string|null $details
 * @property string $kind
 * @property string|null $assignee_id
 * @property CarbonImmutable|null $due_on
 * @property CarbonImmutable|null $remind_at
 * @property CarbonImmutable|null $reminded_at
 * @property string $status
 * @property CarbonImmutable|null $completed_at
 * @property string|null $completed_by_name
 * @property string|null $source_message_id
 * @property string|null $created_by
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $assignee
 * @property-read CustomerAccount|null $customerAccount
 * @property-read CrmLead|null $lead
 */
final class CrmTask extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const string OPEN = 'open';

    public const string DONE = 'done';

    public const string CANCELLED = 'cancelled';

    protected $table = 'crm_tasks';

    protected $attributes = ['kind' => 'follow_up', 'status' => 'open'];

    protected function casts(): array
    {
        return [
            'due_on' => 'immutable_date',
            'remind_at' => 'immutable_datetime',
            'reminded_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }

    /** @return BelongsTo<CrmLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }
}
