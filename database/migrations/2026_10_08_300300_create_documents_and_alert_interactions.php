<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents and per-user alert read/dismiss state.
 *
 * A document belongs to a customer account (`customer_account_id`, ALWAYS
 * the account that owned the subject when it was filed) and optionally to a
 * vehicle. The file lives on the private `documents` disk at `storage_path`;
 * rows imported without a file (the demo seed's paper trail) have none.
 * Only renewal kinds may carry `expires_on`.
 *
 * Alert interactions: alerts themselves are derived and never stored; this is
 * the reader's bookkeeping, keyed by the deterministic alert id and bucketed
 * per scope key, so staff and portal dismissals never share a bucket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->ulid('vehicle_id')->nullable()->index();
            $table->string('kind', 32);
            $table->string('name');
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_path', 1024)->nullable();
            $table->date('expires_on')->nullable();
            $table->string('reference_number')->nullable();
            $table->date('issued_on')->nullable();
            $table->string('issuing_body')->nullable();
            $table->text('notes')->nullable();
            $table->ulid('uploaded_by')->nullable();
            // Historical label: survives the uploader's account, and names
            // imported rows that have no user.
            $table->string('uploaded_by_name')->nullable();
            $table->date('uploaded_on');
            $table->timestampsTz();

            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['uploaded_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });

        DB::statement("alter table documents add constraint documents_kind_check check (kind in ('invoice', 'service_report', 'inspection', 'lto_registration', 'ctpl', 'comprehensive_insurance', 'emission_test', 'ltfrb_franchise', 'warranty', 'photo', 'other'))");
        DB::statement("alter table documents add constraint documents_expiry_check check (expires_on is null or kind in ('lto_registration', 'ctpl', 'comprehensive_insurance', 'emission_test', 'ltfrb_franchise', 'warranty'))");

        Schema::create('alert_interactions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('user_id');
            // TenantScope::key(): organization:<id> or account:<id>.
            $table->string('scope_key', 64);
            $table->string('alert_id', 200);
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampsTz();

            $table->unique(['user_id', 'scope_key', 'alert_id']);
            $table->foreign(['user_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_interactions');
        Schema::dropIfExists('documents');
    }
};
