<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Approvals\ApprovalSettings;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The organization's approval defaults (branch_id null, every field set) or
 * a branch's sparse override (null fields inherit).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property int|null $auto_approve_under_cents
 * @property int|null $ops_approval_under_cents
 * @property int|null $sla_hours
 * @property BigDecimal|null $variance_threshold_pct
 * @property string|null $default_parts_source
 * @property int|null $monthly_budget_cents
 * @property BigDecimal|null $vat_rate_pct
 * @property int|null $misc_fee_flat_cents
 * @property int|null $default_labour_rate_cents
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ApprovalSetting extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'auto_approve_under_cents' => 'integer',
            'ops_approval_under_cents' => 'integer',
            'sla_hours' => 'integer',
            'variance_threshold_pct' => DecimalCast::class,
            'monthly_budget_cents' => 'integer',
            'vat_rate_pct' => DecimalCast::class,
            'misc_fee_flat_cents' => 'integer',
            'default_labour_rate_cents' => 'integer',
        ];
    }

    /**
     * The row's set fields, as ApprovalSettings keys (sparse for a branch row).
     *
     * @return array<string, int|string>
     */
    public function values(): array
    {
        $values = [];
        foreach (ApprovalSettings::KEYS as $key) {
            $value = $this->getAttribute($key);
            if ($value === null) {
                continue;
            }
            $values[$key] = match (true) {
                $value instanceof BigDecimal => (string) $value->strippedOfTrailingZeros(),
                is_int($value), is_string($value) => $value,
                default => throw new \LogicException("Approval setting [{$key}] has an unexpected type."),
            };
        }

        return $values;
    }
}
