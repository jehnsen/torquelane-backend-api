<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Database\EnsureSchemaExists;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * RefreshDatabase plus the API schema. Laravel fires CommandStarting only for
 * real CLI invocations, so the in-process `migrate:fresh` that RefreshDatabase
 * runs never reaches the EnsureSchemaExists listener; each parallel worker's
 * fresh database gets its schema here instead.
 */
trait RefreshApiDatabase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        EnsureSchemaExists::on(DB::connection());
    }
}
