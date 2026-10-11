<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\ManageRelationships;
use App\Domain\Crm\LeadStage;
use App\Http\Requests\Crm\CrmRequest;
use App\Http\Resources\CrmPresenter;
use App\Models\CrmLead;
use App\Models\CrmSetting;
use App\Models\CrmTask;
use App\Tenancy\TenantManager;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** The CRM's day-to-day work (staff, `crm` module): tasks and the B2B pipeline. */
final class CrmWorkController
{
    public function __construct(
        private readonly ManageRelationships $relationships,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * List tasks
     *
     * `crm:view`. Open tasks first, the soonest due at the top. `status`
     * (`open`, `done`, `cancelled`, or `all`; default `open`), `mine` for the
     * caller's own, `customer_account_id` or `lead_id` for one subject's.
     * `summary` counts the open ones: overdue, due today, with a reminder due.
     */
    public function tasks(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $status = $request->word('status') ?? CrmTask::OPEN;
        $query = CrmTask::query()->with(['assignee', 'customerAccount', 'lead'])
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->when($request->boolean('mine'), fn ($q) => $q->where('assignee_id', $this->tenancy->require()->userId))
            ->when($request->id('customer_account_id') !== null, fn ($q) => $q->where('customer_account_id', $request->id('customer_account_id')))
            ->when($request->id('lead_id') !== null, fn ($q) => $q->where('lead_id', $request->id('lead_id')));
        $page = $query->orderByRaw("status = 'open' desc")->orderByRaw('due_on asc nulls last')->orderByDesc('created_at')->orderBy('id')->paginate($request->perPage());
        $rows = array_map(CrmPresenter::task(...), array_values($page->items()));
        $open = array_map(CrmPresenter::task(...), array_values(CrmTask::query()->where('status', CrmTask::OPEN)->with(['assignee', 'customerAccount', 'lead'])->get()->all()));

        return new JsonResponse(['data' => $rows, 'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()], 'summary' => [
            'open' => count($open),
            'overdue' => count(array_filter($open, fn (array $t): bool => $t['overdue'] === true)),
            'reminders_due' => count(array_filter($open, fn (array $t): bool => $t['reminder_due'] === true)),
            'booking_requests' => count(array_filter($open, fn (array $t): bool => $t['kind'] === 'booking_request')),
            'unassigned' => count(array_filter($open, fn (array $t): bool => $t['assignee_id'] === null)),
        ]]);
    }

    /**
     * Add a task
     *
     * `crm:work`. About a customer or a lead; with an assignee, a due date and
     * a reminder time when wanted.
     */
    public function storeTask(CrmRequest $request): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::task($this->relationships->saveTask(null, $request->task())->load(['assignee', 'customerAccount', 'lead']))], 201);
    }

    /**
     * Edit a task
     *
     * `crm:work`. Only what is sent changes; a reminder moved to a new time is due again.
     */
    public function updateTask(CrmRequest $request, CrmTask $task): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::task($this->relationships->saveTask($task, $request->task())->load(['assignee', 'customerAccount', 'lead']))]);
    }

    /**
     * Finish, cancel or re-open a task
     *
     * `crm:work`. `status`: `done`, `cancelled` or `open`.
     */
    public function taskStatus(CrmRequest $request, CrmTask $task): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);
        $status = (string) $request->word('status');
        abort_unless(in_array($status, [CrmTask::OPEN, CrmTask::DONE, CrmTask::CANCELLED], true), 422, 'A task is open, done or cancelled.');

        return new JsonResponse(['data' => CrmPresenter::task($this->relationships->setTaskStatus($task, $status)->load(['assignee', 'customerAccount', 'lead']))]);
    }

    /**
     * The pipeline board
     *
     * `crm:view`. Every lead, grouped by stage in board order, with each
     * stage's count and value, and the open pipeline's total.
     */
    public function leads(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $leads = CrmLead::query()->with('owner')
            ->when($request->boolean('mine'), fn ($q) => $q->where('owner_id', $this->tenancy->require()->userId))
            ->orderByRaw('next_step_on asc nulls last')->orderByDesc('value_cents')->orderBy('id')->get();

        $stages = [];
        foreach (LeadStage::cases() as $stage) {
            $own = $leads->where('stage', $stage);
            $stages[] = ['value' => $stage->value, 'label' => $stage->label(), 'open' => $stage->isOpen(), 'count' => $own->count(), 'value_cents' => self::value($own), 'leads' => array_values($own->map(CrmPresenter::lead(...))->all())];
        }
        $open = $leads->filter(fn (CrmLead $lead): bool => $lead->stage->isOpen());

        return new JsonResponse(['data' => ['stages' => $stages, 'summary' => [
            'open' => $open->count(),
            'open_value_cents' => self::value($open),
            'won' => $leads->where('stage', LeadStage::Won)->count(),
            'won_value_cents' => self::value($leads->where('stage', LeadStage::Won)),
            'lost' => $leads->where('stage', LeadStage::Lost)->count(),
        ]]]);
    }

    /**
     * Add a lead
     *
     * `crm:work`. It starts in `new`, owned by whoever adds it unless an owner is named.
     */
    public function storeLead(CrmRequest $request): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::lead($this->relationships->saveLead(null, $request->lead())->load('owner'))], 201);
    }

    /**
     * Show a lead
     *
     * With its tasks.
     */
    public function showLead(CrmLead $lead): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $tasks = CrmTask::query()->where('lead_id', $lead->id)->with(['assignee', 'customerAccount', 'lead'])->orderByRaw("status = 'open' desc")->orderBy('due_on')->get();

        return new JsonResponse(['data' => CrmPresenter::lead($lead->load('owner')) + ['tasks' => array_values($tasks->map(CrmPresenter::task(...))->all())]]);
    }

    /**
     * Edit a lead
     *
     * `crm:work`. Its own fields; the stage moves through its own action. A won lead is closed to edits (409).
     */
    public function updateLead(CrmRequest $request, CrmLead $lead): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::lead($this->relationships->saveLead($lead, $request->lead())->load('owner'))]);
    }

    /**
     * Move a lead to another stage
     *
     * `crm:work`. Between the open stages freely; to `lost` with a
     * `lost_reason`; a lost lead may be re-opened. `won` is refused here (409
     * `convert_to_win`): a lead is won by converting it.
     */
    public function moveLead(CrmRequest $request, CrmLead $lead): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::lead($this->relationships->moveLead($lead, LeadStage::from((string) $request->word('stage')), $request->word('lost_reason'))->load('owner'))]);
    }

    /**
     * Win a lead: convert it to a customer account
     *
     * `crm:work`. Creates a company account with the terms and approval
     * overrides given, tagged `dealer` when the lead was one, and records the
     * customer's consent (`consent_channel` says how it was given;
     * `service_reminders` and `marketing` only when they agreed to them). The
     * lead becomes `won` and its tasks move to the account.
     */
    public function convertLead(CrmRequest $request, CrmLead $lead): JsonResponse
    {
        Gate::authorize('work', CrmSetting::class);
        $account = $this->relationships->convertLead($lead, $request->conversion() + ['approval_overrides' => $request->approvalOverrides()]);

        return new JsonResponse(['data' => CrmPresenter::lead($lead->refresh()->load('owner')) + ['customer_account' => ['id' => $account->id, 'display_name' => $account->display_name]]], 201);
    }

    /**
     * @param  Collection<int, CrmLead>  $leads
     */
    private static function value(Collection $leads): int
    {
        return array_sum(array_map(fn (CrmLead $lead): int => $lead->value_cents, array_values($leads->all())));
    }
}
