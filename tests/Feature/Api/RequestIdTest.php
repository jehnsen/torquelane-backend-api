<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Illuminate\Support\Str;
use Tests\Support\TestRoutes;

beforeEach(fn () => TestRoutes::register());

it('generates a ULID request id when none is sent', function () {
    $response = $this->getJson('/api/v1/_test/request-id')->assertOk();

    $id = $response->headers->get(AssignRequestId::HEADER);
    expect(Str::isUlid((string) $id))->toBeTrue()
        ->and($response->json('request_id'))->toBe($id);
});

it('echoes a well-formed incoming request id and shares it via Context', function () {
    $this->getJson('/api/v1/_test/request-id', [AssignRequestId::HEADER => 'lb-7f3a.42:x'])
        ->assertOk()
        ->assertHeader(AssignRequestId::HEADER, 'lb-7f3a.42:x')
        ->assertJsonPath('request_id', 'lb-7f3a.42:x');
});

it('replaces a malformed incoming request id', function (string $bad) {
    $response = $this->getJson('/api/v1/_test/request-id', [AssignRequestId::HEADER => $bad])->assertOk();

    expect($response->headers->get(AssignRequestId::HEADER))->not->toBe($bad)
        ->and(Str::isUlid((string) $response->headers->get(AssignRequestId::HEADER)))->toBeTrue();
})->with([
    'spaces' => 'has spaces',
    'injection' => "abc\r\nSet-Cookie: x=1",
    'too long' => str_repeat('a', 129),
]);
