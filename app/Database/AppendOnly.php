<?php

declare(strict_types=1);

namespace App\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes a table append-only at the database, not by convention.
 *
 * In a migration, after `Schema::create(...)`:
 *
 *     AppendOnly::protect('audit_log');      // up()
 *     AppendOnly::unprotect('audit_log');    // down(), before dropping
 *
 * Installs BEFORE UPDATE OR DELETE (per row) and BEFORE TRUNCATE (per
 * statement) triggers that call `forbid_append_only_mutation()`, created by
 * the 2026_10_08_100000 migration. Any UPDATE, DELETE or TRUNCATE then raises
 * SQLSTATE 23001 (restrict_violation) regardless of who issues it, including
 * a superuser running SQL by hand. DROP TABLE is still allowed, so
 * `migrate:fresh` keeps working; tests must use RefreshDatabase (transactions),
 * never DatabaseTruncation.
 */
final class AppendOnly
{
    public const FUNCTION_NAME = 'forbid_append_only_mutation';

    public static function protect(string $table): void
    {
        $quoted = self::identifier($table);
        $function = self::FUNCTION_NAME;

        DB::statement(sprintf(
            'create trigger %s before update or delete on %s for each row execute function %s()',
            self::identifier($table.'_append_only'),
            $quoted,
            $function,
        ));
        DB::statement(sprintf(
            'create trigger %s before truncate on %s for each statement execute function %s()',
            self::identifier($table.'_append_only_truncate'),
            $quoted,
            $function,
        ));
    }

    public static function unprotect(string $table): void
    {
        $quoted = self::identifier($table);

        DB::statement(sprintf('drop trigger if exists %s on %s', self::identifier($table.'_append_only'), $quoted));
        DB::statement(sprintf('drop trigger if exists %s on %s', self::identifier($table.'_append_only_truncate'), $quoted));
    }

    public static function isProtected(string $table): bool
    {
        return DB::scalar(
            'select count(*) = 2 from pg_trigger t
             join pg_class c on c.oid = t.tgrelid
             join pg_namespace n on n.oid = c.relnamespace
             where c.relname = ? and n.nspname = current_schema() and t.tgname in (?, ?)',
            [$table, $table.'_append_only', $table.'_append_only_truncate'],
        ) === true;
    }

    private static function identifier(string $name): string
    {
        if (preg_match('/\A[a-z_][a-z0-9_]{0,62}\z/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid table or trigger name [{$name}].");
        }

        return '"'.$name.'"';
    }
}
