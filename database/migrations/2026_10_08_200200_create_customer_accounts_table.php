<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The frontend's "fleet client", generalised: companies with fleets, and
 * individuals (walk-ins, VIP members). Accounts are never deleted; they are
 * suspended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_accounts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('account_type', 16);
            $table->string('display_name');

            // Company fields.
            $table->string('registered_name')->nullable();
            $table->string('tin', 11)->nullable();
            $table->text('address')->nullable();

            // Individual fields.
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('nickname')->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('email')->nullable();
            $table->date('birthday')->nullable();

            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->unsignedSmallInteger('payment_terms_days')->default(0);
            $table->cents('credit_limit_cents')->nullable();
            // Sparse: a key here wins, a missing key inherits the organization's
            // default. NULL (inherit everything) is kept distinct from {}.
            $table->jsonb('approval_threshold_overrides')->nullable();
            $table->jsonb('tags')->default('[]');
            $table->string('source', 64)->nullable();
            $table->text('notes')->nullable();
            // Portal branding; null inherits the organization's, field by field.
            $table->string('logo_url', 2048)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'display_name']);
        });

        DB::statement("alter table customer_accounts add constraint customer_accounts_type_check check (account_type in ('company', 'individual'))");
        DB::statement("alter table customer_accounts add constraint customer_accounts_status_check check (status in ('active', 'suspended'))");
        DB::statement("alter table customer_accounts add constraint customer_accounts_overrides_check check (approval_threshold_overrides is null or jsonb_typeof(approval_threshold_overrides) = 'object')");
        DB::statement("alter table customer_accounts add constraint customer_accounts_tags_check check (jsonb_typeof(tags) = 'array')");
        DB::statement('alter table customer_accounts add constraint customer_accounts_credit_limit_check check (credit_limit_cents is null or credit_limit_cents >= 0)');
        DB::statement("alter table customer_accounts add constraint customer_accounts_brand_color_check check (brand_color is null or brand_color ~ '^#[0-9a-fA-F]{6}$')");
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_accounts');
    }
};
