<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Users become tenant-owned: one organization, one side, one role, and for
 * portal users exactly one customer account. The CHECKs make the invite rules
 * structural (portal ⇔ pinned to an account; role matches side), and the
 * composite foreign key makes "an account from another organization"
 * unrepresentable.
 *
 * Phase 0A users carried none of this; any that exist cannot be placed in an
 * organization automatically, so the migration refuses rather than guessing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('users')->exists()) {
            throw new RuntimeException('users has rows that predate organizations. Locally: php artisan migrate:fresh --seed.');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('side', 16);
            $table->ulid('customer_account_id')->nullable()->index();
            $table->string('role', 32);
            $table->string('title')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampTz('last_login_at')->nullable();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['customer_account_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('customer_accounts')
                ->restrictOnDelete();
        });

        DB::statement("alter table users add constraint users_side_check check (side in ('staff', 'portal'))");
        DB::statement("alter table users add constraint users_status_check check (status in ('active', 'disabled'))");
        DB::statement(<<<'SQL'
            alter table users add constraint users_role_side_check check (
                (side = 'staff' and role in ('provider_admin', 'service_advisor', 'provider_technician', 'branch_manager', 'cashier'))
                or (side = 'portal' and role in ('fleet_manager', 'operations', 'technician', 'purchasing_officer', 'viewer'))
            )
            SQL);
        DB::statement("alter table users add constraint users_portal_account_check check ((side = 'portal') = (customer_account_id is not null))");

        // Staff branch restrictions. No rows for a user = every branch.
        Schema::create('branch_user', function (Blueprint $table) {
            $table->ulid('organization_id')->index();
            $table->ulid('branch_id');
            $table->ulid('user_id')->index();
            $table->timestampTz('created_at')->useCurrent();

            $table->primary(['branch_id', 'user_id']);
            $table->foreign('organization_id')->references('id')->on('organizations')->restrictOnDelete();
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->cascadeOnDelete();
            $table->foreign(['user_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_user');

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_account_id', 'organization_id']);
            $table->dropUnique(['id', 'organization_id']);
            $table->dropConstrainedForeignId('organization_id');
        });
        DB::statement('alter table users drop constraint if exists users_side_check, drop constraint if exists users_status_check, drop constraint if exists users_role_side_check, drop constraint if exists users_portal_account_check');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['side', 'customer_account_id', 'role', 'title', 'status', 'last_login_at']);
        });
    }
};
