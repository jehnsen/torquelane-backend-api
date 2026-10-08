<?php

declare(strict_types=1);

use App\Domain\Shared\BusinessCalendar;

it('derives the Manila business date from a UTC instant', function (string $utc, string $businessDate) {
    expect(BusinessCalendar::dateOf(new DateTimeImmutable($utc)))->toBe($businessDate);
})->with([
    'Manila morning is the previous UTC day' => ['2026-10-08T23:30:00Z', '2026-10-09'],
    'last second of the Manila day' => ['2026-10-08T15:59:59Z', '2026-10-08'],
    'Manila midnight' => ['2026-10-08T16:00:00Z', '2026-10-09'],
    'year end' => ['2026-12-31T16:00:00Z', '2027-01-01'],
]);

it('ignores the timezone the instant happens to carry', function () {
    $sameInstant = new DateTimeImmutable('2026-10-08T19:30:00-04:00'); // 23:30Z

    expect(BusinessCalendar::dateOf($sameInstant))->toBe('2026-10-09');
});

it('finds the UTC instant a business day starts at', function () {
    $start = BusinessCalendar::startOfDayUtc('2026-10-09');

    expect($start->format(DATE_ATOM))->toBe('2026-10-08T16:00:00+00:00')
        ->and(BusinessCalendar::dateOf($start))->toBe('2026-10-09');
});

it('rejects dates that do not exist', function (string $date) {
    BusinessCalendar::startOfDayUtc($date);
})->with(['2026-02-30', '2026-13-01', '09/10/2026', ''])->throws(InvalidArgumentException::class);
