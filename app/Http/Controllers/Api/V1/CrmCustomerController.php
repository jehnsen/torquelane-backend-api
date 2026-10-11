<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\Customer360;
use App\Actions\Crm\ManageCampaigns;
use App\Actions\Crm\ManageLoyalty;
use App\Actions\Crm\ManageRelationships;
use App\Domain\Crm\LeadStage;
use App\Http\Requests\Crm\CrmRequest;
use App\Http\Resources\CrmPresenter;
use App\Models\CrmNote;
use App\Models\CrmSetting;
use App\Models\CrmTask;
use App\Models\CustomerAccount;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Customers as the CRM sees them (staff, `crm` module): the list, the
 * Customer 360, and what is kept on a customer there (notes, membership,
 * points).
 */
final class CrmCustomerController
{
    public function __construct(
        private readonly Customer360 $view,
        private readonly ManageRelationships $relationships,
        private readonly ManageLoyalty $loyalty,
        private readonly ManageCampaigns $segments,
    ) {}

    /**
     * The CRM's lists
     *
     * The staff a task or a lead can be given to, the pipeline's stages, and
     * the lists the forms offer.
     */
    public function options(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => [
            'staff' => array_values(User::query()->where('side', 'staff')->where('status', 'active')->orderBy('name')->get()->map(fn (User $u): array => ['id' => $u->id, 'name' => $u->name, 'role' => $u->role->value])->all()),
            'lead_stages' => LeadStage::board(),
            'task_kinds' => [['value' => 'follow_up', 'label' => 'Follow-up'], ['value' => 'call', 'label' => 'Call'], ['value' => 'booking_request', 'label' => 'Booking request'], ['value' => 'other', 'label' => 'Other']],
        ]]);
    }

    /**
     * List customers
     *
     * `crm:view`. Every customer account of the organization, by name, with
     * what the CRM knows of each: what they have paid, when they were last
     * served, their tier and their open tasks. `q` searches the name.
     */
    public function index(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $q = $request->word('q');
        $page = CustomerAccount::query()
            ->when($q !== null, fn ($query) => $query->where('display_name', 'ilike', '%'.addcslashes((string) $q, '%_\\').'%'))
            ->orderBy('display_name')->orderBy('id')->paginate($request->perPage());
        $accounts = array_values($page->items());
        $openTasks = CrmTask::query()->where('status', CrmTask::OPEN)->whereIn('customer_account_id', array_map(fn (CustomerAccount $a): string => $a->id, $accounts))
            ->selectRaw('customer_account_id, count(*) as n')->groupBy('customer_account_id')->toBase()->pluck('n', 'customer_account_id');
        $tiers = Membership::query()->where('status', 'active')->with('tier')->whereIn('customer_account_id', array_map(fn (CustomerAccount $a): string => $a->id, $accounts))->get()->keyBy('customer_account_id');

        $rows = [];
        foreach ($this->segments->profiles($accounts) as $index => $profile) {
            $account = $accounts[$index];
            $rows[] = [
                'id' => $account->id,
                'display_name' => $account->display_name,
                'account_type' => $account->account_type,
                'status' => $account->status,
                'tags' => $account->tags,
                'spend_cents' => $profile->spendCents,
                'last_visit_on' => $profile->lastVisitOn,
                'tier_name' => $tiers->get($account->id)?->tier->name,
                'open_tasks' => is_numeric($n = $openTasks->get($account->id)) ? (int) $n : 0,
            ];
        }

        return new JsonResponse(['data' => $rows, 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()]]);
    }

    /**
     * Customer 360
     *
     * `crm:view`. Everything on record about one customer, in one read:
     * vehicles, jobs, invoices and balance, payments and bookings at EVERY
     * branch, consents, notes, tasks, messages, feedback, membership, prepaid
     * packages, points, and a timeline built from those records.
     */
    public function show(CustomerAccount $customer_account): JsonResponse
    {
        Gate::authorize('viewCustomer', [CrmSetting::class, $customer_account]);

        return new JsonResponse(['data' => $this->view->for($customer_account)]);
    }

    /**
     * A customer's benefits
     *
     * `crm:view`. What to tell the customer at the counter: the tier they
     * hold and what it gives, the prepaid packages they can still use, and
     * their points.
     */
    public function benefits(CustomerAccount $customer_account): JsonResponse
    {
        Gate::authorize('viewCustomer', [CrmSetting::class, $customer_account]);
        $membership = $this->loyalty->membershipOf($customer_account->id);

        return new JsonResponse(['data' => [
            'customer_account_id' => $customer_account->id,
            'membership' => $membership === null ? null : CrmPresenter::membership($membership),
            'packages' => array_values($this->loyalty->usablePackages($customer_account->id)->map(CrmPresenter::package(...))->all()),
            'points' => $this->loyalty->pointsBalance($customer_account->id),
        ]]);
    }

    /**
     * Add a note
     *
     * `crm:work`.
     */
    public function storeNote(CrmRequest $request, CustomerAccount $customer_account): JsonResponse
    {
        Gate::authorize('workCustomer', [CrmSetting::class, $customer_account]);
        $data = $request->note();

        return new JsonResponse(['data' => CrmPresenter::note($this->relationships->addNote($customer_account, ['body' => $data['body'] ?? '', 'vehicle_id' => $data['vehicle_id'] ?? null, 'pinned' => $data['pinned'] ?? false]))], 201);
    }

    /**
     * Edit a note
     *
     * `crm:work`. Its text, or whether it is pinned.
     */
    public function updateNote(CrmRequest $request, CustomerAccount $customer_account, CrmNote $note): JsonResponse
    {
        Gate::authorize('workCustomer', [CrmSetting::class, $customer_account]);
        abort_unless($note->customer_account_id === $customer_account->id, 404);
        $data = $request->note();

        return new JsonResponse(['data' => CrmPresenter::note($this->relationships->editNote($note, array_intersect_key($data, ['body' => true, 'pinned' => true])))]);
    }

    /**
     * Remove a note
     *
     * `crm:work`.
     */
    public function destroyNote(CustomerAccount $customer_account, CrmNote $note): Response
    {
        Gate::authorize('workCustomer', [CrmSetting::class, $customer_account]);
        abort_unless($note->customer_account_id === $customer_account->id, 404);
        $this->relationships->deleteNote($note);

        return response()->noContent();
    }

    /**
     * Put a customer on a tier
     *
     * `crm:work`. Whatever membership they held ends as this one starts.
     */
    public function enrol(CrmRequest $request, CustomerAccount $customer_account): JsonResponse
    {
        Gate::authorize('workCustomer', [CrmSetting::class, $customer_account]);

        return new JsonResponse(['data' => CrmPresenter::membership($this->loyalty->enrol($customer_account, $request->enrolment())->load('tier'))], 201);
    }

    /**
     * End a membership
     *
     * `crm:work`.
     */
    public function cancelMembership(CustomerAccount $customer_account, Membership $membership): JsonResponse
    {
        Gate::authorize('workCustomer', [CrmSetting::class, $customer_account]);
        abort_unless($membership->customer_account_id === $customer_account->id, 404);

        return new JsonResponse(['data' => CrmPresenter::membership($this->loyalty->cancelMembership($membership)->load('tier'))]);
    }

    /**
     * Spend or correct points
     *
     * `crm:work`. `points` is signed: negative for points the customer spent
     * (`redeemed`), either way for a correction (`adjusted`). A balance never
     * goes below zero (409).
     */
    public function adjustPoints(CrmRequest $request, CustomerAccount $customer_account): JsonResponse
    {
        Gate::authorize('workCustomer', [CrmSetting::class, $customer_account]);
        $this->loyalty->adjust($customer_account, $request->points(), (string) $request->word('reason'), $request->word('note'));

        return new JsonResponse(['data' => ['customer_account_id' => $customer_account->id, 'points' => $this->loyalty->pointsBalance($customer_account->id)]], 201);
    }
}
