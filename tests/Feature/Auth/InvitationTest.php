<?php

declare(strict_types=1);

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Support\World;

/*
 * Port of ../web/app/api/admin/users/route.ts: only roles with access:manage
 * invite; portal roles must be pinned to an account; staff roles must not
 * be. Plus the API's no-escalation and branch-containment rules.
 */

beforeEach(function () {
    $this->world = World::build();
    Notification::fake();
});

function invite(array $body): TestResponse
{
    return test()->postJson('/api/v1/invitations', $body + ['name' => 'New Person']);
}

/** The plain token from the invitation email sent to $email. */
function invitationToken(string $email): string
{
    $token = null;
    Notification::assertSentTo(new AnonymousNotifiable, InvitationNotification::class, function (InvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable) use ($email, &$token): bool {
        if ($notifiable->routes['mail'] === $email) {
            $token = $notification->token;
        }

        return true;
    });

    return $token ?? throw new LogicException("No invitation was emailed to {$email}.");
}

it('lets the provider admin invite a portal user to an account, by email', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'Dispatch@Actimed.ph', 'role' => 'operations', 'customer_account_id' => $this->world->id('fc-actimed')])
        ->assertCreated()
        ->assertJsonPath('data.email', 'dispatch@actimed.ph')
        ->assertJsonPath('data.side', 'portal')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonMissingPath('data.token')
        ->assertJsonMissingPath('data.token_hash');

    expect(invitationToken('dispatch@actimed.ph'))->toHaveLength(64);
});

it('refuses roles without access:manage', function (string $who) {
    Sanctum::actingAs($this->world->user($who));

    invite(['email' => 'x@example.com', 'role' => 'viewer', 'customer_account_id' => $this->world->id('fc-actimed')])
        ->assertForbidden()
        ->assertJsonPath('error.message', fn (string $message) => str_contains($message, "doesn't have permission to manage user access"));
})->with(['advisor@mekanikomore.ph', 'cashier@mekanikomore.ph', 'donmiguel@mekanikomor.ph', 'viewer@mekanikomore.ph']);

it('requires an account for a portal role', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'x@example.com', 'role' => 'viewer'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.customer_account_id.0', 'Choose which customer account this person belongs to.');
});

it('refuses an account for a staff role', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'x@example.com', 'role' => 'service_advisor', 'customer_account_id' => $this->world->id('fc-actimed')])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.customer_account_id.0', 'Staff roles are not pinned to a customer account.');
});

it('refuses another organization\'s account', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'x@example.com', 'role' => 'viewer', 'customer_account_id' => $this->world->id('rival:account')])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.customer_account_id.0', "That customer account doesn't belong to your organization.");
});

it('refuses a portal invitation to a suspended account (no new work)', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'x@example.com', 'role' => 'viewer', 'customer_account_id' => $this->world->id('fc-bayani')])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended');

    Notification::assertNothingSent();
});

it('refuses an email that already has an account', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'admin@other.example', 'role' => 'service_advisor'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.email.0', 'An account with that email already exists.');
});

it('stops a branch manager from inviting above their own grants', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));

    invite(['email' => 'boss@example.com', 'role' => 'provider_admin'])->assertForbidden();
});

it('keeps a branch manager\'s staff invitations inside their branches', function () {
    Sanctum::actingAs($this->world->user('manager.samahuzai@mekanikomore.ph'));
    $detailing = $this->world->id('samahuzai-binan');
    $repair = $this->world->id('mekanikomor-binan');

    invite(['email' => 'a@example.com', 'role' => 'cashier'])
        ->assertStatus(422)->assertJsonPath('error.details.fields.branch_ids.0', 'Choose which of your branches this person works in.');
    invite(['email' => 'b@example.com', 'role' => 'cashier', 'branch_ids' => [$repair]])
        ->assertStatus(422)->assertJsonPath('error.details.fields.branch_ids.0', 'You can only assign branches you have access to.');
    invite(['email' => 'c@example.com', 'role' => 'cashier', 'branch_ids' => [$detailing]])
        ->assertCreated()->assertJsonPath('data.branch_ids', [$detailing]);
});

it('replaces a pending invitation when the same email is invited again', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    invite(['email' => 'twice@example.com', 'role' => 'service_advisor'])->assertCreated();
    invite(['email' => 'twice@example.com', 'role' => 'provider_technician'])->assertCreated();

    expect(asSystem(fn () => Invitation::query()->pending()->where('email', 'twice@example.com')->pluck('role')->map->value->all()))
        ->toBe(['provider_technician']);
});

it('creates the user from the invitation and signs them in', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    invite(['email' => 'dispatch@actimed.ph', 'role' => 'operations', 'title' => 'Dispatcher', 'customer_account_id' => $this->world->id('fc-actimed')])->assertCreated();
    $token = invitationToken('dispatch@actimed.ph');
    $this->app['auth']->forgetGuards();

    $this->withHeaders(spaHeaders())->postJson('/api/v1/auth/invitations/accept', [
        'token' => $token,
        'password' => 'correct-horse',
        'password_confirmation' => 'correct-horse',
    ])
        ->assertCreated()
        ->assertJsonPath('data.user.email', 'dispatch@actimed.ph')
        ->assertJsonPath('data.user.role', 'operations')
        ->assertJsonPath('data.user.title', 'Dispatcher')
        ->assertJsonPath('data.customer_account.id', $this->world->id('fc-actimed'));

    $user = asSystem(fn () => User::query()->where('email', 'dispatch@actimed.ph')->firstOrFail());
    $this->assertAuthenticatedAs($user, 'web');

    // Single use.
    $this->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => 'another-one', 'password_confirmation' => 'another-one'])
        ->assertStatus(422);
});

it('applies the invited branch pins on acceptance', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    $repair = $this->world->id('mekanikomor-binan');
    invite(['email' => 'tech2@mekanikomore.ph', 'role' => 'provider_technician', 'branch_ids' => [$repair]])->assertCreated();

    $this->postJson('/api/v1/auth/invitations/accept', ['token' => invitationToken('tech2@mekanikomore.ph'), 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse'])
        ->assertCreated()
        ->assertJsonPath('data.branches.restricted', true)
        ->assertJsonPath('data.branches.selected', $repair);
});

it('refuses an expired or revoked invitation', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    invite(['email' => 'late@example.com', 'role' => 'service_advisor'])->assertCreated();
    $token = invitationToken('late@example.com');

    $this->travel(Invitation::EXPIRES_AFTER_DAYS + 1)->days();

    $this->postJson('/api/v1/auth/invitations/accept', ['token' => $token, 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse'])
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.token.0', 'This invitation is invalid or has expired. Ask for a new one.');
});

it('refuses acceptance once the account has been suspended', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));
    invite(['email' => 'soon@northwind.ph', 'role' => 'viewer', 'customer_account_id' => $this->world->id('fc-northwind')])->assertCreated();
    $this->postJson('/api/v1/customer-accounts/'.$this->world->id('fc-northwind').'/suspend')->assertOk();

    $this->postJson('/api/v1/auth/invitations/accept', ['token' => invitationToken('soon@northwind.ph'), 'password' => 'correct-horse', 'password_confirmation' => 'correct-horse'])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'account_suspended');
});

it('revokes a pending invitation', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->deleteJson('/api/v1/invitations/'.$this->world->id('invite:staff'))
        ->assertOk()
        ->assertJsonPath('data.status', 'revoked');
    $this->deleteJson('/api/v1/invitations/'.$this->world->id('invite:staff'))
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'invalid_transition');
});

it('cannot revoke another organization\'s invitation', function () {
    Sanctum::actingAs($this->world->user('owner@mekanikomore.ph'));

    $this->deleteJson('/api/v1/invitations/'.$this->world->id('rival:invite'))->assertNotFound();
});
