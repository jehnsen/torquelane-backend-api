<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage for EnforceIdempotency. Infrastructure, keyed per user rather than
 * per organization, so it carries no organization_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            // sha256 of "METHOD path": the route the key was spent on.
            $table->char('scope_hash', 64);
            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);
            $table->smallInteger('response_status')->nullable();
            $table->jsonb('response_headers')->nullable();
            $table->text('response_body')->nullable();
            $table->timestampTz('created_at');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('expires_at')->index();

            $table->unique(['user_id', 'scope_hash', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
