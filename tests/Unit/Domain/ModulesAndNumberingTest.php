<?php

declare(strict_types=1);

use App\Domain\Modules\Module;
use App\Domain\Modules\ModuleEntitlements;
use App\Domain\Numbering\DocumentNumberFormat;

it('activates a module for a branch only when both switches are on', function () {
    $organization = [Module::RepairPms, Module::Detailing];

    expect(ModuleEntitlements::activeForBranch($organization, [Module::Detailing, Module::Pos]))->toBe([Module::Detailing])
        ->and(ModuleEntitlements::activeForBranch([], [Module::Detailing]))->toBe([])
        ->and(ModuleEntitlements::activeForBranch($organization, []))->toBe([]);
});

it('reports a module active across branches when it is active in any of them', function () {
    $organization = [Module::RepairPms, Module::Detailing, Module::Equipment];

    expect(ModuleEntitlements::activeForAny($organization, [
        'repair' => [Module::RepairPms],
        'detailing' => [Module::Detailing, Module::Equipment, Module::Pos],
    ]))->toBe([Module::RepairPms, Module::Detailing, Module::Equipment]);
});

it('keys a series period on the Manila calendar year', function () {
    // 1 Jan 07:30 in Manila is still 31 Dec in UTC.
    expect(DocumentNumberFormat::periodKey(new DateTimeImmutable('2026-12-31T23:30:00Z')))->toBe('2027')
        ->and(DocumentNumberFormat::periodKey(new DateTimeImmutable('2026-12-31T15:59:59Z')))->toBe('2026');
});

it('formats a number with its prefix, period and padding', function () {
    expect(DocumentNumberFormat::format('WO', '2026', 1))->toBe('WO-2026-0001')
        ->and(DocumentNumberFormat::format('INV', '2026', 42, 6))->toBe('INV-2026-000042')
        ->and(DocumentNumberFormat::format('WO', '2026', 12345))->toBe('WO-2026-12345');
});

it('refuses a number below one', function () {
    DocumentNumberFormat::format('WO', '2026', 0);
})->throws(InvalidArgumentException::class);
