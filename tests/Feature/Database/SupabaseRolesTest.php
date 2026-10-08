<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * docker/postgres/init/01-supabase-roles.sql (and CI) create Supabase's anon
 * and authenticated roles so this runs for real rather than as a no-op.
 */

const SUPABASE_API_ROLES = ['anon', 'authenticated'];

function revokeMigration(): Migration
{
    /** @var Migration */
    return require database_path('migrations/2026_10_08_100200_revoke_supabase_api_roles.php');
}

beforeEach(function () {
    $missing = array_diff(SUPABASE_API_ROLES, DB::table('pg_roles')->pluck('rolname')->all());

    expect($missing)->toBeEmpty(
        'Roles '.implode(', ', $missing).' are missing; run docker/postgres/init/01-supabase-roles.sql against the test server.',
    );
});

it('leaves the API roles no USAGE on our schema', function (string $role) {
    expect(DB::scalar('select has_schema_privilege(?, current_schema(), ?)', [$role, 'USAGE']))->toBeFalse();
})->with(SUPABASE_API_ROLES);

it('leaves the API roles no privilege on any table in our schema', function (string $role) {
    $tables = DB::table('pg_tables')->where('schemaname', DB::raw('current_schema()'))->pluck('tablename');
    expect($tables)->not->toBeEmpty();

    foreach ($tables as $table) {
        $granted = DB::scalar(
            'select has_table_privilege(?, quote_ident(current_schema()) || ? || quote_ident(?), ?)',
            [$role, '.', $table, 'SELECT,INSERT,UPDATE,DELETE,TRUNCATE,REFERENCES,TRIGGER'],
        );
        expect($granted)->toBeFalse("{$role} holds a privilege on {$table}");
    }
})->with(SUPABASE_API_ROLES);

it('revokes grants that were added after the fact', function () {
    $schema = DB::scalar('select current_schema()');
    DB::statement(sprintf('grant usage on schema "%s" to anon, authenticated', $schema));
    DB::statement('grant select, insert on users to anon, authenticated');
    expect(DB::scalar("select has_table_privilege('anon', 'users', 'SELECT')"))->toBeTrue();

    revokeMigration()->up();

    foreach (SUPABASE_API_ROLES as $role) {
        expect(DB::scalar('select has_schema_privilege(?, current_schema(), ?)', [$role, 'USAGE']))->toBeFalse()
            ->and(DB::scalar("select has_table_privilege(?, 'users', 'SELECT,INSERT')", [$role]))->toBeFalse();
    }
});

it('does not touch the public schema other apps share', function () {
    $before = DB::scalar("select has_schema_privilege('anon', 'public', 'USAGE')");

    revokeMigration()->up();

    expect(DB::scalar("select has_schema_privilege('anon', 'public', 'USAGE')"))->toBe($before);
});
