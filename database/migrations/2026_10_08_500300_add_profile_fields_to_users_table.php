<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Profile self-service (../web 0007_pms_profile_fields): first name, last
 * name and a username, edited from "My profile". `name` stays the derived
 * "first last" every list reads.
 *
 * A username is a display handle, never a credential (sign-in is by email).
 * Unlike ../web's global constraint it is unique per ORGANIZATION,
 * case-insensitively, so one tenant cannot probe another's handles. It is
 * nullable: an invited user has none until they choose one.
 *
 * Existing rows are backfilled as ../web did: the name split at its first
 * space; the username from the email's local part, numbered on a tie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('first_name')->default('');
            $table->string('last_name')->default('');
            $table->string('username', 32)->nullable();
        });

        DB::statement(<<<'SQL'
            update users set
                first_name = split_part(name, ' ', 1),
                last_name = trim(substring(name from length(split_part(name, ' ', 1)) + 1))
            SQL);
        DB::statement(<<<'SQL'
            with ranked as (
                select id, lower(split_part(email, '@', 1)) as base,
                    row_number() over (partition by organization_id, lower(split_part(email, '@', 1)) order by email) as rn
                from users
            )
            update users u set username = left(ranked.base, 28) || case when ranked.rn = 1 then '' else ranked.rn::text end
            from ranked where u.id = ranked.id
            SQL);

        DB::statement('create unique index users_organization_username_unique on users (organization_id, lower(username)) where username is not null');
    }

    public function down(): void
    {
        DB::statement('drop index if exists users_organization_username_unique');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'username']);
        });
    }
};
