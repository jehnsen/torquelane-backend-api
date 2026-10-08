<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenancyViolation;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->world = World::build();
});

function actAs(User $user, Closure $callback): mixed
{
    $context = app(TenantContextResolver::class)->resolve($user, null)->context ?? throw new LogicException('no context');

    return app(TenantManager::class)->actingAs($context, $callback);
}

it('refuses to read a tenant model without a tenant context', function () {
    CustomerAccount::query()->count();
})->throws(TenancyViolation::class, 'No tenant context');

it('scopes every tenant read to the current organization', function () {
    $rival = $this->world->user('rival:admin');

    $names = actAs($rival, fn () => CustomerAccount::query()->orderBy('display_name')->pluck('display_name')->all());

    expect($names)->toBe(['Rival Fleet Co', 'Rival Walk-in']);
});

it('finds nothing of another organization even by primary key', function () {
    $actimed = $this->world->id('fc-actimed');

    expect(actAs($this->world->user('rival:admin'), fn () => CustomerAccount::query()->find($actimed)))->toBeNull();
});

it('fills organization_id from the context on create', function () {
    $owner = $this->world->user('owner@mekanikomore.ph');

    $account = actAs($owner, function (): CustomerAccount {
        $account = new CustomerAccount;
        $account->forceFill(['account_type' => 'individual', 'display_name' => 'Walk In'])->save();

        return $account;
    });

    expect($account->organization_id)->toBe($owner->organization_id);
});

it('refuses to write a row into another organization', function () {
    $account = asSystem(fn () => CustomerAccount::query()->findOrFail($this->world->id('rival:account')));

    actAs($this->world->user('owner@mekanikomore.ph'), fn () => $account->forceFill(['notes' => 'mine now'])->save());
})->throws(TenancyViolation::class, 'into an organization other than');

it('refuses to create a tenant row with neither an organization nor a context', function () {
    (new Contact)->forceFill(['customer_account_id' => $this->world->id('fc-actimed'), 'name' => 'Orphan'])->save();
})->throws(TenancyViolation::class);

it('keeps a portal user to their own account even within the organization', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->getJson('/api/v1/customer-accounts')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->world->id('fc-actimed'));
    $this->getJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind'))->assertNotFound();
    $this->getJson('/api/v1/users')->assertOk()->assertJsonPath('meta.total', 5);
});

it('returns the same 404 body for another tenant\'s record as for a missing one', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $foreign = $this->getJson('/api/v1/customer-accounts/'.$this->world->id('rival:account'))->assertNotFound()->json();
    $missing = $this->getJson('/api/v1/customer-accounts/01aaaaaaaaaaaaaaaaaaaaaaaa')->assertNotFound()->json();

    expect($foreign)->toBe($missing);
});

it('writes one audit row per change, with the actor, role and request id', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->withHeaders(['X-Request-Id' => 'req-audit-1'])
        ->patchJson('/api/v1/organization', ['legal_name' => 'MekanikoMoR Auto Services Inc.'])
        ->assertOk()
        ->assertJsonPath('data.legal_name', 'MekanikoMoR Auto Services Inc.');

    $log = asSystem(fn () => AuditLog::query()->where('entity_type', 'organization')->sole());

    expect($log->request_id)->toBe('req-audit-1')
        ->and($log->actor_id)->toBe($this->world->id('owner@mekanikomore.ph'))
        ->and($log->actor_role)->toBe('provider_admin')
        ->and($log->organization_id)->toBe($this->world->id('prov-mekanikomore'))
        ->and($log->action)->toBe('updated')
        ->and($log->before)->toHaveKey('legal_name', null)
        ->and($log->after['legal_name'] ?? null)->toBe('MekanikoMoR Auto Services Inc.');
});

it('keeps the audit trail and the consent ledger append-only', function (string $table) {
    // Make sure there is a row to try to change (the seeders write no audit rows).
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $this->patchJson('/api/v1/organization', ['legal_name' => 'Audit me'])->assertOk();
    expect(DB::table($table)->count())->toBeGreaterThan(0);

    DB::transaction(fn () => DB::table($table)->limit(1)->update(['organization_id' => $this->world->id('rival')]));
})->with(['consents', 'audit_logs'])->throws(QueryException::class, '23001');

it('lets the database refuse a staff role pinned to an account, or a portal user without one', function (array $attributes) {
    DB::transaction(fn () => DB::table('users')->where('id', $this->world->id('advisor@mekanikomore.ph'))->update($attributes));
})->with([
    'staff pinned to an account' => [['customer_account_id' => '01aaaaaaaaaaaaaaaaaaaaaaaa']],
    'portal role on the staff side' => [['role' => 'viewer']],
])->throws(QueryException::class);

it('lets the database refuse an account from another organization', function () {
    DB::transaction(fn () => DB::table('users')
        ->where('id', $this->world->id('donmiguel@mekanikomor.ph'))
        ->update(['customer_account_id' => $this->world->id('rival:account')]));
})->throws(QueryException::class, 'foreign key');

it('keeps the organization itself out of other tenants\' reach', function () {
    expect(fn () => asSystem(fn () => Organization::query()->count()))->not->toThrow(TenancyViolation::class);
});
