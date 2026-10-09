<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Approvals\ApprovalBands;
use App\Domain\WorkOrders\PartsSource;
use Illuminate\Validation\Rule;

/**
 * The account's sparse approval overrides: a key sets that band, null goes
 * back to inheriting, an absent key is unchanged. Money in centavos.
 */
final class SaveAccountApprovalSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'auto_approve_under_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'ops_approval_under_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sla_hours' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:720'],
            'variance_threshold_pct' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100'],
            'default_parts_source' => ['sometimes', 'nullable', 'string', Rule::enum(PartsSource::class)->except(PartsSource::ShopStock)],
            'monthly_budget_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function changes(): array
    {
        $changes = [];
        foreach (ApprovalBands::OVERRIDE_KEYS as $key) {
            if ($this->has($key)) {
                $value = $this->input($key);
                $changes[$key] = match (true) {
                    $value === null => null,
                    $key === 'default_parts_source' => is_string($value) ? $value : null,
                    default => is_numeric($value) ? intval($value) : null,
                };
            }
        }

        return $changes;
    }
}
