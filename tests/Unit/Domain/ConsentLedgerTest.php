<?php

declare(strict_types=1);

use App\Domain\Crm\ConsentDecision;
use App\Domain\Crm\ConsentLedger;
use App\Domain\Crm\ConsentPurpose;

function decision(string $id, ConsentPurpose $purpose, bool $granted, string $at): ConsentDecision
{
    return new ConsentDecision($id, $purpose, $granted, new DateTimeImmutable($at));
}

it('reports null for every purpose never decided', function () {
    expect(ConsentLedger::current([]))->toBe([
        'service_records' => null,
        'service_reminders' => null,
        'marketing' => null,
        'vehicle_history_sharing' => null,
    ]);
});

it('takes the latest decision per purpose by capture time, not by row order', function () {
    $granted = decision('01B', ConsentPurpose::Marketing, true, '2026-01-01T00:00:00Z');
    $withdrawn = decision('01A', ConsentPurpose::Marketing, false, '2026-06-01T00:00:00Z');

    // Recorded later (higher id) but captured earlier (backdated paper form): still not current.
    $current = ConsentLedger::current([$withdrawn, $granted]);

    expect($current['marketing'])->toBe($withdrawn)
        ->and(ConsentLedger::isGranted([$withdrawn, $granted], ConsentPurpose::Marketing))->toBeFalse();
});

it('breaks a tie on capture time by insertion order (ULID)', function () {
    $first = decision('01A', ConsentPurpose::ServiceReminders, true, '2026-01-01T00:00:00Z');
    $second = decision('01B', ConsentPurpose::ServiceReminders, false, '2026-01-01T00:00:00Z');

    expect(ConsentLedger::current([$second, $first])['service_reminders'])->toBe($second)
        ->and(ConsentLedger::current([$first, $second])['service_reminders'])->toBe($second);
});

it('keeps purposes independent', function () {
    $records = decision('01A', ConsentPurpose::ServiceRecords, true, '2026-01-01T00:00:00Z');
    $marketing = decision('01B', ConsentPurpose::Marketing, false, '2026-02-01T00:00:00Z');

    $current = ConsentLedger::current([$records, $marketing]);

    expect($current['service_records'])->toBe($records)
        ->and($current['marketing'])->toBe($marketing)
        ->and($current['vehicle_history_sharing'])->toBeNull();
});

it('lets a re-grant after a withdrawal win', function () {
    $ledger = [
        decision('01A', ConsentPurpose::Marketing, true, '2026-01-01T00:00:00Z'),
        decision('01B', ConsentPurpose::Marketing, false, '2026-02-01T00:00:00Z'),
        decision('01C', ConsentPurpose::Marketing, true, '2026-03-01T00:00:00Z'),
    ];

    expect(ConsentLedger::isGranted($ledger, ConsentPurpose::Marketing))->toBeTrue();
});

it('requires service_records, granted, to open an account', function () {
    $records = decision('01A', ConsentPurpose::ServiceRecords, true, '2026-01-01T00:00:00Z');
    $refused = decision('01A', ConsentPurpose::ServiceRecords, false, '2026-01-01T00:00:00Z');
    $marketing = decision('01B', ConsentPurpose::Marketing, true, '2026-01-01T00:00:00Z');

    expect(ConsentLedger::satisfiesAccountOpening([$records, $marketing]))->toBeTrue()
        ->and(ConsentLedger::satisfiesAccountOpening([$marketing]))->toBeFalse()
        ->and(ConsentLedger::satisfiesAccountOpening([$refused]))->toBeFalse();
});
