<?php

declare(strict_types=1);

namespace App\Domain\Tax;

/**
 * BIR alphanumeric tax codes (ATC) for expanded (creditable) withholding tax,
 * as a bill, a payment and a 2307 name them. The API accepts any well-formed
 * code with the rate the user gives it: BIR adds and re-rates codes, and the
 * right one depends on the payee (individual or corporate, their gross
 * income, whether the payor is a top withholding agent). `common()` is a
 * pick-list of the ones a shop meets, nothing more.
 *
 * TODO: confirm this list and its rates with the accountant before go-live.
 */
final class Atc
{
    /** `WI` an individual payee, `WC` a corporate one, then three digits. */
    public const string PATTERN = '/^W[CI]\d{3}$/';

    public static function isValid(string $code): bool
    {
        return preg_match(self::PATTERN, $code) === 1;
    }

    /**
     * @return list<array{code: string, rate_pct: string, description: string}>
     */
    public static function common(): array
    {
        return [
            ['code' => 'WC158', 'rate_pct' => '1', 'description' => 'Purchase of goods by a top withholding agent (corporate supplier)'],
            ['code' => 'WI158', 'rate_pct' => '1', 'description' => 'Purchase of goods by a top withholding agent (individual supplier)'],
            ['code' => 'WC160', 'rate_pct' => '2', 'description' => 'Purchase of services by a top withholding agent (corporate supplier)'],
            ['code' => 'WI160', 'rate_pct' => '2', 'description' => 'Purchase of services by a top withholding agent (individual supplier)'],
            ['code' => 'WC100', 'rate_pct' => '5', 'description' => 'Rentals (corporate lessor)'],
            ['code' => 'WI100', 'rate_pct' => '5', 'description' => 'Rentals (individual lessor)'],
            ['code' => 'WC010', 'rate_pct' => '10', 'description' => 'Professional fees (corporate, gross income up to P720,000)'],
            ['code' => 'WC011', 'rate_pct' => '15', 'description' => 'Professional fees (corporate, gross income above P720,000)'],
            ['code' => 'WI010', 'rate_pct' => '5', 'description' => 'Professional fees (individual, gross income up to P3,000,000)'],
            ['code' => 'WI011', 'rate_pct' => '10', 'description' => 'Professional fees (individual, gross income above P3,000,000)'],
            ['code' => 'WC120', 'rate_pct' => '2', 'description' => 'Contractors and subcontractors (corporate)'],
            ['code' => 'WI120', 'rate_pct' => '2', 'description' => 'Contractors and subcontractors (individual)'],
        ];
    }
}
