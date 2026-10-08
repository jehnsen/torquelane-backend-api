<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

beforeEach(function () {
    $this->world = World::build();
});

function login(string $email, string $password = DemoSeeder::PASSWORD): TestResponse
{
    return test()->withHeaders(spaHeaders())->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

it('serves the CSRF cookie under /api/v1', function () {
    $this->withHeaders(spaHeaders())->get('/api/v1/sanctum/csrf-cookie')
        ->assertNoContent()
        ->assertCookie('XSRF-TOKEN');
});

it('signs a staff member in and answers with /me', function () {
    login('owner@mekanikomore.ph')
        ->assertOk()
        ->assertJsonPath('data.user.email', 'owner@mekanikomore.ph')
        ->assertJsonPath('data.side', 'staff')
        ->assertJsonPath('data.organization.name', 'MekanikoMoR');

    $this->assertAuthenticatedAs($this->world->user('owner@mekanikomore.ph'), 'web');
    expect(asSystem(fn () => AuditLog::query()->where('action', 'logged_in')->count()))->toBe(1)
        ->and($this->world->user('owner@mekanikomore.ph')->last_login_at)->not->toBeNull();
});

it('signs a portal user in to their own account', function () {
    login('fleet@northwind.ph')
        ->assertOk()
        ->assertJsonPath('data.side', 'portal')
        ->assertJsonPath('data.customer_account.id', $this->world->id('fc-northwind'));
});

it('treats the email case-insensitively', function () {
    login('Owner@MekanikoMoRe.ph')->assertOk();
});

it('gives one message for an unknown email and a wrong password', function (string $email, string $password) {
    login($email, $password)
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.details.fields.email.0', "That email and password combination isn't recognised.");

    $this->assertGuest('web');
})->with([
    'unknown email' => ['nobody@example.com', DemoSeeder::PASSWORD],
    'wrong password' => ['owner@mekanikomore.ph', 'not-the-password'],
]);

it('refuses a suspended account\'s portal user at login, with the reason', function () {
    login('yard@bayanicon.ph')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'account_suspended')
        ->assertJsonPath('error.details.reason', 'account_suspended');

    $this->assertGuest('web');
});

it('refuses a disabled user at login', function () {
    asSystem(fn () => $this->world->user('advisor@mekanikomore.ph')->forceFill(['status' => User::DISABLED])->save());

    login('advisor@mekanikomore.ph')
        ->assertStatus(403)
        ->assertJsonPath('error.details.reason', 'user_disabled');
});

it('needs a cookie session: a request from outside the SPA origins is a 400', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'owner@mekanikomore.ph', 'password' => DemoSeeder::PASSWORD])
        ->assertStatus(400)
        ->assertJsonPath('error.code', 'bad_request');
});

it('rate-limits login attempts per email and IP', function () {
    foreach (range(1, 5) as $attempt) {
        login('owner@mekanikomore.ph', 'wrong')->assertStatus(422);
    }

    login('owner@mekanikomore.ph')
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'rate_limited')
        ->assertHeader('Retry-After');
});

it('signs out', function () {
    login('owner@mekanikomore.ph')->assertOk();

    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('lets a suspended account\'s portal user sign out', function () {
    Sanctum::actingAs($this->world->user('yard@bayanicon.ph'));

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
});

it('ignores bearer tokens: SPA cookie auth only', function () {
    $token = $this->world->user('owner@mekanikomore.ph')->createToken('test')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/me')->assertStatus(401);
});

it('emails a reset link and answers the same for unknown addresses', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@mekanikomore.ph'])
        ->assertStatus(202)->assertExactJson(['data' => ['status' => 'sent']]);
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'ghost@example.com'])
        ->assertStatus(202)->assertExactJson(['data' => ['status' => 'sent']]);

    Notification::assertSentTo($this->world->user('owner@mekanikomore.ph'), ResetPassword::class, function (ResetPassword $notification, array $channels, User $user): bool {
        expect($notification->toMail($user)->actionUrl)->toStartWith('http://localhost:3000/reset-password?token=');

        return true;
    });
    Notification::assertCount(1);
});

it('resets a password with the emailed token', function () {
    Notification::fake();
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@mekanikomore.ph']);

    $token = null;
    Notification::assertSentTo($this->world->user('owner@mekanikomore.ph'), ResetPassword::class, function (ResetPassword $notification) use (&$token): bool {
        $token = $notification->token;

        return true;
    });

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'owner@mekanikomore.ph',
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertOk();

    login('owner@mekanikomore.ph', 'a-new-password')->assertOk();
    expect(asSystem(fn () => AuditLog::query()->where('action', 'password_reset')->count()))->toBe(1);
});

it('refuses a bad reset token', function () {
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'nope',
        'email' => 'owner@mekanikomore.ph',
        'password' => 'a-new-password',
        'password_confirmation' => 'a-new-password',
    ])->assertStatus(422)->assertJsonPath('error.details.fields.token.0', 'This reset link is invalid or has expired. Request a new one.');
});

it('rate-limits password reset requests', function () {
    Notification::fake();
    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@mekanikomore.ph'])->assertStatus(202);
    }

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'owner@mekanikomore.ph'])->assertStatus(429);
});
