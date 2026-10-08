<?php

declare(strict_types=1);

use App\Domain\Fleet\FleetThresholds;
use App\Models\Document;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * The vehicle and document screens' filters, applied on the server: PMS
 * band, staleness and health order are derived per request; expiry status is
 * the same rule as the compliance badge.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    $this->world = World::build();
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
});

it('filters vehicles by their derived PMS band and lists the least healthy first', function () {
    $summary = $this->getJson('/api/v1/fleet/summary')->json('data');

    $overdue = $this->getJson('/api/v1/vehicles?pms=overdue&sort=health&per_page=100')->assertOk();
    $rows = $overdue->json('data');
    expect($overdue->json('meta.total'))->toBe($summary['overdue'])
        ->and(array_unique(array_column(array_column($rows, 'pms'), 'status')))->toBe(['overdue']);
    $scores = array_column(array_column($rows, 'pms'), 'health_score');
    $sorted = $scores;
    sort($sorted);
    expect($scores)->toBe($sorted);

    $this->getJson('/api/v1/vehicles?pms=due_soon&per_page=100')->assertJsonPath('meta.total', $summary['due_soon']);
    $this->getJson('/api/v1/vehicles?pms=ok&per_page=1&page=2')->assertJsonPath('meta.total', $summary['compliant'])->assertJsonPath('meta.page', 2);

    $stale = $this->getJson('/api/v1/vehicles?pms=stale&per_page=100')->json('data');
    expect(array_unique(array_column(array_column($stale, 'odometer'), 'stale')))->toBe($stale === [] ? [] : [true]);
});

it('searches vehicles by make, model, driver or location', function () {
    $rows = $this->getJson('/api/v1/vehicles?search=hiace&per_page=100')->assertOk()->json('data');

    expect($rows)->not->toBeEmpty();
    foreach ($rows as $row) {
        expect(mb_strtolower($row['plate_number'].' '.$row['make'].' '.$row['model'].' '.$row['assigned_to'].' '.$row['location']))->toContain('hiace');
    }
});

it('filters documents by expiry status and text, and totals them', function () {
    $today = '2026-10-08';
    $warning = CarbonImmutable::parse($today)->addDays(FleetThresholds::BADGE_WARNING_DAYS)->toDateString();

    $expired = $this->getJson('/api/v1/documents?status=expired&sort=expiry&per_page=100')->assertOk()->json('data');
    expect($expired)->not->toBeEmpty();
    foreach ($expired as $document) {
        expect($document['expires_on'] < $today)->toBeTrue();
    }
    $dates = array_column($expired, 'expires_on');
    $sorted = $dates;
    sort($sorted);
    expect($dates)->toBe($sorted);

    foreach ($this->getJson('/api/v1/documents?status=expiring&per_page=100')->json('data') as $document) {
        expect($document['expires_on'] >= $today && $document['expires_on'] <= $warning)->toBeTrue();
    }

    $summary = $this->getJson('/api/v1/documents/summary')->assertOk()->json('data');
    $all = asSystem(fn () => Document::query()->where('organization_id', $this->world->id('prov-mekanikomore'))->get());
    $soon = CarbonImmutable::parse($today)->addDays(FleetThresholds::DOCUMENT_EXPIRY_WARNING_DAYS)->toDateString();
    expect($summary)->toBe([
        'count' => $all->count(),
        'total_bytes' => $all->sum('size_bytes'),
        'expiring_soon' => $all->filter(fn (Document $d): bool => $d->expires_on !== null && $d->expires_on->toDateString() <= $soon)->count(),
        'expiring_window_days' => FleetThresholds::DOCUMENT_EXPIRY_WARNING_DAYS,
    ]);

    $plate = $this->getJson('/api/v1/documents?q=npq%202214&per_page=100')->json('data');
    expect($plate)->not->toBeEmpty();
});
