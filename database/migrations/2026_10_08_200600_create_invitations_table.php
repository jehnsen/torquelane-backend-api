<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pending staff and portal invitations. A user row exists only once an
 * invitation is accepted, so there is never a passwordless user. The token is
 * stored as its SHA-256; the plain token exists only in the email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invitations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('email');
            $table->string('name');
            $table->string('side', 16);
            $table->string('role', 32);
            $table->string('title')->nullable();
            $table->ulid('customer_account_id')->nullable();
            // Staff branch restriction to apply on acceptance; [] = every branch.
            $table->jsonb('branch_ids')->default('[]');
            $table->char('token_hash', 64)->unique();
            $table->ulid('invited_by');
            $table->timestampTz('expires_at');
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['organization_id', 'email']);
            $table->foreign(['customer_account_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('customer_accounts')
                ->restrictOnDelete();
            $table->foreign(['invited_by', 'organization_id'])
                ->references(['id', 'organization_id'])->on('users')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            alter table invitations add constraint invitations_role_side_check check (
                (side = 'staff' and role in ('provider_admin', 'service_advisor', 'provider_technician', 'branch_manager', 'cashier') and customer_account_id is null)
                or (side = 'portal' and role in ('fleet_manager', 'operations', 'technician', 'purchasing_officer', 'viewer') and customer_account_id is not null and branch_ids = '[]'::jsonb)
            )
            SQL);
        DB::statement("alter table invitations add constraint invitations_branch_ids_check check (jsonb_typeof(branch_ids) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
