<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Bay;
use App\Models\Contact;
use App\Models\JournalEntry;
use App\Models\MeterReading;
use App\Models\Technician;
use App\Models\Vendor;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * docs/frontend-parity.md is the contract for Phase 5: this reads its tables
 * and calls every endpoint they name, as a provider admin and as a fleet
 * manager. Every endpoint must exist; a GET answers 2xx to a caller on its
 * row's side and 403/404 to the other side; nothing answers 5xx. Writes run
 * inside a savepoint that is rolled back, so each sees the same seed.
 *
 * Placeholders are filled with seeded Actimed records both callers reach
 * (staff-only resources with the repair branch's).
 */

const PARITY_DOC = __DIR__.'/../../../docs/frontend-parity.md';

/**
 * Every endpoint named in the parity tables, with the sides its rows give it.
 *
 * @return array<string, array{method: string, path: string, sides: list<string>}>
 */
function parityEndpoints(): array
{
    $endpoints = [];
    foreach (file(PARITY_DOC, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $cells = array_map('trim', explode('|', $line));
        if (count($cells) < 7 || ! in_array($cells[3], ['staff', 'portal', 'both'], true)) {
            continue;
        }
        preg_match_all('/`(GET|POST|PUT|PATCH|DELETE) (\/[^`\s]*)`/', $cells[2], $matches, PREG_SET_ORDER);
        foreach ($matches as [, $method, $path]) {
            $key = "{$method} {$path}";
            $endpoints[$key] ??= ['method' => $method, 'path' => $path, 'sides' => []];
            $endpoints[$key]['sides'][] = $cells[3];
        }
    }

    return $endpoints;
}

/**
 * @return array<string, string>
 */
function parityPlaceholders(World $world, string $document): array
{
    return asSystem(fn (): array => [
        'vehicle' => $world->id('veh-001'),
        'work_order' => $world->id('wo-0079'),
        'customer_account' => $world->id('fc-actimed'),
        'document' => $document,
        'purchase_order' => $world->id('po-seed-0002'),
        'item' => $world->id('item:90915-YZZD4'),
        'shop_purchase_order' => $world->id('shop-po:top-up'),
        'goods_receipt' => $world->id('goods-receipt:top-up'),
        'stock_count' => $world->id('stock-count:cycle'),
        'stock_transfer' => $world->id('stock-transfer:cloths'),
        'fleet_part' => $world->id('part:fc-actimed:p-oil-filter'),
        'service_task' => $world->id('task:oil-filter'),
        'user' => $world->id('ops@mekanikomore.ph'),
        'invitation' => $world->id('invite:portal'),
        'branch' => $world->id('mekanikomor-binan'),
        'vendor' => (string) Vendor::query()->where('organization_id', $world->id('prov-mekanikomore'))->orderBy('id')->value('id'),
        'technician' => (string) Technician::query()->where('branch_id', $world->id('mekanikomor-binan'))->orderBy('id')->value('id'),
        'bay' => (string) Bay::query()->where('branch_id', $world->id('mekanikomor-binan'))->orderBy('id')->value('id'),
        'contact' => (string) Contact::query()->where('customer_account_id', $world->id('fc-actimed'))->orderBy('id')->value('id'),
        'reading' => (string) MeterReading::query()->where('vehicle_id', $world->id('veh-001'))->orderBy('id')->value('id'),
        'invoice' => $world->id('invoice:actimed-overdue'),
        'payment' => $world->id('payment:actimed-partial'),
        'account' => (string) Account::query()->where('organization_id', $world->id('prov-mekanikomore'))->where('code', '1100')->value('id'),
        'journal_entry' => (string) JournalEntry::query()->where('organization_id', $world->id('prov-mekanikomore'))->orderBy('id')->value('id'),
    ]);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-08T10:00:00+08:00'));
    Storage::fake('documents');
    $this->world = World::build();

    // The seeded documents are metadata only; download needs a stored file.
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $this->document = $this->post('/api/v1/documents', [
        'vehicle_id' => $this->world->id('veh-001'),
        'kind' => 'invoice',
        'file' => UploadedFile::fake()->create('invoice.pdf', 40, 'application/pdf'),
    ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');
});

it('names real endpoints, every one of them done', function () {
    $endpoints = parityEndpoints();
    expect(count($endpoints))->toBeGreaterThan(100);

    $placeholders = parityPlaceholders($this->world, $this->document);
    foreach ($endpoints as $key => $endpoint) {
        $uri = '/api/v1'.strtr($endpoint['path'], array_combine(array_map(fn (string $k): string => '{'.$k.'}', array_keys($placeholders)), $placeholders));
        expect(fn () => Route::getRoutes()->match(Request::create($uri, $endpoint['method'])))->not->toThrow(Throwable::class, $key);
    }

    $statuses = [];
    foreach (file(PARITY_DOC, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $cells = array_map('trim', explode('|', $line));
        if (count($cells) >= 7 && in_array($cells[3], ['staff', 'portal', 'both'], true)) {
            $statuses[] = $cells[4];
        }
    }
    expect(array_values(array_unique($statuses)))->toBe(['done']);
});

it('serves every parity endpoint to the right side, and never fails', function (string $email, string $side) {
    $this->withoutMiddleware(ThrottleRequests::class);
    $placeholders = parityPlaceholders($this->world, $this->document);
    $user = $this->world->user($email);
    $failures = [];

    foreach (parityEndpoints() as $key => $endpoint) {
        $uri = '/api/v1'.strtr($endpoint['path'], array_combine(array_map(fn (string $k): string => '{'.$k.'}', array_keys($placeholders)), $placeholders));
        Sanctum::actingAs($user);

        if ($endpoint['method'] === 'GET') {
            $status = $this->getJson($uri)->getStatusCode();
            $ours = in_array('both', $endpoint['sides'], true) || in_array($side, $endpoint['sides'], true);
            $ok = $ours ? $status >= 200 && $status < 300 : in_array($status, [403, 404], true);
        } else {
            DB::beginTransaction();
            try {
                $status = $this->json($endpoint['method'], $uri)->getStatusCode();
            } finally {
                DB::rollBack();
            }
            $ok = $status < 500 && $status !== 405;
        }

        if (! $ok) {
            $failures[] = "{$key} → {$status}";
        }
    }

    expect($failures)->toBe([]);
})->with([
    'provider admin' => ['owner@mekanikomore.ph', 'staff'],
    'fleet manager' => ['donmiguel@mekanikomor.ph', 'portal'],
]);
