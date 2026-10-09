<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Approvals\ApprovalBands;
use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\WorkOrders\PartsSource;
use App\Models\CustomerAccount;
use Illuminate\Validation\Rule;

/**
 * POST creates (with opening consents), PATCH edits. Status changes have their
 * own endpoints, and the account type never changes. Portal users may edit
 * their own account's contact details and branding, not the shop's commercial
 * or internal fields.
 */
final class SaveCustomerAccountRequest extends ApiRequest
{
    /** Staff-only fields: terms, limits, bands, and the shop's own CRM notes. */
    public const array STAFF_ONLY = ['payment_terms_days', 'credit_limit_cents', 'approval_threshold_overrides', 'tags', 'source', 'notes'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('POST');
        $portal = $this->tenant()?->isPortal() ?? false;

        $rules = [
            'account_type' => $creating ? ['required', 'string', Rule::in([CustomerAccount::COMPANY, CustomerAccount::INDIVIDUAL])] : ['prohibited'],
            'status' => ['prohibited'],
            'display_name' => [$creating ? 'required_if:account_type,company' : 'sometimes', 'string', 'max:255'],
            'registered_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tin' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIN],
            'address' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'first_name' => [$creating ? 'required_if:account_type,individual' : 'sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => [$creating ? 'required_if:account_type,individual' : 'sometimes', 'nullable', 'string', 'max:255'],
            'nickname' => ['sometimes', 'nullable', 'string', 'max:255'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'birthday' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'contact_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'payment_terms_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'credit_limit_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            // Sparse: only the keys named here override the organization's defaults.
            'approval_threshold_overrides' => ['sometimes', 'nullable', 'array:'.implode(',', ApprovalBands::OVERRIDE_KEYS)],
            'approval_threshold_overrides.auto_approve_under_cents' => ['integer', 'min:0'],
            'approval_threshold_overrides.ops_approval_under_cents' => ['integer', 'min:0'],
            'approval_threshold_overrides.sla_hours' => ['integer', 'min:0', 'max:720'],
            'approval_threshold_overrides.variance_threshold_pct' => ['integer', 'min:0', 'max:100'],
            'approval_threshold_overrides.default_parts_source' => ['string', Rule::enum(PartsSource::class)->except(PartsSource::ShopStock)],
            'approval_threshold_overrides.monthly_budget_cents' => ['integer', 'min:0'],
            'tags' => ['sometimes', 'array', 'max:50'],
            'tags.*' => ['string', 'max:64', 'distinct'],
            'source' => ['sometimes', 'nullable', 'string', 'max:64'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            ...self::brandingRules(),
        ];

        if ($creating) {
            $rules += [
                'consents' => ['required', 'array', 'min:1', 'max:'.count(ConsentPurpose::cases())],
                'consents.*.purpose' => ['required', 'string', 'distinct', Rule::enum(ConsentPurpose::class)],
                'consents.*.granted' => ['required', 'boolean'],
                'consents.*.channel' => ['required', 'string', Rule::enum(ConsentChannel::class)],
                'consents.*.evidence' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'consents.*.captured_at' => ['sometimes', 'nullable', 'date', 'before_or_equal:now'],
                // A brand-new account has no contacts yet.
                'consents.*.contact_id' => ['prohibited'],
            ];
        }

        if ($portal) {
            foreach (self::STAFF_ONLY as $field) {
                $rules[$field] = ['prohibited'];
            }
        }

        return $rules;
    }

    /**
     * The account's own columns (everything but the opening consents).
     *
     * @return array<string, mixed>
     */
    public function accountAttributes(): array
    {
        return array_diff_key($this->validated(), ['consents' => true]);
    }

    /**
     * @return list<array{purpose: string, granted: bool, channel: string, evidence?: string|null, captured_at?: string|null}>
     */
    public function openingConsents(): array
    {
        $decisions = [];
        foreach ($this->array('consents') as $decision) {
            if (! is_array($decision)) {
                continue;
            }
            $decisions[] = [
                'purpose' => is_string($decision['purpose'] ?? null) ? $decision['purpose'] : '',
                'granted' => filter_var($decision['granted'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'channel' => is_string($decision['channel'] ?? null) ? $decision['channel'] : '',
                'evidence' => is_string($decision['evidence'] ?? null) ? $decision['evidence'] : null,
                'captured_at' => is_string($decision['captured_at'] ?? null) ? $decision['captured_at'] : null,
            ];
        }

        return $decisions;
    }
}
