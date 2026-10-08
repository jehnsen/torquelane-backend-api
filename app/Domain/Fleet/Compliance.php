<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Documents\DocumentFacts;
use App\Domain\Shared\Calendar;
use DateTimeImmutable;

/**
 * Port of ../web/lib/compliance.ts. Golden-tested against compliance.json.
 * Statuses: `expired`, `expiring`, `ok`.
 */
final class Compliance
{
    private const array MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    /**
     * Worst state across a vehicle's roadworthiness documents and its driver's
     * licence. One expired anything reads `expired`; compliance does not average.
     *
     * @param  list<DocumentFacts>  $documents
     */
    public static function vehicleStatus(string $vehicleId, ?string $driverLicenceExpiry, array $documents, DateTimeImmutable $today): string
    {
        $dates = [];
        foreach ($documents as $document) {
            if ($document->vehicleId === $vehicleId && $document->expiresOn !== null && $document->kind->isCompliance()) {
                $dates[] = $document->expiresOn;
            }
        }
        if ($driverLicenceExpiry !== null && $driverLicenceExpiry !== '') {
            $dates[] = $driverLicenceExpiry;
        }
        if ($dates === []) {
            return 'ok';
        }

        $min = min(array_map(fn (string $date): int => Calendar::differenceInCalendarDays(Calendar::parseDate($date), $today), $dates));

        return self::band($min);
    }

    public static function documentStatus(?string $expiresOn, DateTimeImmutable $today): string
    {
        return $expiresOn === null || $expiresOn === ''
            ? 'ok'
            : self::band(Calendar::differenceInCalendarDays(Calendar::parseDate($expiresOn), $today));
    }

    /**
     * Compliance documents and driver licences expiring inside a window
     * (already-expired ones included), counted per kind label, largest first.
     *
     * @param  list<string|null>  $driverLicenceExpiries  one per vehicle
     * @param  list<DocumentFacts>  $documents
     * @return array{total: int, byKind: list<array{label: string, count: int}>}
     */
    public static function expiringSummary(array $driverLicenceExpiries, array $documents, int $windowDays, DateTimeImmutable $today): array
    {
        $counts = [];
        foreach ($documents as $document) {
            if ($document->expiresOn === null || ! $document->kind->isCompliance()) {
                continue;
            }
            if (Calendar::differenceInCalendarDays(Calendar::parseDate($document->expiresOn), $today) > $windowDays) {
                continue;
            }
            $counts[$document->kind->value] = ($counts[$document->kind->value] ?? ['label' => $document->kind->label(), 'count' => 0]);
            $counts[$document->kind->value]['count']++;
        }

        $licences = 0;
        foreach ($driverLicenceExpiries as $expiry) {
            if ($expiry === null || $expiry === '') {
                continue;
            }
            if (Calendar::differenceInCalendarDays(Calendar::parseDate($expiry), $today) > $windowDays) {
                continue;
            }
            $licences++;
        }

        $byKind = array_values($counts);
        if ($licences > 0) {
            $byKind[] = ['label' => 'Driver licence', 'count' => $licences];
        }

        // Stable, like Array.prototype.sort: equal counts keep first-seen order.
        usort($byKind, fn (array $a, array $b): int => $b['count'] - $a['count']);

        return ['total' => array_sum(array_column($byKind, 'count')), 'byKind' => $byKind];
    }

    /**
     * PH LTO renewal month by the plate's last digit: 1–9 → January–September,
     * 0 → October. Null when the plate has no digit.
     */
    public static function plateEndingRenewalMonth(string $plateNumber): ?string
    {
        if (preg_match_all('/\d/', $plateNumber, $digits) === 0) {
            return null;
        }

        $last = (int) end($digits[0]);

        return self::MONTH_NAMES[$last === 0 ? 9 : max(0, min(8, $last - 1))];
    }

    private static function band(int $days): string
    {
        if ($days < 0) {
            return 'expired';
        }

        return $days <= FleetThresholds::BADGE_WARNING_DAYS ? 'expiring' : 'ok';
    }
}
