<?php

declare(strict_types=1);

namespace App\Http\Requests\Crm;

use App\Domain\Approvals\ApprovalBands;
use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\LeadStage;
use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Domain\Receivables\PaymentMethod;
use App\Http\Requests\ApiRequest;
use App\Http\Requests\Input;
use Illuminate\Validation\Rule;

/**
 * Every CRM write and list filter. The CRM's forms are small and share their
 * field names (a `name`, a `body`, a `template_id`), so one request carries
 * them all: shape and type here (R3), what a route REQUIRES by its name, and
 * each route reads the part it needs through a typed accessor.
 */
final class CrmRequest extends ApiRequest
{
    private const string DECIMAL = 'regex:/^\d+(\.\d{1,2})?$/';

    /** What each creating route must be given. */
    private const array REQUIRED = [
        'crm.customers.notes.store' => ['body'],
        'crm.tasks.store' => ['title'],
        'crm.tasks.status' => ['status'],
        'crm.leads.store' => ['company_name'],
        'crm.leads.stage' => ['stage'],
        'crm.leads.convert' => ['consent_channel'],
        'crm.templates.store' => ['name', 'purpose', 'body'],
        'crm.reminder-rules.update' => ['template_id'],
        'crm.tiers.store' => ['name'],
        'crm.customers.membership.store' => ['tier_id'],
        'crm.prepaid-plans.store' => ['name', 'price_cents', 'credits'],
        'crm.prepaid-packages.store' => ['customer_account_id', 'plan_id', 'payment_method'],
        'crm.prepaid-packages.redeem' => ['detailing_booking_id'],
        'crm.customers.points.store' => ['points', 'reason'],
        'crm.segments.store' => ['name'],
        'crm.campaigns.store' => ['name', 'segment_id', 'template_id'],
        'crm.campaigns.status' => ['status'],
    ];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            // Shared.
            'name' => ['sometimes', 'string', 'max:255'],
            'body' => ['sometimes', 'string', 'max:5000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'note' => ['sometimes', 'nullable', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'string', 'max:16'],
            'branch_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'customer_account_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'template_id' => ['sometimes', 'string', 'ulid'],
            // Lists.
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mine' => ['sometimes', 'boolean'],
            'purpose' => ['sometimes', 'string', Rule::enum(MessagePurpose::class)],
            'source_type' => ['sometimes', 'string', Rule::in(['reminder', 'campaign', 'feedback', 'task', 'manual'])],
            'source_id' => ['sometimes', 'string', 'ulid'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            // Notes and tasks.
            'pinned' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'string', 'max:255'],
            'details' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'kind' => ['sometimes', 'string', Rule::in(['follow_up', 'call', 'booking_request', 'other'])],
            'lead_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'assignee_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'remind_at' => ['sometimes', 'nullable', 'date'],
            // Leads.
            'company_name' => ['sometimes', 'string', 'max:255'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'value_cents' => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'next_step' => ['sometimes', 'nullable', 'string', 'max:255'],
            'next_step_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'is_dealer' => ['sometimes', 'boolean'],
            'stage' => ['sometimes', 'string', Rule::enum(LeadStage::class)],
            'lost_reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Winning a lead.
            'consent_channel' => ['sometimes', 'string', Rule::in(array_map(fn (ConsentChannel $c): string => $c->value, [ConsentChannel::InPerson, ConsentChannel::PaperForm, ConsentChannel::Email, ConsentChannel::Sms, ConsentChannel::Phone]))],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'credit_limit_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'registered_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIN],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'service_reminders' => ['sometimes', 'boolean'],
            'marketing' => ['sometimes', 'boolean'],
            'approval_overrides' => ['sometimes', 'array'],
            'approval_overrides.auto_approve_under_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'approval_overrides.ops_approval_under_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'approval_overrides.sla_hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:720'],
            'approval_overrides.variance_threshold_pct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'approval_overrides.monthly_budget_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Templates and rules.
            'channel' => ['sometimes', 'string', Rule::enum(MessageChannel::class)],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'enabled' => ['sometimes', 'boolean'],
            'lead_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
            // Settings.
            'quiet_start_minutes' => ['sometimes', 'integer', 'min:0', 'max:1439'],
            'quiet_end_minutes' => ['sometimes', 'integer', 'min:0', 'max:1439'],
            'reminder_cooldown_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'lapsed_after_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'campaign_hourly_limit' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'feedback_enabled' => ['sometimes', 'boolean'],
            'loyalty_enabled' => ['sometimes', 'boolean'],
            'loyalty_cents_per_point' => ['sometimes', 'integer', 'min:1', 'max:100000000'],
            // Memberships.
            'discount_pct' => ['sometimes', 'numeric', 'min:0', 'max:100', self::DECIMAL],
            'priority_booking' => ['sometimes', 'boolean'],
            'free_addon_ids' => ['sometimes', 'array', 'max:50'],
            'free_addon_ids.*' => ['string', 'ulid'],
            'perks' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'position' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'tier_id' => ['sometimes', 'string', 'ulid'],
            'started_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:started_on'],
            // Prepaid packages and points.
            'price_cents' => ['sometimes', 'integer', 'min:1', 'max:100000000000'],
            'credits' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'detailing_package_id' => ['sometimes', 'nullable', 'string', 'ulid'],
            'valid_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:3650'],
            'plan_id' => ['sometimes', 'string', 'ulid'],
            'payment_method' => ['sometimes', 'string', Rule::enum(PaymentMethod::class)],
            'payment_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'detailing_booking_id' => ['sometimes', 'string', 'ulid'],
            'points' => ['sometimes', 'integer', 'not_in:0', 'min:-1000000', 'max:1000000'],
            'reason' => ['sometimes', 'string', Rule::in(['redeemed', 'adjusted'])],
            // Segments and campaigns.
            'filters' => ['sometimes', 'array'],
            'filters.min_spend_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.max_spend_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'filters.visited_within_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:36500'],
            'filters.not_visited_for_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:36500'],
            'filters.makes' => ['sometimes', 'array', 'max:50'],
            'filters.makes.*' => ['string', 'max:64'],
            'filters.size_classes' => ['sometimes', 'array'],
            'filters.size_classes.*' => ['string', Rule::in(['small', 'medium', 'large', 'xl'])],
            'filters.tier_ids' => ['sometimes', 'array', 'max:50'],
            'filters.tier_ids.*' => ['string', 'max:26'],
            'filters.branch_ids' => ['sometimes', 'array', 'max:50'],
            'filters.branch_ids.*' => ['string', 'ulid'],
            'filters.account_type' => ['sometimes', 'nullable', 'string', Rule::in(['company', 'individual'])],
            'filters.tags' => ['sometimes', 'array', 'max:50'],
            'filters.tags.*' => ['string', 'max:64'],
            'segment_id' => ['sometimes', 'string', 'ulid'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'hourly_limit' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100000'],
        ];

        foreach (self::REQUIRED[(string) $this->route()?->getName()] ?? [] as $key) {
            $rules[$key] = ['required', ...array_values(array_filter($rules[$key], fn (mixed $rule): bool => $rule !== 'sometimes' && $rule !== 'nullable'))];
        }

        return $rules;
    }

    public function id(string $key): ?string
    {
        return $this->filled($key) ? Input::id($this->validated($key)) : null;
    }

    public function word(string $key): ?string
    {
        return Input::text($this->validated($key));
    }

    public function perPage(): int
    {
        return $this->filled('per_page') ? Input::int($this->validated('per_page')) : 25;
    }

    public function points(): int
    {
        return Input::int($this->validated('points'));
    }

    /**
     * @return array<string, int|bool>
     */
    public function settings(): array
    {
        $out = [];
        foreach (['quiet_start_minutes', 'quiet_end_minutes', 'reminder_cooldown_days', 'lapsed_after_days', 'campaign_hourly_limit', 'loyalty_cents_per_point'] as $key) {
            if ($this->has($key)) {
                $out[$key] = Input::int($this->validated($key));
            }
        }
        foreach (['feedback_enabled', 'loyalty_enabled'] as $key) {
            if ($this->has($key)) {
                $out[$key] = $this->boolean($key);
            }
        }

        return $out;
    }

    /**
     * The approval bands a won lead's account starts with.
     *
     * @return array<string, int|string|null>
     */
    public function approvalOverrides(): array
    {
        $out = [];
        $given = $this->validated('approval_overrides');
        foreach (is_array($given) ? $given : [] as $key => $value) {
            if (in_array($key, ApprovalBands::OVERRIDE_KEYS, true) && (is_int($value) || is_string($value) || $value === null)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * A note about a customer.
     *
     * @return array{body?: string, vehicle_id?: string|null, pinned?: bool}
     */
    public function note(): array
    {
        $out = [];
        if ($this->has('body')) {
            $out['body'] = Input::string($this->validated('body'));
        }
        if ($this->has('vehicle_id')) {
            $out['vehicle_id'] = $this->filled('vehicle_id') ? Input::id($this->validated('vehicle_id')) : null;
        }
        if ($this->has('pinned')) {
            $out['pinned'] = $this->boolean('pinned');
        }

        return $out;
    }

    /**
     * A task. Only what is sent changes on an edit.
     *
     * @return array{title?: string, details?: string|null, kind?: string, customer_account_id?: string|null, lead_id?: string|null, vehicle_id?: string|null, assignee_id?: string|null, due_on?: string|null, remind_at?: string|null}
     */
    public function task(): array
    {
        $out = [];
        if ($this->has('title')) {
            $out['title'] = Input::string($this->validated('title'));
        }
        if ($this->has('details')) {
            $out['details'] = Input::text($this->validated('details'));
        }
        if ($this->has('kind')) {
            $out['kind'] = Input::string($this->validated('kind'));
        }
        if ($this->has('customer_account_id')) {
            $out['customer_account_id'] = $this->filled('customer_account_id') ? Input::id($this->validated('customer_account_id')) : null;
        }
        if ($this->has('lead_id')) {
            $out['lead_id'] = $this->filled('lead_id') ? Input::id($this->validated('lead_id')) : null;
        }
        if ($this->has('vehicle_id')) {
            $out['vehicle_id'] = $this->filled('vehicle_id') ? Input::id($this->validated('vehicle_id')) : null;
        }
        if ($this->has('assignee_id')) {
            $out['assignee_id'] = $this->filled('assignee_id') ? Input::id($this->validated('assignee_id')) : null;
        }
        if ($this->has('due_on')) {
            $out['due_on'] = Input::text($this->validated('due_on'));
        }
        if ($this->has('remind_at')) {
            $out['remind_at'] = Input::text($this->validated('remind_at'));
        }

        return $out;
    }

    /**
     * A lead's own fields.
     *
     * @return array{company_name?: string, contact_name?: string|null, contact_email?: string|null, contact_phone?: string|null, value_cents?: int, owner_id?: string|null, next_step?: string|null, next_step_on?: string|null, source?: string|null, is_dealer?: bool, notes?: string|null, branch_id?: string|null}
     */
    public function lead(): array
    {
        $out = [];
        if ($this->has('company_name')) {
            $out['company_name'] = Input::string($this->validated('company_name'));
        }
        if ($this->has('contact_name')) {
            $out['contact_name'] = Input::text($this->validated('contact_name'));
        }
        if ($this->has('contact_email')) {
            $out['contact_email'] = Input::text($this->validated('contact_email'));
        }
        if ($this->has('contact_phone')) {
            $out['contact_phone'] = Input::text($this->validated('contact_phone'));
        }
        if ($this->has('value_cents')) {
            $out['value_cents'] = Input::int($this->validated('value_cents'));
        }
        if ($this->has('owner_id')) {
            $out['owner_id'] = $this->filled('owner_id') ? Input::id($this->validated('owner_id')) : null;
        }
        if ($this->has('next_step')) {
            $out['next_step'] = Input::text($this->validated('next_step'));
        }
        if ($this->has('next_step_on')) {
            $out['next_step_on'] = Input::text($this->validated('next_step_on'));
        }
        if ($this->has('source')) {
            $out['source'] = Input::text($this->validated('source'));
        }
        if ($this->has('is_dealer')) {
            $out['is_dealer'] = $this->boolean('is_dealer');
        }
        if ($this->has('notes')) {
            $out['notes'] = Input::text($this->validated('notes'));
        }
        if ($this->has('branch_id')) {
            $out['branch_id'] = $this->filled('branch_id') ? Input::id($this->validated('branch_id')) : null;
        }

        return $out;
    }

    /**
     * How a won lead becomes an account.
     *
     * @return array{consent_channel: string, payment_terms_days?: int, credit_limit_cents?: int|null, registered_name?: string|null, tin?: string|null, address?: string|null, service_reminders?: bool, marketing?: bool}
     */
    public function conversion(): array
    {
        $out = ['consent_channel' => Input::string($this->validated('consent_channel'))];
        if ($this->has('payment_terms_days')) {
            $out['payment_terms_days'] = Input::int($this->validated('payment_terms_days'));
        }
        if ($this->has('credit_limit_cents')) {
            $out['credit_limit_cents'] = $this->filled('credit_limit_cents') ? Input::int($this->validated('credit_limit_cents')) : null;
        }
        if ($this->has('registered_name')) {
            $out['registered_name'] = Input::text($this->validated('registered_name'));
        }
        if ($this->has('tin')) {
            $out['tin'] = Input::text($this->validated('tin'));
        }
        if ($this->has('address')) {
            $out['address'] = Input::text($this->validated('address'));
        }
        if ($this->has('service_reminders')) {
            $out['service_reminders'] = $this->boolean('service_reminders');
        }
        if ($this->has('marketing')) {
            $out['marketing'] = $this->boolean('marketing');
        }

        return $out;
    }

    /**
     * A message template.
     *
     * @return array{name?: string, channel?: string, purpose?: string, subject?: string|null, body?: string, is_active?: bool}
     */
    public function template(): array
    {
        $out = [];
        if ($this->has('name')) {
            $out['name'] = Input::string($this->validated('name'));
        }
        if ($this->has('channel')) {
            $out['channel'] = Input::string($this->validated('channel'));
        }
        if ($this->has('purpose')) {
            $out['purpose'] = Input::string($this->validated('purpose'));
        }
        if ($this->has('subject')) {
            $out['subject'] = Input::text($this->validated('subject'));
        }
        if ($this->has('body')) {
            $out['body'] = Input::string($this->validated('body'));
        }
        if ($this->has('is_active')) {
            $out['is_active'] = $this->boolean('is_active');
        }

        return $out;
    }

    /**
     * A reminder rule.
     *
     * @return array{template_id: string, enabled?: bool, lead_days?: int|null}
     */
    public function rule(): array
    {
        $out = ['template_id' => Input::id($this->validated('template_id'))];
        if ($this->has('enabled')) {
            $out['enabled'] = $this->boolean('enabled');
        }
        if ($this->has('lead_days')) {
            $out['lead_days'] = $this->filled('lead_days') ? Input::int($this->validated('lead_days')) : null;
        }

        return $out;
    }

    /**
     * A membership tier.
     *
     * @return array{name?: string, discount_pct?: string, priority_booking?: bool, free_addon_ids?: list<string>, perks?: string|null, is_active?: bool, position?: int}
     */
    public function tier(): array
    {
        $out = [];
        if ($this->has('name')) {
            $out['name'] = Input::string($this->validated('name'));
        }
        if ($this->has('discount_pct')) {
            $out['discount_pct'] = Input::string($this->validated('discount_pct'));
        }
        if ($this->has('priority_booking')) {
            $out['priority_booking'] = $this->boolean('priority_booking');
        }
        if ($this->has('free_addon_ids')) {
            $out['free_addon_ids'] = self::strings($this->validated('free_addon_ids'));
        }
        if ($this->has('perks')) {
            $out['perks'] = Input::text($this->validated('perks'));
        }
        if ($this->has('is_active')) {
            $out['is_active'] = $this->boolean('is_active');
        }
        if ($this->has('position')) {
            $out['position'] = Input::int($this->validated('position'));
        }

        return $out;
    }

    /**
     * Putting a customer on a tier.
     *
     * @return array{tier_id: string, started_on?: string|null, expires_on?: string|null, notes?: string|null}
     */
    public function enrolment(): array
    {
        $out = ['tier_id' => Input::id($this->validated('tier_id'))];
        if ($this->has('started_on')) {
            $out['started_on'] = Input::text($this->validated('started_on'));
        }
        if ($this->has('expires_on')) {
            $out['expires_on'] = Input::text($this->validated('expires_on'));
        }
        if ($this->has('notes')) {
            $out['notes'] = Input::text($this->validated('notes'));
        }

        return $out;
    }

    /**
     * A prepaid plan.
     *
     * @return array{name?: string, price_cents?: int, credits?: int, detailing_package_id?: string|null, valid_days?: int|null, is_active?: bool}
     */
    public function plan(): array
    {
        $out = [];
        if ($this->has('name')) {
            $out['name'] = Input::string($this->validated('name'));
        }
        if ($this->has('price_cents')) {
            $out['price_cents'] = Input::int($this->validated('price_cents'));
        }
        if ($this->has('credits')) {
            $out['credits'] = Input::int($this->validated('credits'));
        }
        if ($this->has('detailing_package_id')) {
            $out['detailing_package_id'] = $this->filled('detailing_package_id') ? Input::id($this->validated('detailing_package_id')) : null;
        }
        if ($this->has('valid_days')) {
            $out['valid_days'] = $this->filled('valid_days') ? Input::int($this->validated('valid_days')) : null;
        }
        if ($this->has('is_active')) {
            $out['is_active'] = $this->boolean('is_active');
        }

        return $out;
    }

    /**
     * Selling a prepaid plan.
     *
     * @return array{plan_id: string, payment_method: string, payment_reference?: string|null}
     */
    public function sale(): array
    {
        $out = ['plan_id' => Input::id($this->validated('plan_id')), 'payment_method' => Input::string($this->validated('payment_method'))];
        if ($this->has('payment_reference')) {
            $out['payment_reference'] = Input::text($this->validated('payment_reference'));
        }

        return $out;
    }

    /**
     * A segment.
     *
     * @return array{name?: string, description?: string|null, filters?: array<array-key, mixed>}
     */
    public function segmentData(): array
    {
        $out = [];
        if ($this->has('name')) {
            $out['name'] = Input::string($this->validated('name'));
        }
        if ($this->has('description')) {
            $out['description'] = Input::text($this->validated('description'));
        }
        if ($this->has('filters')) {
            $out['filters'] = is_array($raw = $this->validated('filters')) ? $raw : [];
        }

        return $out;
    }

    /**
     * A campaign.
     *
     * @return array{name?: string, segment_id?: string, template_id?: string, scheduled_at?: string|null, hourly_limit?: int|null}
     */
    public function campaign(): array
    {
        $out = [];
        if ($this->has('name')) {
            $out['name'] = Input::string($this->validated('name'));
        }
        if ($this->has('segment_id')) {
            $out['segment_id'] = Input::id($this->validated('segment_id'));
        }
        if ($this->has('template_id')) {
            $out['template_id'] = Input::id($this->validated('template_id'));
        }
        if ($this->has('scheduled_at')) {
            $out['scheduled_at'] = Input::text($this->validated('scheduled_at'));
        }
        if ($this->has('hourly_limit')) {
            $out['hourly_limit'] = $this->filled('hourly_limit') ? Input::int($this->validated('hourly_limit')) : null;
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        $out = [];
        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = strtolower($item);
            }
        }

        return $out;
    }
}
