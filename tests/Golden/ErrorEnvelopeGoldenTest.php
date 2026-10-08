<?php

declare(strict_types=1);

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestRoutes;

/*
 * Golden files pin the exact bytes clients parse. A diff here is an API
 * change: update the fixture deliberately (UPDATE_GOLDEN=1 composer test),
 * review it in the diff, and regenerate openapi.json.
 */

beforeEach(fn () => TestRoutes::register());

function assertGolden(TestResponse $response, string $name, ?Closure $mask = null): void
{
    $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $actual = json_encode(
        ['status' => $response->getStatusCode(), 'body' => $mask ? $mask($body) : $body],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
    )."\n";

    $path = __DIR__."/fixtures/{$name}.json";

    if (getenv('UPDATE_GOLDEN') === '1' || ! file_exists($path)) {
        if (getenv('CI') !== false && ! file_exists($path)) {
            test()->fail("Golden fixture {$name}.json is missing; generate it locally and commit it.");
        }
        @mkdir(dirname($path), recursive: true);
        file_put_contents($path, $actual);
    }

    expect($actual)->toBe(file_get_contents($path));
}

it('matches the golden envelope for every error code', function (string $method, string $uri, array $payload, string $name) {
    config(['app.debug' => false]);

    assertGolden($this->json($method, $uri, $payload), "errors/{$name}");
})->with([
    'unauthenticated 401' => ['GET', '/api/v1/_test/auth', [], 'unauthenticated'],
    'forbidden 403' => ['GET', '/api/v1/_test/forbidden', [], 'forbidden'],
    'not found 404' => ['GET', '/api/v1/no-such-thing', [], 'not_found'],
    'out of scope 404' => ['GET', '/api/v1/_test/out-of-scope', [], 'not_found_out_of_scope'],
    'method not allowed 405' => ['POST', '/api/v1/health', [], 'method_not_allowed'],
    'validation 422' => ['POST', '/api/v1/_test/validate', ['quantity' => 'x'], 'validation'],
    'invalid transition 409' => ['POST', '/api/v1/_test/transition', [], 'invalid_transition'],
    'conflict 409' => ['POST', '/api/v1/_test/conflict', [], 'conflict'],
    'module disabled 403' => ['GET', '/api/v1/_test/module', [], 'module_disabled'],
    'server error 500' => ['GET', '/api/v1/_test/boom', [], 'server_error'],
]);

it('matches the golden rate-limited envelope', function () {
    $this->getJson('/api/v1/_test/throttled');

    assertGolden($this->getJson('/api/v1/_test/throttled'), 'errors/rate_limited');
});

it('matches the golden health body', function () {
    RecordQueueHeartbeat::dispatch();

    assertGolden($this->getJson('/api/v1/health'), 'health_ok', function (array $body): array {
        $utc = '/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/';
        expect($body['data']['checked_at'])->toMatch($utc)
            ->and($body['data']['checks']['queue']['last_heartbeat_at'])->toMatch($utc)
            ->and($body['data']['checks']['database']['latency_ms'])->toBeInt();

        $body['data']['checked_at'] = '<utc-timestamp>';
        $body['data']['checks']['database']['latency_ms'] = '<int>';
        $body['data']['checks']['queue']['last_heartbeat_at'] = '<utc-timestamp>';

        return $body;
    });
});
