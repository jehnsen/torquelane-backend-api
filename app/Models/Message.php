<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the message log: what was (or was not) sent to whom, why, and what became of it. A message that may not go is logged as `skipped` with its reason, never silently dropped.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string $customer_account_id
 * @property string|null $contact_id
 * @property MessageChannel $channel
 * @property MessagePurpose $purpose
 * @property string|null $template_id
 * @property string|null $to_address
 * @property string|null $to_name
 * @property string|null $subject
 * @property string $body
 * @property string $status
 * @property string|null $skip_reason
 * @property string $source_type
 * @property string|null $source_id
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $dedupe_key
 * @property CarbonImmutable|null $dedupe_on
 * @property string|null $token_hash
 * @property CarbonImmutable|null $send_after
 * @property CarbonImmutable|null $sent_at
 * @property string|null $failed_reason
 * @property CarbonImmutable|null $responded_at
 * @property string $created_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerAccount $customerAccount
 */
final class Message extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const string QUEUED = 'queued';

    public const string SENT = 'sent';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    protected $attributes = ['status' => 'queued', 'created_by_name' => 'System'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'dedupe_on' => 'immutable_date',
            'send_after' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
