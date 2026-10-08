<?php

declare(strict_types=1);

use App\Tenancy\TenantManager;
use Tests\Concerns\RefreshApiDatabase;
use Tests\TestCase;

/*
 * Unit and Arch tests are pure PHP: no application, no database.
 * Everything else boots Laravel against real Postgres. RefreshApiDatabase
 * (RefreshDatabase + the API schema) wraps each test in a transaction —
 * never switch to DatabaseTruncation, which the append-only triggers reject
 * by design.
 */
pest()->extend(TestCase::class)
    ->use(RefreshApiDatabase::class)
    ->in('Feature', 'Isolation', 'Golden');

/**
 * Tenant models refuse to be read without a tenant context. Tests that read
 * or seed directly do it in a named system context, like seeders do.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function asSystem(Closure $callback): mixed
{
    return app(TenantManager::class)->system('test', $callback);
}

/**
 * Headers that make a request "from the SPA" (a Sanctum stateful domain), so
 * it gets a session: needed for cookie login.
 *
 * @return array<string, string>
 */
function spaHeaders(): array
{
    return ['Origin' => 'http://localhost:3000', 'Referer' => 'http://localhost:3000/'];
}
