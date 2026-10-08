<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Creates the API's own Postgres schema (DB_SCHEMA, default `torquelane`)
 * before anything migrates.
 *
 * Laravel creates its `migrations` table in the first schema on the
 * search_path before any migration runs, so the schema cannot be created by a
 * migration. Two entry points cover every path that migrates:
 *   - this listener, for `php artisan migrate*` (composer setup, deploys, CI);
 *   - Tests\TestCase::beforeRefreshingDatabase(), because Laravel only fires
 *     CommandStarting for real CLI invocations, not for the in-process
 *     `migrate:fresh` that RefreshDatabase runs in each test database.
 */
final class EnsureSchemaExists
{
    public function __construct(private readonly DatabaseManager $db) {}

    public function handle(CommandStarting $event): void
    {
        if (str_starts_with($event->command, 'migrate')) {
            self::on($this->db->connection());
        }
    }

    public static function on(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $schema = self::schemaName($connection->getConfig('search_path'));

        // Look before creating: Postgres checks the database-level CREATE
        // privilege before it honours IF NOT EXISTS, so a least-privilege role
        // that owns a pre-created schema (docs/deploy.md 1b) would fail here.
        $exists = $connection->scalar('select exists (select 1 from pg_namespace where nspname = ?)', [$schema]);

        if ($exists !== true) {
            $connection->statement(sprintf('create schema "%s"', $schema));
        }
    }

    public static function schemaName(mixed $searchPath): string
    {
        $first = is_array($searchPath) ? ($searchPath[0] ?? '') : explode(',', is_string($searchPath) ? $searchPath : '')[0];
        $schema = trim(is_string($first) ? $first : '', " \"'");

        if (preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/', $schema) !== 1) {
            throw new InvalidArgumentException("Invalid DB_SCHEMA [{$schema}].");
        }

        return $schema;
    }
}
