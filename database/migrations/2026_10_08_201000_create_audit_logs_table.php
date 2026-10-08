<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The audit trail (append-only, R7). No foreign key to the audited entity and
 * no cascades: the record of a change outlives whatever it describes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->timestampTz('occurred_at');
            $table->string('request_id', 128)->nullable();
            // Null for a system actor (seeder, scheduled job).
            $table->ulid('actor_id')->nullable();
            $table->string('actor_role', 32)->nullable();
            $table->foreignUlid('organization_id')->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('customer_account_id')->nullable();
            $table->string('entity_type', 64);
            $table->ulid('entity_id');
            $table->string('action', 64);
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();

            $table->index(['organization_id', 'occurred_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['customer_account_id', 'occurred_at']);
        });

        AppendOnly::protect('audit_logs');
    }

    public function down(): void
    {
        AppendOnly::unprotect('audit_logs');
        Schema::dropIfExists('audit_logs');
    }
};
