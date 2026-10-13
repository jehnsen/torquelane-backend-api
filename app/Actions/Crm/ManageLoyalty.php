<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Audit\AuditTrail;
use App\Actions\Billing\InvoiceParties;
use App\Actions\Ledger\Ledger;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Crm\Loyalty;
use App\Domain\Crm\MembershipBenefits;
use App\Domain\Crm\Prepaid;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\Invoicing;
use App\Domain\Invoicing\VatTreatment;
use App\Domain\Ledger\Postings\Postings;
use App\Domain\Ledger\Postings\PrepaidRedemptionFacts;
use App\Domain\Ledger\Postings\PrepaidSaleFacts;
use App\Domain\Numbering\DocumentType;
use App\Domain\Receivables\PaymentMethod;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingAddon;
use App\Models\DetailingBooking;
use App\Models\DetailingPackage;
use App\Models\InvoiceBooking;
use App\Models\LoyaltyEntry;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Payment;
use App\Models\PrepaidPackage;
use App\Models\PrepaidPlan;
use App\Models\PrepaidRedemption;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Memberships, prepaid packages and loyalty points.
 *
 * **Prepaid packages are money.** A sale takes the price in and owes it as
 * service: Dr the cash-side account of the payment method / Cr Unearned
 * Revenue, posted in the sale's transaction. The price is VAT-inclusive: a
 * VAT-registered branch credits Output VAT for the VAT inside it there and
 * then (Phase 12), and Unearned Revenue for the rest. Each credit redeemed
 * earns its share of that net (`Prepaid::redemptionCents`): Dr Unearned
 * Revenue / Cr Sales – Detailing. A package's redemptions add up to its net
 * price, to the centavo, so Unearned Revenue always equals what is still owed. Redemptions are
 * append-only (R7); the package's reference is numbered in its transaction
 * (R8).
 */
final class ManageLoyalty
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
        private readonly DocumentNumbers $numbers,
        private readonly Ledger $ledger,
        private readonly CrmSettings $settings,
        private readonly InvoiceParties $parties,
    ) {}

    /* ------------------------------------------------------------ memberships */

    /**
     * @param  array{name?: string, discount_pct?: string, priority_booking?: bool, free_addon_ids?: list<string>, perks?: string|null, is_active?: bool, position?: int}  $data
     */
    public function saveTier(?MembershipTier $tier, array $data): MembershipTier
    {
        return DB::transaction(function () use ($tier, $data): MembershipTier {
            $before = $tier === null ? null : AuditTrail::snapshot($tier);
            $tier ??= (new MembershipTier)->forceFill(['organization_id' => $this->tenancy->require()->organizationId()]);
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                $taken = MembershipTier::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($tier->exists, fn ($q) => $q->whereKeyNot($tier->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['name' => 'Another tier has that name.']);
                }
            }
            if (isset($data['free_addon_ids'])) {
                $ids = array_values(array_unique($data['free_addon_ids']));
                if (DetailingAddon::query()->whereIn('id', $ids)->count() !== count($ids)) {
                    throw ValidationException::withMessages(['free_addon_ids' => 'Pick add-ons from a branch\'s menu.']);
                }
                $data['free_addon_ids'] = $ids;
            }
            $tier->forceFill($data)->save();
            $this->audit->record($tier, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($tier));

            return $tier;
        });
    }

    /**
     * Put a customer on a tier. Whatever membership they held ends as this one starts.
     *
     * @param  array{tier_id: string, started_on?: string|null, expires_on?: string|null, notes?: string|null}  $data
     */
    public function enrol(CustomerAccount $account, array $data): Membership
    {
        return DB::transaction(function () use ($account, $data): Membership {
            CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            $tier = MembershipTier::query()->whereKey($data['tier_id'])->where('is_active', true)->first();
            if (! $tier instanceof MembershipTier) {
                throw ValidationException::withMessages(['tier_id' => 'Pick a tier that is on offer.']);
            }
            foreach (Membership::query()->where('customer_account_id', $account->id)->where('status', 'active')->lockForUpdate()->get() as $current) {
                $before = AuditTrail::snapshot($current);
                $current->forceFill(['status' => 'cancelled'])->save();
                $this->audit->record($current, 'replaced', $before, AuditTrail::snapshot($current));
            }

            $membership = new Membership;
            $membership->forceFill([
                'organization_id' => $account->organization_id,
                'customer_account_id' => $account->id,
                'tier_id' => $tier->id,
                'started_on' => $data['started_on'] ?? Calendar::toDate(CarbonImmutable::now()),
                'expires_on' => $data['expires_on'] ?? null,
                'notes' => $data['notes'] ?? null,
                'created_by_name' => $this->actor()->name,
            ])->save();
            $this->audit->record($membership, 'enrolled', null, AuditTrail::snapshot($membership));

            return $membership;
        });
    }

    public function cancelMembership(Membership $membership): Membership
    {
        return DB::transaction(function () use ($membership): Membership {
            $locked = Membership::query()->lockForUpdate()->findOrFail($membership->id);
            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['status' => 'cancelled'])->save();
            $this->audit->record($locked, 'cancelled', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /** The membership a customer holds on a day: active, started, not expired. */
    public function membershipOf(string $accountId, ?string $on = null): ?Membership
    {
        $on ??= Calendar::toDate(CarbonImmutable::now());

        return Membership::query()->where('customer_account_id', $accountId)->where('status', 'active')->where('started_on', '<=', $on)
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', $on))->with('tier')->first();
    }

    /** What that membership does to a bill; null with no membership. */
    public function benefitsOf(string $accountId, ?string $on = null): ?MembershipBenefits
    {
        $tier = $this->membershipOf($accountId, $on)?->tier;

        return $tier === null ? null : new MembershipBenefits($tier->name, (string) $tier->discount_pct->strippedOfTrailingZeros(), $tier->priority_booking, $tier->free_addon_ids);
    }

    /* --------------------------------------------------------------- packages */

    /**
     * @param  array{name?: string, price_cents?: int, credits?: int, detailing_package_id?: string|null, valid_days?: int|null, is_active?: bool}  $data
     */
    public function savePlan(?PrepaidPlan $plan, array $data): PrepaidPlan
    {
        return DB::transaction(function () use ($plan, $data): PrepaidPlan {
            $before = $plan === null ? null : AuditTrail::snapshot($plan);
            $plan ??= (new PrepaidPlan)->forceFill(['organization_id' => $this->tenancy->require()->organizationId()]);
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                $taken = PrepaidPlan::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($plan->exists, fn ($q) => $q->whereKeyNot($plan->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['name' => 'Another plan has that name.']);
                }
            }
            if (isset($data['detailing_package_id']) && ! DetailingPackage::query()->whereKey($data['detailing_package_id'])->exists()) {
                throw ValidationException::withMessages(['detailing_package_id' => 'That detailing package was not found.']);
            }
            $plan->forceFill($data)->save();
            $this->audit->record($plan, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($plan));

            return $plan;
        });
    }

    /**
     * Sell a plan: the package is numbered, the money is booked, in one transaction.
     *
     * @param  array{plan_id: string, payment_method: string, payment_reference?: string|null}  $data
     */
    public function sell(CustomerAccount $account, Branch $branch, array $data): PrepaidPackage
    {
        $method = PaymentMethod::from($data['payment_method']);
        if ($method->needsReference() && trim((string) ($data['payment_reference'] ?? '')) === '') {
            throw ValidationException::withMessages(['payment_reference' => "A {$method->label()} payment needs its reference number."]);
        }
        if ($account->status !== 'active') {
            throw new ConflictException("{$account->display_name} is suspended.");
        }

        return DB::transaction(function () use ($account, $branch, $data, $method): PrepaidPackage {
            $plan = PrepaidPlan::query()->whereKey($data['plan_id'])->where('is_active', true)->first();
            if (! $plan instanceof PrepaidPlan) {
                throw ValidationException::withMessages(['plan_id' => 'Pick a plan that is on sale.']);
            }
            $now = CarbonImmutable::now();
            $today = Calendar::toDate($now);
            $actor = $this->actor();
            // A package is sold VAT-inclusive: a VAT-registered branch owes the VAT inside the price when it is sold.
            $vat = $this->parties->vat($branch, $account);
            $vatCents = $vat->vatRegistered ? Invoicing::totals([new InvoiceLineDraft(InvoiceLineKind::Manual, $plan->name, '1', $plan->price_cents)], new VatTreatment(true, true, $vat->vatRatePct))->vatAmountCents : 0;

            $package = new PrepaidPackage;
            $package->forceFill([
                'organization_id' => $account->organization_id,
                'branch_id' => $branch->id,
                'customer_account_id' => $account->id,
                'plan_id' => $plan->id,
                'reference' => $this->numbers->issue($account->organization_id, null, DocumentType::PrepaidPackage, $now)->formatted,
                'plan_name' => $plan->name,
                'price_cents' => $plan->price_cents,
                'vat_cents' => $vatCents,
                'vat_rate_pct' => $vat->vatRegistered ? $vat->vatRatePct : '0',
                'credits_total' => $plan->credits,
                'detailing_package_id' => $plan->detailing_package_id,
                'sold_on' => $today,
                'expires_on' => $plan->valid_days === null ? null : Calendar::toDate(Calendar::addDays($now, $plan->valid_days)),
                'payment_method' => $method,
                'payment_reference' => $data['payment_reference'] ?? null,
                'sold_by' => $actor->id,
                'sold_by_name' => $actor->name,
            ])->save();

            $this->ledger->post($account->organization_id, Postings::postingsFor(new PrepaidSaleFacts(
                $package->id, $package->reference, $branch->id, $account->id, $account->display_name, $today, $package->price_cents, $method, $vatCents,
            )));
            $this->audit->record($package, 'sold', null, AuditTrail::snapshot($package));

            return $package;
        });
    }

    /**
     * Use one credit of a package for a finished, signed-off detailing job.
     * The job is then settled: it cannot also be invoiced.
     */
    public function redeem(PrepaidPackage $package, DetailingBooking $booking): PrepaidRedemption
    {
        return DB::transaction(function () use ($package, $booking): PrepaidRedemption {
            $locked = PrepaidPackage::query()->lockForUpdate()->findOrFail($package->id);
            $job = DetailingBooking::query()->lockForUpdate()->findOrFail($booking->id);
            $today = Calendar::toDate(CarbonImmutable::now());

            $refuse = fn (string $message, string $reason): ConflictException => new ConflictException($message, ['reason' => $reason]);
            if ($job->customer_account_id !== $locked->customer_account_id) {
                throw ValidationException::withMessages(['prepaid_package_id' => 'That package belongs to another customer.']);
            }
            if ($job->status !== BookingStatus::Completed || $job->qc_status !== DetailingBooking::QC_PASSED) {
                throw $refuse("{$job->reference} is redeemed against once it is finished and signed off.", 'not_billable');
            }
            if ($locked->detailing_package_id !== null && $locked->detailing_package_id !== $job->package_id) {
                throw $refuse("{$locked->reference} pays for another package, not {$job->package_name}.", 'wrong_package');
            }
            if ($locked->credits_used >= $locked->credits_total) {
                throw $refuse("{$locked->reference} has no credits left.", 'no_credits');
            }
            if ($locked->expires_on !== null && $locked->expires_on->toDateString() < $today) {
                throw $refuse("{$locked->reference} expired on {$locked->expires_on->format('j F Y')}.", 'expired');
            }
            if (PrepaidRedemption::query()->where('detailing_booking_id', $job->id)->exists() || InvoiceBooking::query()->where('booking_id', $job->id)->whereNull('released_at')->exists()) {
                throw $refuse("{$job->reference} is already settled.", 'already_settled');
            }

            // What a credit earns is its share of the price BEFORE VAT: the VAT was charged when the package was sold.
            $amount = Prepaid::redemptionCents($locked->netPriceCents(), $locked->credits_total, $locked->credits_used, 1);
            $redemption = new PrepaidRedemption;
            $redemption->forceFill([
                'organization_id' => $locked->organization_id,
                'prepaid_package_id' => $locked->id,
                'branch_id' => $job->branch_id,
                'detailing_booking_id' => $job->id,
                'credits' => 1,
                'amount_cents' => $amount,
                'redeemed_on' => $today,
                'redeemed_by_name' => $this->actor()->name,
            ])->save();
            $locked->forceFill(['credits_used' => $locked->credits_used + 1])->save();

            $this->ledger->post($locked->organization_id, Postings::postingsFor(new PrepaidRedemptionFacts(
                $redemption->id,
                $locked->reference,
                $job->branch_id,
                $locked->customer_account_id,
                $today,
                $amount,
                sprintf('%s: credit %d of %d for %s', $locked->reference, $locked->credits_used, $locked->credits_total, $job->reference),
            )));
            $this->audit->record($redemption, 'redeemed', null, AuditTrail::snapshot($redemption));

            return $redemption;
        });
    }

    /**
     * A customer's packages with credits left and time to use them.
     *
     * @return Collection<int, PrepaidPackage>
     */
    public function usablePackages(string $accountId): Collection
    {
        $today = Calendar::toDate(CarbonImmutable::now());

        return PrepaidPackage::query()->where('customer_account_id', $accountId)->whereColumn('credits_used', '<', 'credits_total')
            ->where(fn ($query) => $query->whereNull('expires_on')->orWhere('expires_on', '>=', $today))->orderBy('sold_on')->orderBy('id')->get();
    }

    /* ----------------------------------------------------------------- points */

    public function pointsBalance(string $accountId): int
    {
        return (int) LoyaltyEntry::query()->where('customer_account_id', $accountId)->sum('points');
    }

    /** Points for a payment received, once, where the organization has points on. */
    public function earnFor(Payment $payment): ?LoyaltyEntry
    {
        $settings = $this->settings->of($payment->organization_id);
        $points = $settings->loyalty_enabled ? Loyalty::pointsFor($payment->amount_cents, $settings->loyalty_cents_per_point) : 0;
        if ($points < 1 || LoyaltyEntry::query()->where('payment_id', $payment->id)->where('reason', 'earned')->exists()) {
            return null;
        }

        return $this->entry($payment->organization_id, $payment->customer_account_id, $points, 'earned', $payment->id, "Payment {$payment->number}", 'System');
    }

    /** A voided payment gives its points back. */
    public function reverseFor(Payment $payment): ?LoyaltyEntry
    {
        $earned = LoyaltyEntry::query()->where('payment_id', $payment->id)->where('reason', 'earned')->first();
        if ($earned === null || LoyaltyEntry::query()->where('payment_id', $payment->id)->where('reason', 'reversed')->exists()) {
            return null;
        }

        return $this->entry($payment->organization_id, $payment->customer_account_id, -$earned->points, 'reversed', $payment->id, "Payment {$payment->number} voided", 'System');
    }

    /** Points spent by the customer, or a correction either way. A balance never goes below zero. */
    public function adjust(CustomerAccount $account, int $points, string $reason, ?string $note): LoyaltyEntry
    {
        return DB::transaction(function () use ($account, $points, $reason, $note): LoyaltyEntry {
            CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if ($this->pointsBalance($account->id) + $points < 0) {
                throw new ConflictException("{$account->display_name} does not have that many points.", ['reason' => 'insufficient_points']);
            }
            $entry = $this->entry($account->organization_id, $account->id, $points, $reason, null, $note, $this->actor()->name);
            $this->audit->record($entry, 'recorded', null, AuditTrail::snapshot($entry));

            return $entry;
        });
    }

    private function entry(string $organizationId, string $accountId, int $points, string $reason, ?string $paymentId, ?string $note, string $by): LoyaltyEntry
    {
        $entry = new LoyaltyEntry;
        $entry->forceFill([
            'organization_id' => $organizationId,
            'customer_account_id' => $accountId,
            'points' => $points,
            'reason' => $reason,
            'payment_id' => $paymentId,
            'note' => $note,
            'created_by_name' => $by,
        ])->save();

        return $entry;
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }
}
