<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use Tests\Support\TestRoutes;

beforeEach(fn () => TestRoutes::register());

it('answers 401 unauthenticated without a token', function () {
    $this->getJson('/api/v1/_test/auth')
        ->assertStatus(401)
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']]);
});

it('answers 401 in the envelope even when the client asks for HTML', function () {
    $this->get('/api/v1/_test/auth', ['Accept' => 'text/html'])
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson(['error' => ['code' => 'unauthenticated', 'message' => 'Unauthenticated.']]);
});

it('answers 404 not_found for an unknown route', function () {
    $this->getJson('/api/v1/no-such-thing')
        ->assertStatus(404)
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);
});

it('answers 404 for a missing model without naming the model', function () {
    $response = $this->getJson('/api/v1/_test/missing-model')
        ->assertStatus(404)
        ->assertExactJson(['error' => ['code' => 'not_found', 'message' => 'Not found.']]);

    expect($response->getContent())->not->toContain('User')->not->toContain('App\\');
});

it('makes an out-of-scope record indistinguishable from a missing one', function () {
    $missing = $this->getJson('/api/v1/_test/missing-model');
    $outOfScope = $this->getJson('/api/v1/_test/out-of-scope');

    expect($outOfScope->status())->toBe(404)
        ->and($outOfScope->json())->toBe($missing->json());
});

it('answers 403 forbidden with the policy reason', function () {
    $this->getJson('/api/v1/_test/forbidden')
        ->assertStatus(403)
        ->assertExactJson(['error' => ['code' => 'forbidden', 'message' => 'Only a provider admin may do this.']]);
});

it('answers 422 validation with per-field details', function () {
    $this->postJson('/api/v1/_test/validate', ['quantity' => 'many'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.details.fields.name', ['The name field is required.'])
        ->assertJsonPath('error.details.fields.quantity', ['The quantity field must be an integer.'])
        ->assertJsonMissingPath('message')
        ->assertJsonMissingPath('errors');
});

it('answers 409 invalid_transition with details', function () {
    $this->postJson('/api/v1/_test/transition')
        ->assertStatus(409)
        ->assertExactJson(['error' => [
            'code' => 'invalid_transition',
            'message' => 'Cannot move a closed work order back to draft.',
            'details' => ['from' => 'closed', 'to' => 'draft'],
        ]]);
});

it('answers 409 conflict', function () {
    $this->postJson('/api/v1/_test/conflict')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'conflict');
});

it('answers 403 module_disabled', function () {
    $this->getJson('/api/v1/_test/module')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'module_disabled');
});

it('answers 405 method_not_allowed', function () {
    $this->postJson('/api/v1/health')
        ->assertStatus(405)
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('answers 429 rate_limited with Retry-After', function () {
    $this->getJson('/api/v1/_test/throttled')->assertOk();

    $this->getJson('/api/v1/_test/throttled')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertExactJson(['error' => ['code' => 'rate_limited', 'message' => 'Too many requests.']]);
});

it('answers 500 server_error without leaking the exception', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertExactJson(['error' => ['code' => 'server_error', 'message' => 'Server error.']]);

    expect($response->getContent())->not->toContain('hunter2')->not->toContain('RuntimeException');
});

it('adds exception details to a 500 only in debug mode', function () {
    config(['app.debug' => true]);

    $this->getJson('/api/v1/_test/boom')
        ->assertStatus(500)
        ->assertJsonPath('error.code', 'server_error')
        ->assertJsonPath('error.details.exception', RuntimeException::class);
});

it('puts a request id on error responses too', function () {
    $this->getJson('/api/v1/no-such-thing', [AssignRequestId::HEADER => 'trace-123'])
        ->assertStatus(404)
        ->assertHeader(AssignRequestId::HEADER, 'trace-123');
});
