<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A request for a 0–10 score after a job was paid for, and the customer's answer.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string|null $invoice_id
 * @property string|null $work_order_id
 * @property string|null $detailing_booking_id
 * @property string $token_hash
 * @property string|null $message_id
 * @property CarbonImmutable $requested_at
 * @property CarbonImmutable|null $responded_at
 * @property int|null $score
 * @property string|null $comment
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerAccount $customerAccount
 */
final class FeedbackRequest extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'requested_at' => 'immutable_datetime',
            'responded_at' => 'immutable_datetime',
            'score' => 'integer',
        ];
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
