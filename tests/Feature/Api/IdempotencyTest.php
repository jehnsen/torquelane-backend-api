<?php

declare(strict_types=1);

use App\Http\Middleware\EnforceIdempotency;
use App\Models\IdempotencyKey;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\Support\TestRoutes;

beforeEach(function () {
    TestRoutes::register();
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function idempotentRuns(): int
{
    return (int) Cache::get('test:orders', 0);
}

it('runs every time when no key is sent', function () {
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'])->assertCreated();
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'])->assertCreated();

    expect(idempotentRuns())->toBe(2);
});

it('replays the stored response for the same key and body', function () {
    $headers = [EnforceIdempotency::HEADER => 'key-1'];

    $first = $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)->assertCreated();
    $second = $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers);

    $second->assertStatus(201)
        ->assertHeader(EnforceIdempotency::REPLAYED_HEADER, 'true')
        ->assertHeader('Location', '/api/v1/orders/1');
    expect($second->getContent())->toBe($first->getContent())
        ->and($first->headers->has(EnforceIdempotency::REPLAYED_HEADER))->toBeFalse()
        ->and(idempotentRuns())->toBe(1);
});

it('treats a reordered JSON body as the same request', function () {
    $headers = [EnforceIdempotency::HEADER => 'key-2'];

    $this->postJson('/api/v1/_test/orders', ['item' => 'oil', 'qty' => 2], $headers)->assertCreated();
    $this->postJson('/api/v1/_test/orders', ['qty' => 2, 'item' => 'oil'], $headers)
        ->assertHeader(EnforceIdempotency::REPLAYED_HEADER, 'true');

    expect(idempotentRuns())->toBe(1);
});

it('answers 409 conflict for the same key with a different body', function () {
    $headers = [EnforceIdempotency::HEADER => 'key-3'];

    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)->assertCreated();
    $this->postJson('/api/v1/_test/orders', ['item' => 'brake pads'], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    expect(idempotentRuns())->toBe(1);
});

it('scopes keys per user', function () {
    $headers = [EnforceIdempotency::HEADER => 'shared'];

    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)->assertCreated();
    Sanctum::actingAs(User::factory()->create());
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)
        ->assertCreated()
        ->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);

    expect(idempotentRuns())->toBe(2);
});

it('scopes keys per route', function () {
    $headers = [EnforceIdempotency::HEADER => 'shared'];

    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)->assertCreated();
    $this->postJson('/api/v1/_test/other-orders', ['item' => 'oil'], $headers)
        ->assertCreated()
        ->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);

    expect(idempotentRuns())->toBe(2);
});

it('answers 409 while the first request is still running', function () {
    $headers = [EnforceIdempotency::HEADER => 'in-flight'];
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers);
    IdempotencyKey::query()->update(['completed_at' => null, 'response_status' => null, 'response_body' => null]);

    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');

    expect(idempotentRuns())->toBe(1);
});

it('takes over a claim abandoned by a request that died', function () {
    $headers = [EnforceIdempotency::HEADER => 'abandoned'];
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers);
    IdempotencyKey::query()->update([
        'completed_at' => null,
        'created_at' => now()->subSeconds(EnforceIdempotency::ABANDONED_AFTER_SECONDS + 1),
    ]);

    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers)
        ->assertCreated()
        ->assertHeaderMissing(EnforceIdempotency::REPLAYED_HEADER);

    expect(idempotentRuns())->toBe(2)->and(IdempotencyKey::query()->count())->toBe(1);
});

it('forgets a key after 24 hours', function () {
    $headers = [EnforceIdempotency::HEADER => 'old'];
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], $headers);

    $this->travel(EnforceIdempotency::TTL_HOURS)->hours();
    $this->travel(1)->second();

    $this->postJson('/api/v1/_test/orders', ['item' => 'brake pads'], $headers)
        ->assertCreated()
        ->assertJsonPath('data.echo', 'brake pads');

    expect(idempotentRuns())->toBe(2);
});

it('does not store a 5xx, so the retry runs the action again', function () {
    $headers = [EnforceIdempotency::HEADER => 'flaky'];

    $this->postJson('/api/v1/_test/failing-orders', [], $headers)->assertStatus(500);
    $this->postJson('/api/v1/_test/failing-orders', [], $headers)->assertStatus(500);

    expect(idempotentRuns())->toBe(2)->and(IdempotencyKey::query()->count())->toBe(0);
});

it('requires the header on routes marked idempotent:required', function () {
    $this->postJson('/api/v1/_test/strict-orders')
        ->assertStatus(422)
        ->assertJsonPath('error.details.fields.idempotency_key.0', 'The Idempotency-Key header is required for this endpoint.');

    expect(idempotentRuns())->toBe(0);
});

it('rejects a malformed key', function () {
    $this->postJson('/api/v1/_test/orders', [], [EnforceIdempotency::HEADER => str_repeat('k', 256)])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation');
});

it('prunes keys older than 24 hours', function () {
    $this->postJson('/api/v1/_test/orders', ['item' => 'oil'], [EnforceIdempotency::HEADER => 'prune-me']);
    $this->travel(EnforceIdempotency::TTL_HOURS + 1)->hours();

    $this->artisan('model:prune', ['--model' => [IdempotencyKey::class]])->assertSuccessful();

    expect(IdempotencyKey::query()->count())->toBe(0);
});
