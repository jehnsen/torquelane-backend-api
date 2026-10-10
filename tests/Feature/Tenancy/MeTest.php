<?php

declare(strict_types=1);

use Laravel\Sanctum\Sanctum;
use Tests\Golden\Support\WebFixtures;
use Tests\Support\World;

/*
 * GET /me for every demo account. Capabilities are checked against the
 * frontend's own ROLE_CAPABILITIES (rbac.json) plus the API's documented
 * additions, not against AccessMatrix itself, so this is not circular.
 */

beforeEach(function () {
    $this->world = World::build();
});

/** @return array<string, list<string>> */
function expectedCapabilities(): array
{
    $web = [];
    foreach (WebFixtures::cases('rbac') as $case) {
        if ($case['fn'] === '$constants') {
            $web = $case['output']['ROLE_CAPABILITIES'];
        }
    }

    return [
        'provider_admin' => [...$web['provider_admin'], 'customer:manage', 'organization:manage', 'inventory:view', 'inventory:manage', 'billing:view', 'billing:manage', 'billing:void', 'ledger:view', 'ledger:manage'],
        'service_advisor' => [...$web['service_advisor'], 'customer:manage', 'inventory:view', 'billing:view', 'billing:manage'],
        'provider_technician' => [...$web['provider_technician'], 'inventory:view'],
        'fleet_manager' => [...$web['fleet_manager'], 'customer:manage', 'billing:view'],
        'operations' => $web['operations'],
        'technician' => $web['technician'],
        'purchasing_officer' => [...$web['purchasing_officer'], 'billing:view'],
        'viewer' => [...$web['viewer'], 'billing:view'],
        // Proposed grants for the API-only roles (see CLAUDE.md, "Roles").
        'branch_manager' => ['vehicle:update', 'vehicle:manage', 'workorder:create', 'workorder:update', 'workorder:complete', 'workorder:approve', 'po:issue', 'document:upload', 'document:delete', 'settings:manage', 'access:manage', 'customer:manage', 'inventory:view', 'inventory:manage', 'billing:view', 'billing:manage', 'billing:void', 'ledger:view'],
        'cashier' => ['customer:manage', 'inventory:view', 'billing:view', 'billing:manage'],
    ];
}

it('reports the right identity, side, account and capabilities for every demo account', function (string $email) {
    $user = $this->world->user($email);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/me');

    if ($email === 'yard@bayanicon.ph') {
        $response->assertForbidden()->assertJsonPath('error.code', 'account_suspended');

        return;
    }

    $response->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.role', $user->role->value)
        ->assertJsonPath('data.side', $user->side->value)
        ->assertJsonPath('data.organization.id', $this->world->id('prov-mekanikomore'))
        ->assertJsonPath('data.customer_account.id', $user->customer_account_id)
        ->assertJsonPath('data.branding.support_email', 'support@mekanikomore.ph');

    $capabilities = $response->json('data.capabilities');
    $expected = expectedCapabilities()[$user->role->value];
    sort($capabilities);
    sort($expected);
    expect($capabilities)->toBe($expected);
})->with(fn (): array => World::demoEmails());

it('shows unrestricted staff every branch, working across "all"', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $me = $this->getJson('/api/v1/me')->assertOk();

    expect(array_column($me->json('data.branches.allowed'), 'slug'))->toBe(['mekanikomor-binan', 'samahuzai-binan'])
        ->and($me->json('data.branches.selected'))->toBe('all')
        ->and($me->json('data.branches.restricted'))->toBeFalse()
        ->and($me->json('data.modules.organization'))->toBe(['repair_pms', 'detailing', 'equipment'])
        ->and($me->json('data.modules.active'))->toBe(['repair_pms', 'detailing', 'equipment'])
        ->and($me->json('data.modules.by_branch.'.$this->world->id('mekanikomor-binan')))->toBe(['repair_pms'])
        ->and($me->json('data.modules.by_branch.'.$this->world->id('samahuzai-binan')))->toBe(['detailing', 'equipment'])
        ->and($me->json('data.branding.display_name'))->toBe('MekanikoMoR');
});

it('pins a branch manager to their branch, with its modules and its brand', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));

    $me = $this->getJson('/api/v1/me')->assertOk();

    expect(array_column($me->json('data.branches.allowed'), 'slug'))->toBe(['samahuzai-binan'])
        ->and($me->json('data.branches.selected'))->toBe($this->world->id('samahuzai-binan'))
        ->and($me->json('data.branches.restricted'))->toBeTrue()
        ->and($me->json('data.modules.active'))->toBe(['detailing', 'equipment'])
        ->and($me->json('data.branding.display_name'))->toBe('Samahuzai')
        ->and($me->json('data.branding.brand_color'))->toBe('#1d5ba6')
        ->and($me->json('data.branding.support_email'))->toBe('support@mekanikomore.ph');
});

it('gives a portal user their account\'s branding, falling back field by field', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $me = $this->getJson('/api/v1/me')->assertOk();

    expect($me->json('data.branches'))->toBe(['allowed' => [], 'selected' => null, 'restricted' => false])
        ->and($me->json('data.branding'))->toBe([
            'display_name' => 'Actimed',
            'logo_url' => null,
            'brand_color' => '#0f7a5a',
            'support_email' => 'support@mekanikomore.ph',
            'theme_tokens' => null,
        ]);
});
