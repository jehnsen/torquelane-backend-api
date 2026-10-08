<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Keeps Supabase's auto-generated REST API (PostgREST) away from our schema.
 *
 * Supabase's `anon` and `authenticated` roles are what PostgREST runs browser
 * requests as. Revoking USAGE on the schema is the load-bearing part: without
 * it no grant on any table, present or future, is reachable. The table,
 * sequence and routine revokes and the default-privilege revokes are defence
 * in depth. Only our schema is touched: the shared project's `public` schema
 * (the legacy frontend's pms_* tables and an unrelated app) is left alone.
 *
 * A no-op on a server where those roles do not exist (plain Postgres). Local
 * docker and CI create them so this migration is exercised and tested.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            do $$
            declare
                r text;
                s text := current_schema();
            begin
                execute format('revoke all on schema %I from public', s);
                foreach r in array array['anon', 'authenticated'] loop
                    if exists (select 1 from pg_roles where rolname = r) then
                        execute format('revoke all on schema %I from %I', s, r);
                        execute format('revoke all on all tables in schema %I from %I', s, r);
                        execute format('revoke all on all sequences in schema %I from %I', s, r);
                        execute format('revoke all on all routines in schema %I from %I', s, r);
                        execute format('alter default privileges in schema %I revoke all on tables from %I', s, r);
                        execute format('alter default privileges in schema %I revoke all on sequences from %I', s, r);
                        execute format('alter default privileges in schema %I revoke all on routines from %I', s, r);
                    end if;
                end loop;
            end
            $$
            SQL);
    }

    public function down(): void
    {
        // Deliberately nothing: rolling back must not re-open the schema.
    }
};
