<?php

declare(strict_types=1);

namespace App\Domain\Approvals;

use App\Domain\WorkOrders\PartsSource;
use InvalidArgumentException;

/**
 * The effective approval and billing settings for one order, in centavos.
 * Folded from three levels, each sparse over the one before:
 *
 *   organization defaults → branch override → customer account override
 *
 * An unset field inherits; it is never read as zero. A 0% VAT rate is a real
 * setting (and what a branch that is not VAT-registered bills), not a
 * missing one. Bands run on the pre-tax subtotal: VAT never enters a
 * threshold.
 */
final readonly class ApprovalSettings
{
    /** Every field, snake_case, as stored (approval_settings columns). */
    public const array KEYS = [
        'auto_approve_under_cents',
        'ops_approval_under_cents',
        'sla_hours',
        'variance_threshold_pct',
        'default_parts_source',
        'monthly_budget_cents',
        'vat_rate_pct',
        'misc_fee_flat_cents',
        'default_labour_rate_cents',
    ];

    public function __construct(
        public int $autoApproveUnderCents,
        public int $opsApprovalUnderCents,
        public int $slaHours,
        /** Decimal string. */
        public string $varianceThresholdPct,
        public PartsSource $defaultPartsSource,
        public int $monthlyBudgetCents,
        /** Decimal string. */
        public string $vatRatePct,
        public int $miscFeeFlatCents,
        public int $defaultLabourRateCents,
    ) {}

    /** ../web DEFAULT_APPROVAL_SETTINGS. */
    public static function defaults(): self
    {
        return new self(500_000, 5_000_000, 4, '15', PartsSource::SupplierProvided, 15_000_000, '12', 0, 65_000);
    }

    /**
     * @param  array<string, mixed>  $values  every KEY present
     */
    public static function fromArray(array $values): self
    {
        foreach (self::KEYS as $key) {
            if (! array_key_exists($key, $values) || $values[$key] === null) {
                throw new InvalidArgumentException("Approval settings are missing [{$key}].");
            }
        }

        return new self(
            self::int($values['auto_approve_under_cents']),
            self::int($values['ops_approval_under_cents']),
            self::int($values['sla_hours']),
            self::decimal($values['variance_threshold_pct']),
            $values['default_parts_source'] instanceof PartsSource ? $values['default_parts_source'] : PartsSource::from(self::string($values['default_parts_source'])),
            self::int($values['monthly_budget_cents']),
            self::decimal($values['vat_rate_pct']),
            self::int($values['misc_fee_flat_cents']),
            self::int($values['default_labour_rate_cents']),
        );
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'auto_approve_under_cents' => $this->autoApproveUnderCents,
            'ops_approval_under_cents' => $this->opsApprovalUnderCents,
            'sla_hours' => $this->slaHours,
            'variance_threshold_pct' => $this->varianceThresholdPct,
            'default_parts_source' => $this->defaultPartsSource->value,
            'monthly_budget_cents' => $this->monthlyBudgetCents,
            'vat_rate_pct' => $this->vatRatePct,
            'misc_fee_flat_cents' => $this->miscFeeFlatCents,
            'default_labour_rate_cents' => $this->defaultLabourRateCents,
        ];
    }

    /**
     * A sparse override: a key present and non-null wins; anything else
     * inherits. Unknown keys are refused so a typo cannot silently inherit.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    public function overriddenBy(?array $overrides): self
    {
        if ($overrides === null) {
            return $this;
        }
        $unknown = array_diff(array_keys($overrides), self::KEYS);
        if ($unknown !== []) {
            throw new InvalidArgumentException('Unknown approval setting(s): '.implode(', ', $unknown).'.');
        }

        return self::fromArray(array_merge($this->toArray(), array_filter($overrides, fn (mixed $v): bool => $v !== null)));
    }

    /** A branch that is not VAT-registered bills 0%. */
    public function forVatRegistration(bool $isVatRegistered): self
    {
        return $isVatRegistered ? $this : $this->overriddenBy(['vat_rate_pct' => '0']);
    }

    /**
     * organization → branch → account, then the branch's VAT registration.
     *
     * @param  array<string, mixed>|null  $branch  sparse
     * @param  array<string, mixed>|null  $account  sparse (ApprovalBands::OVERRIDE_KEYS only)
     */
    public static function effective(self $organization, ?array $branch, ?array $account, bool $isVatRegistered = true): self
    {
        if ($account !== null) {
            $account = array_intersect_key($account, array_flip(ApprovalBands::OVERRIDE_KEYS));
        }

        return $organization->overriddenBy($branch)->overriddenBy($account)->forVatRegistration($isVatRegistered);
    }

    private static function int(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new InvalidArgumentException('Expected a whole number.');
    }

    private static function decimal(mixed $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^\d+(\.\d{1,3})?$/', $value) === 1) {
            // Normalise "12.000" → "12" so equal rates compare equal.
            return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
        }

        throw new InvalidArgumentException('Expected a non-negative decimal with at most 3 places.');
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException('Expected a string.');
    }
}
