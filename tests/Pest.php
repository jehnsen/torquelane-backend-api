<?php

declare(strict_types=1);

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
