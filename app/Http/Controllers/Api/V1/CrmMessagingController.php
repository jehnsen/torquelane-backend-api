<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Crm\BuildReminders;
use App\Actions\Crm\CrmSettings;
use App\Actions\Crm\ManageFeedback;
use App\Actions\Crm\ManageMessaging;
use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Domain\Crm\Reminders\ReminderKind;
use App\Http\Requests\Crm\CrmRequest;
use App\Http\Resources\CrmPresenter;
use App\Models\CrmSetting;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\ReminderRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Messaging (staff, `crm` module): the settings every message is held to,
 * templates, reminder rules, the message log and feedback.
 */
final class CrmMessagingController
{
    public function __construct(
        private readonly CrmSettings $settings,
        private readonly ManageMessaging $messaging,
        private readonly ManageFeedback $feedback,
    ) {}

    /**
     * CRM settings
     *
     * Quiet hours (minutes of the Manila day), the reminder cooldown, when a
     * customer counts as lapsed, the campaign hourly limit, and whether
     * feedback and loyalty points are on.
     */
    public function settings(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::settings($this->settings->current())]);
    }

    /**
     * Change CRM settings
     *
     * `crm:manage`. Only what is sent changes.
     */
    public function updateSettings(CrmRequest $request): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::settings($this->settings->save($request->settings()))]);
    }

    /**
     * List templates
     *
     * `crm:view`. With the placeholders each uses, and the lists the form offers.
     */
    public function templates(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);

        return new JsonResponse(['data' => array_values(MessageTemplate::query()->orderBy('purpose')->orderBy('name')->get()->map(CrmPresenter::template(...))->all()), 'meta' => [
            'channels' => array_map(fn (MessageChannel $c): array => ['value' => $c->value, 'label' => $c->label()], MessageChannel::cases()),
            'purposes' => array_map(fn (MessagePurpose $p): array => ['value' => $p->value, 'label' => $p->label(), 'consent' => $p->consent()?->value], MessagePurpose::cases()),
            'standard_placeholders' => ['customer_name', 'shop', 'shop_phone', 'link', 'unsubscribe_link'],
        ]]);
    }

    /**
     * Add a template
     *
     * `crm:manage`.
     */
    public function storeTemplate(CrmRequest $request): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::template($this->messaging->saveTemplate(null, $request->template()))], 201);
    }

    /**
     * Edit a template
     *
     * `crm:manage`. A template a rule or a campaign uses keeps its purpose (409).
     */
    public function updateTemplate(CrmRequest $request, MessageTemplate $template): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => CrmPresenter::template($this->messaging->saveTemplate($template, $request->template()))]);
    }

    /**
     * Delete a template
     *
     * `crm:manage`. Only one that was never used; switch a used one off instead (409).
     */
    public function destroyTemplate(MessageTemplate $template): Response
    {
        Gate::authorize('manage', CrmSetting::class);
        $this->messaging->deleteTemplate($template);

        return response()->noContent();
    }

    /**
     * Reminder rules
     *
     * `crm:view`. The four kinds of service reminder, each with its rule
     * (`null` until one is set).
     */
    public function rules(): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $rules = ReminderRule::query()->with('template')->get()->keyBy(fn (ReminderRule $rule): string => $rule->kind->value);

        return new JsonResponse(['data' => array_map(fn (ReminderKind $kind): array => [
            'kind' => $kind->value,
            'label' => $kind->label(),
            'has_lead_days' => $kind->hasLeadDays(),
            'rule' => ($rule = $rules->get($kind->value)) === null ? null : CrmPresenter::rule($rule),
        ], ReminderKind::cases())]);
    }

    /**
     * Set a reminder rule
     *
     * `crm:manage`. Whether this kind of reminder is sent, with which
     * service-reminder template, and (for documents) how many days ahead.
     */
    public function updateRule(CrmRequest $request, string $kind): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);
        $reminder = ReminderKind::tryFrom($kind);
        abort_if($reminder === null, 404);

        return new JsonResponse(['data' => CrmPresenter::rule($this->messaging->saveRule($reminder, $request->rule())->load('template'))]);
    }

    /**
     * Build the reminders now
     *
     * `crm:manage`. The run the schedule makes every morning: candidates from
     * the maintenance engine, detailing repeat intervals, document expiries
     * and lapsed customers; each held to consent, opt-out, the cooldown and
     * quiet hours. Returns how many were queued and why the rest were not.
     */
    public function runReminders(BuildReminders $reminders): JsonResponse
    {
        Gate::authorize('manage', CrmSetting::class);

        return new JsonResponse(['data' => $reminders->run()]);
    }

    /**
     * The message log
     *
     * `crm:view`. Everything sent, queued, failed and skipped, newest first.
     * `status`, `purpose`, `source_type` (+ `source_id`) and
     * `customer_account_id` narrow it. A skipped message says why
     * (`skip_reason`: `no_consent`, `opted_out`, `no_address`).
     */
    public function messages(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $page = Message::query()->with('customerAccount')
            ->when($request->word('status') !== null, fn ($q) => $q->where('status', $request->word('status')))
            ->when($request->word('purpose') !== null, fn ($q) => $q->where('purpose', $request->word('purpose')))
            ->when($request->word('source_type') !== null, fn ($q) => $q->where('source_type', $request->word('source_type')))
            ->when($request->id('source_id') !== null, fn ($q) => $q->where('source_id', $request->id('source_id')))
            ->when($request->id('customer_account_id') !== null, fn ($q) => $q->where('customer_account_id', $request->id('customer_account_id')))
            ->orderByDesc('created_at')->orderByDesc('id')->paginate($request->perPage());
        $counts = Message::query()->toBase()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        return new JsonResponse([
            'data' => array_map(CrmPresenter::message(...), array_values($page->items())),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
            'summary' => array_map(fn (string $status): int => is_numeric($n = $counts->get($status)) ? (int) $n : 0, ['queued' => 'queued', 'sent' => 'sent', 'failed' => 'failed', 'skipped' => 'skipped']),
        ]);
    }

    /**
     * Feedback
     *
     * `crm:view`. The organization's Net Promoter Score and the answers
     * behind it, newest first.
     */
    public function feedback(CrmRequest $request): JsonResponse
    {
        Gate::authorize('view', CrmSetting::class);
        $overview = $this->feedback->overview($request->id('customer_account_id'));

        return new JsonResponse(['data' => array_map(CrmPresenter::feedback(...), $overview['answers']), 'summary' => $overview['summary']]);
    }
}
