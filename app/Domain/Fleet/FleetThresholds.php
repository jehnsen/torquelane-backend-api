<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Maintenance\DueSoonThresholds;
use App\Domain\Maintenance\MeterKind;

/**
 * Every fleet threshold, in one place (../web: lib/interval-status.ts,
 * lib/pms.ts, lib/compliance.ts, lib/alerts.ts, lib/odometer-validation.ts).
 * The engine, the endpoints and GET /fleet/summary's `thresholds` all read
 * them from here; change them here only.
 */
final class FleetThresholds
{
    /** A task enters the warning band this far ahead of its distance limit… */
    public const int DUE_SOON_KM = 750;

    /** …or this many days ahead of its date. */
    public const int DUE_SOON_DAYS = 21;

    /** Past this many days, a reading is old enough that projections should say so. */
    public const int ODOMETER_STALE_DAYS = 14;

    /** Above this multiple of the average daily rate, a reading is probably a typo. */
    public const int ODOMETER_RATE_HIGH_MULTIPLIER = 3;

    /** Below this multiple, a reading is probably an old value re-entered. */
    public const float ODOMETER_RATE_LOW_MULTIPLIER = 0.1;

    /** Dashboard tile window for expiring documents. */
    public const int DASHBOARD_EXPIRY_WINDOW_DAYS = 60;

    /** Vehicle card / detail badge window. */
    public const int BADGE_WARNING_DAYS = 30;

    /** A document inside this window of its renewal date raises an alert. */
    public const int DOCUMENT_EXPIRY_WARNING_DAYS = 45;

    public static function dueSoon(): DueSoonThresholds
    {
        return new DueSoonThresholds([MeterKind::Km->value => self::DUE_SOON_KM], self::DUE_SOON_DAYS);
    }

    /**
     * @return array<string, int|float>
     */
    public static function all(): array
    {
        return [
            'due_soon_km' => self::DUE_SOON_KM,
            'due_soon_days' => self::DUE_SOON_DAYS,
            'odometer_stale_days' => self::ODOMETER_STALE_DAYS,
            'odometer_rate_high_multiplier' => self::ODOMETER_RATE_HIGH_MULTIPLIER,
            'odometer_rate_low_multiplier' => self::ODOMETER_RATE_LOW_MULTIPLIER,
            'dashboard_expiry_window_days' => self::DASHBOARD_EXPIRY_WINDOW_DAYS,
            'badge_warning_days' => self::BADGE_WARNING_DAYS,
            'document_expiry_warning_days' => self::DOCUMENT_EXPIRY_WARNING_DAYS,
        ];
    }
}
