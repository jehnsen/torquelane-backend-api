<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Receivables\PaymentMethod;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prepaid package a customer bought: its price is owed as service until its credits are used.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $branch_id
 * @property string $customer_account_id
 * @property string $plan_id
 * @property string $reference
 * @property string $plan_name
 * @property int $price_cents
 * @property int $vat_cents
 * @property int $credits_total
 * @property int $credits_used
 * @property string|null $detailing_package_id
 * @property CarbonImmutable $sold_on
 * @property CarbonImmutable|null $expires_on
 * @property PaymentMethod $payment_method
 * @property string|null $payment_reference
 * @property string|null $sold_by
 * @property string $sold_by_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read CustomerAccount $customerAccount
 */
final class PrepaidPackage extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['credits_used' => 0, 'vat_cents' => 0];

    protected function casts(): array
    {
        return [
            'price_cents' => 'integer',
            'vat_cents' => 'integer',
            'credits_total' => 'integer',
            'credits_used' => 'integer',
            'sold_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'payment_method' => PaymentMethod::class,
        ];
    }

    /** What the package earns as it is used: its price less the VAT inside it. */
    public function netPriceCents(): int
    {
        return $this->price_cents - $this->vat_cents;
    }

    /** @return BelongsTo<CustomerAccount, $this> */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class, 'customer_account_id');
    }
}
