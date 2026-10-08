<?php

declare(strict_types=1);

use App\Models\User;
use App\Tenancy\TenantManager;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\Golden\Support\WebFixtures;

/*
 * The tenancy fixture's demo-account sweeps, replayed end to end: DemoSeeder
 * builds the tenant from the same demo-seed.json, the fixture's provider and
 * fleet-client ids are mapped onto the seeded ULIDs (DemoSeeder::$ids), and
 * each demo user signs in and asks the real API.
 *
 *   resolveTenantScope    → GET /me side, organization, customer account
 *                           (a null scope is a 403: yard@bayanicon.ph, whose
 *                           account is suspended, gets account_suspended)
 *   visibleFleetClientIds → GET /customer-accounts ids
 *   providerBranding      → GET /me branding
 *
 * The suspended-account rule change alters none of these: the frontend also
 * kept suspended clients visible to the provider side (Bayani is in the
 * staff lists below) and denied the client side.
 */

dataset('demo sweeps', function (): array {
    $cases = [];
    foreach (WebFixtures::cases('tenancy') as $case) {
        if (preg_match('/^sweep › (resolveTenantScope|visibleFleetClientIds|providerBranding) › demo (\S+) \(/', $case['case'], $m) === 1) {
            $cases[$case['case']] = [$m[1], $m[2], $case['output'], $case['input']];
        }
    }

    return $cases;
});

beforeEach(function () {
    $seeder = new DemoSeeder;
    $seeder->run();
    $this->ids = $seeder->ids;
});

it('serves each demo account the scope, accounts and branding the frontend computed', function (string $fn, string $email, mixed $expected, array $input) {
    $user = app(TenantManager::class)->system('golden lookup', fn (): User => User::query()->where('email', $email)->firstOrFail());
    Sanctum::actingAs($user);
    $map = fn (string $id): string => $this->ids[$id];

    if ($fn === 'resolveTenantScope') {
        $me = $this->getJson('/api/v1/me');

        if ($expected === null) {
            $me->assertStatus(403)->assertJsonPath('error.code', 'account_suspended');

            return;
        }

        $me->assertOk()
            ->assertJsonPath('data.side', $expected['kind'] === 'provider' ? 'staff' : 'portal')
            ->assertJsonPath('data.organization.id', $map($expected['providerId']))
            ->assertJsonPath('data.customer_account.id', isset($expected['fleetClientId']) ? $map($expected['fleetClientId']) : null);

        return;
    }

    if ($fn === 'visibleFleetClientIds') {
        $response = $this->getJson('/api/v1/customer-accounts?per_page=100');

        if ($expected === []) {
            $response->assertStatus(403);

            return;
        }

        $ids = array_column($response->assertOk()->json('data'), 'id');
        $want = array_map($map, $expected);
        sort($ids);
        sort($want);

        expect($ids)->toBe($want);

        return;
    }

    // The frontend fell back to platform branding for a null scope; the API
    // refuses a session with no scope outright.
    if ($input[1] === null) {
        $this->getJson('/api/v1/me')->assertStatus(403);

        return;
    }

    $branding = $this->getJson('/api/v1/me')->assertOk()->json('data.branding');
    expect([
        'displayName' => $branding['display_name'],
        'logoUrl' => $branding['logo_url'],
        'brandColor' => $branding['brand_color'],
        'supportEmail' => $branding['support_email'],
    ])->toBe($expected);
})->with('demo sweeps');
