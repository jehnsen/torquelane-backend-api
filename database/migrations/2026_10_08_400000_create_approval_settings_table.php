<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approval and billing settings (App\Domain\Approvals\ApprovalSettings).
 *
 *   branch_id null      the organization's defaults: every field set (CHECKed)
 *   branch_id set       a sparse branch override: a null field inherits
 *
 * The third level, a customer account's override, stays on
 * customer_accounts.approval_threshold_overrides (Phase 1). An unset field
 * inherits at every level and is never read as zero; a 0% VAT rate is a real
 * value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->cents('auto_approve_under_cents')->nullable();
            $table->cents('ops_approval_under_cents')->nullable();
            $table->unsignedSmallInteger('sla_hours')->nullable();
            $table->rate('variance_threshold_pct')->nullable();
            $table->string('default_parts_source', 24)->nullable();
            $table->cents('monthly_budget_cents')->nullable();
            $table->rate('vat_rate_pct')->nullable();
            $table->cents('misc_fee_flat_cents')->nullable();
            $table->cents('default_labour_rate_cents')->nullable();
            $table->timestampsTz();

            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });

        DB::statement('create unique index approval_settings_scope_unique on approval_settings (organization_id, branch_id) nulls not distinct');
        DB::statement(<<<'SQL'
            alter table approval_settings add constraint approval_settings_defaults_complete check (
                branch_id is not null or (
                    auto_approve_under_cents is not null and ops_approval_under_cents is not null
                    and sla_hours is not null and variance_threshold_pct is not null
                    and default_parts_source is not null and monthly_budget_cents is not null
                    and vat_rate_pct is not null and misc_fee_flat_cents is not null
                    and default_labour_rate_cents is not null
                )
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table approval_settings add constraint approval_settings_values_check check (
                coalesce(auto_approve_under_cents, 0) >= 0 and coalesce(ops_approval_under_cents, 0) >= 0
                and coalesce(variance_threshold_pct, 0) >= 0 and coalesce(monthly_budget_cents, 0) >= 0
                and coalesce(vat_rate_pct, 0) between 0 and 100 and coalesce(misc_fee_flat_cents, 0) >= 0
                and coalesce(default_labour_rate_cents, 0) >= 0
                and (default_parts_source is null or default_parts_source in ('own_stock', 'supplier_provided'))
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_settings');
    }
};
