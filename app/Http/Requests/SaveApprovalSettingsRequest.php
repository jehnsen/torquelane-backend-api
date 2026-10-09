<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\WorkOrders\PartsSource;
use Illuminate\Validation\Rule;

/**
 * PUT /approval-settings (organization defaults) and
 * PUT /branches/{branch}/approval-settings (a branch's sparse override; null
 * clears a field back to inheriting). Money in centavos.
 */
final class SaveApprovalSettingsRequest extends ApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $nullable = $this->route('branch') !== null ? ['nullable'] : [];
        $money = ['sometimes', ...$nullable, 'integer', 'min:0', 'max:100000000000'];
        $pct = ['sometimes', ...$nullable, 'numeric', 'decimal:0,3', 'min:0'];

        return [
            'auto_approve_under_cents' => $money,
            'ops_approval_under_cents' => $money,
            'sla_hours' => ['sometimes', ...$nullable, 'integer', 'min:0', 'max:720'],
            'variance_threshold_pct' => [...$pct, 'max:1000'],
            'default_parts_source' => ['sometimes', ...$nullable, 'string', Rule::enum(PartsSource::class)->except(PartsSource::ShopStock)],
            'monthly_budget_cents' => $money,
            // 0 is a real rate (not VAT-registered), not a missing one.
            'vat_rate_pct' => [...$pct, 'max:100'],
            'misc_fee_flat_cents' => $money,
            'default_labour_rate_cents' => $money,
        ];
    }

    /**
     * @return array<string, int|string|null>
     */
    public function values(): array
    {
        $out = [];
        foreach ($this->validated() as $key => $value) {
            $out[(string) $key] = $value === null || is_int($value) || is_string($value) ? $value : (is_float($value) ? (string) json_encode($value) : null);
        }

        return $out;
    }
}
