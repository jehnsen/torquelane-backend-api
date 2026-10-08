<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * "My profile": a signed-in user edits their own first name, last name,
 * username (the display name follows) and password. Usernames are handles,
 * unique per organization, case-insensitively; never a credential.
 */

beforeEach(function () {
    $this->world = World::build();
});

it('backfills the demo users\' names and usernames as ../web did', function () {
    Sanctum::actingAs($this->world->user('ops@mekanikomore.ph'));

    $this->getJson('/api/v1/me')->assertOk()
        ->assertJsonPath('data.user.first_name', 'Marisol')
        ->assertJsonPath('data.user.last_name', 'Bautista')
        ->assertJsonPath('data.user.username', 'ops');
});

it('updates the caller\'s own names and username', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->patchJson('/api/v1/me', ['first_name' => ' Miguel ', 'last_name' => 'Dela Paz', 'username' => 'miguel.dp'])
        ->assertOk()
        ->assertJsonPath('data.user.name', 'Miguel Dela Paz')
        ->assertJsonPath('data.user.first_name', 'Miguel')
        ->assertJsonPath('data.user.last_name', 'Dela Paz')
        ->assertJsonPath('data.user.username', 'miguel.dp')
        ->assertJsonPath('data.user.role', 'fleet_manager');

    expect(asSystem(fn () => AuditLog::query()->where('action', 'profile_updated')->count()))->toBe(1);
});

it('refuses a username already taken in the organization, whatever its case', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->patchJson('/api/v1/me', ['first_name' => 'Don', 'last_name' => 'Miguel', 'username' => 'OWNER'])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.username.0', 'That username is already taken.');
    $this->patchJson('/api/v1/me', ['first_name' => 'Don', 'last_name' => 'Miguel', 'username' => 'no spaces'])->assertUnprocessable();
    $this->patchJson('/api/v1/me', ['first_name' => '', 'last_name' => 'Miguel', 'username' => 'donmiguel'])->assertUnprocessable();

    // Another organization's handles are its own.
    $rival = $this->world->user('rival:admin');
    Sanctum::actingAs($rival);
    $this->patchJson('/api/v1/me', ['first_name' => 'Rival', 'last_name' => 'Admin', 'username' => 'owner'])->assertOk();
});

it('changes the password only with the current one', function () {
    Sanctum::actingAs($this->world->user('donmiguel@mekanikomor.ph'));

    $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])
        ->assertUnprocessable()
        ->assertJsonPath('error.details.fields.current_password.0', 'Your current password isn\'t correct.');
    $this->putJson('/api/v1/me/password', ['current_password' => 'demo1234', 'password' => 'short', 'password_confirmation' => 'short'])->assertUnprocessable();

    $this->putJson('/api/v1/me/password', ['current_password' => 'demo1234', 'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1'])->assertNoContent();

    $user = asSystem(fn (): User => User::query()->where('email', 'donmiguel@mekanikomor.ph')->firstOrFail());
    expect(Hash::check('new-secret-1', $user->getAuthPassword()))->toBeTrue()
        ->and(asSystem(fn () => AuditLog::query()->where('action', 'password_changed')->value('after')))->toBeNull();
});

it('needs a session', function () {
    $this->patchJson('/api/v1/me', ['first_name' => 'A', 'last_name' => 'B', 'username' => 'abc'])->assertUnauthorized();
    $this->putJson('/api/v1/me/password', [])->assertUnauthorized();
});
