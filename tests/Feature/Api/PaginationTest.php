<?php

declare(strict_types=1);

use App\Models\User;
use Tests\Support\TestRoutes;

beforeEach(function () {
    TestRoutes::register();
    User::factory()->count(30)->create();
});

it('paginates by page with meta { page, per_page, total } and no links', function () {
    $this->getJson('/api/v1/_test/users?page=2&per_page=10')
        ->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at']], 'meta' => ['page', 'per_page', 'total']])
        ->assertJsonPath('meta', ['page' => 2, 'per_page' => 10, 'total' => 30]);
});

it('defaults to 25 per page', function () {
    $this->getJson('/api/v1/_test/users')
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 25, 'total' => 30]);
});

it('paginates by cursor with meta { per_page, next_cursor, prev_cursor }', function () {
    $first = $this->getJson('/api/v1/_test/users-cursor?per_page=20')
        ->assertOk()
        ->assertJsonCount(20, 'data')
        ->assertExactJsonStructure(['data' => ['*' => ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at']], 'meta' => ['per_page', 'next_cursor', 'prev_cursor']])
        ->assertJsonPath('meta.prev_cursor', null);

    $this->getJson('/api/v1/_test/users-cursor?per_page=20&cursor='.$first->json('meta.next_cursor'))
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.next_cursor', null);
});

it('rejects a page size above the maximum', function () {
    $this->getJson('/api/v1/_test/users?per_page=101')
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation')
        ->assertJsonPath('error.details.fields.per_page.0', 'The per page field must not be greater than 100.');
});
