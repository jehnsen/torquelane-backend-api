<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\ManageLoyalty;
use App\Domain\Crm\Prepaid;
use App\Http\Requests\Crm\CrmRequest;
use App\Http\Resources\CrmPresenter;
use App\Models\Branch;
use App\Models\CrmSetting;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\PrepaidPackage;
use App\Models\PrepaidPlan;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Memberships and prepaid packages (staff, `crm` module). */
final class CrmLoyaltyController
{
    public function __construct(
        private readonly ManageLoyalty $loyalty,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * List membership tiers
     *
     * `crm:view`. With how many customers hold each.
     */
    public function tiers(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $members = Membership::query()->where('status', 'active')->toBase()->selectRaw('tier_id, count(*) as n')->groupBy('tier_id')->pluck('n', 'tier_id');

        return new JsonResponse(['data' => array_values(MembershipTier::query()->orderBy('position')->orderBy('name')->get()
            ->map(fn (MembershipTier $tier): array => CrmPresenter::tier($tier, is_numeric($n = $members->get($tier->id)) ? (int) $n : 0))->all())]);
    }

    /**
     * Add a tier
     *
     * `crm:manage`. A discount (0–100%), priority booking, and the detailing add-ons a member gets free.
     */
    public function storeTier(CrmRequest $request): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::tier($this->loyalty->saveTier(null, $request->tier()))], 201);
    }

    /**
     * Edit a tier
     *
     * `crm:manage`. Only what is sent changes.
     */
    public function updateTier(CrmRequest $request, MembershipTier $tier): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::tier($this->loyalty->saveTier($tier, $request->tier()))]);
    }

    /**
     * List prepaid plans
     *
     * `crm:view`.
     */
    public function plans(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => array_values(PrepaidPlan::query()->orderBy('name')->get()->map(CrmPresenter::plan(...))->all())]);
    }

    /**
     * Add a prepaid plan
     *
     * `crm:manage`. So many credits for a price; a credit pays for one
     * detailing job (of `detailing_package_id`, or of any package when null).
     */
    public function storePlan(CrmRequest $request): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::plan($this->loyalty->savePlan(null, $request->plan()))], 201);
    }

    /**
     * Edit a prepaid plan
     *
     * `crm:manage`. Packages already sold keep what they were sold as.
     */
    public function updatePlan(CrmRequest $request, PrepaidPlan $plan): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::plan($this->loyalty->savePlan($plan, $request->plan()))]);
    }

    /**
     * List prepaid packages sold
     *
     * `crm:view`. Newest first; `customer_account_id` for one customer's.
     * `summary.unearned_cents` is what is still owed as service across them
     * all: what Unearned Revenue should hold for prepaid packages.
     */
    public function packages(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $page = PrepaidPackage::query()->with('customerAccount')
            ->when($request->id('customer_account_id') !== null, fn ($q) => $q->where('customer_account_id', $request->id('customer_account_id')))
            ->orderByDesc('sold_on')->orderByDesc('id')->paginate($request->perPage());
        $all = PrepaidPackage::query()->get(['id', 'price_cents', 'vat_cents', 'credits_total', 'credits_used']);

        return new JsonResponse([
            'data' => array_map(CrmPresenter::package(...), array_values($page->items())),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => [
                'sold' => $all->count(),
                'sold_cents' => array_sum(array_map(fn (PrepaidPackage $p): int => $p->price_cents, array_values($all->all()))),
                'unearned_cents' => array_sum(array_map(fn (PrepaidPackage $p): int => Prepaid::unearnedCents($p->netPriceCents(), $p->credits_total, $p->credits_used), array_values($all->all()))),
            ],
        ]);
    }

    /**
     * Sell a prepaid package
     *
     * `crm:work`. Numbered and booked in one transaction: Dr the cash-side
     * account of `payment_method` / Cr Unearned Revenue. `branch_id` is the
     * branch that took the money (the session's branch when not given).
     * Needs an `Idempotency-Key`.
     */
    public function sell(CrmRequest $request): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);
        $context = $this->tenancy->require();
        $branchId = $request->id('branch_id') ?? $context->selectedBranchId ?? (count($context->allowedBranchIds) === 1 ? $context->allowedBranchIds[0] : null);
        if ($branchId === null) {
            throw ValidationException::withMessages(['branch_id' => 'Say which branch took the payment.']);
        }
        if (! $context->branchAllowed($branchId)) {
            throw (new ModelNotFoundException)->setModel(Branch::class, [$branchId]);
        }
        $account = CustomerAccount::query()->findOrFail((string) $request->id('customer_account_id'));

        return new JsonResponse(['data' => CrmPresenter::package($this->loyalty->sell($account, Branch::query()->findOrFail($branchId), $request->sale())->load('customerAccount'))], 201);
    }

    /**
     * Redeem a prepaid credit
     *
     * `crm:work`. One credit of the package settles a finished, signed-off
     * detailing job of the same customer (`detailing_booking_id`), which then
     * cannot be invoiced. Books Dr Unearned Revenue / Cr Sales – Detailing
     * for the credit's share of the price. Refused with 409 and a `reason`
     * (`not_billable`, `wrong_package`, `no_credits`, `expired`,
     * `already_settled`). Needs an `Idempotency-Key`.
     */
    public function redeem(CrmRequest $request, PrepaidPackage $prepaid_package): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);
        $booking = DetailingBooking::query()->findOrFail((string) $request->id('detailing_booking_id'));
        if (! $this->tenancy->require()->branchAllowed($booking->branch_id)) {
            throw (new ModelNotFoundException)->setModel(DetailingBooking::class, [$booking->id]);
        }
        $redemption = $this->loyalty->redeem($prepaid_package, $booking);

        return new JsonResponse(['data' => CrmPresenter::package($prepaid_package->refresh()->load('customerAccount')) + ['redemption' => [
            'id' => $redemption->id,
            'detailing_booking_id' => $redemption->detailing_booking_id,
            'amount_cents' => $redemption->amount_cents,
            'redeemed_on' => $redemption->redeemed_on->toDateString(),
        ]]], 201);
    }
}
