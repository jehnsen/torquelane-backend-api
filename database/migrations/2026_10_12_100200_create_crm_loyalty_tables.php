<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (Phase 11), loyalty: membership tiers and memberships, prepaid package
 * plans, the packages sold and their redemptions, and the loyalty points
 * ledger. Also how often a detailing package is due again.
 *
 * Invariants the database holds:
 *  - a tier's name is unique in its organization; its discount is 0 to 100%;
 *  - a customer account holds at most one active membership;
 *  - a plan and a package sold cost something and carry at least one credit;
 *    a package never has more credits used than it was sold with;
 *  - a package's reference comes from the `prepaid_package` series (R8);
 *  - a redemption is append-only (R7); a booking is settled by at most one;
 *  - the points ledger is append-only; a payment earns points once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_tiers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->decimal('discount_pct', 5, 2)->default(0);
            $table->boolean('priority_booking')->default(false);
            // Detailing add-ons a member gets free, by id.
            $table->jsonb('free_addon_ids')->default('[]');
            $table->text('perks')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
        });
        DB::statement('create unique index membership_tiers_name_unique on membership_tiers (organization_id, lower(name))');
        DB::statement("alter table membership_tiers add constraint membership_tiers_values_check check (discount_pct between 0 and 100 and jsonb_typeof(free_addon_ids) = 'array')");

        Schema::create('memberships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->ulid('tier_id');
            $table->date('started_on');
            $table->date('expires_on')->nullable();
            $table->string('status', 12)->default('active');
            $table->text('notes')->nullable();
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['tier_id', 'organization_id'])->references(['id', 'organization_id'])->on('membership_tiers')->restrictOnDelete();
        });
        DB::statement("alter table memberships add constraint memberships_values_check check (status in ('active', 'cancelled') and (expires_on is null or expires_on >= started_on))");
        DB::statement("create unique index memberships_one_active on memberships (customer_account_id) where status = 'active'");

        Schema::create('prepaid_plans', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->cents('price_cents');
            $table->unsignedSmallInteger('credits');
            // What one credit pays for; null = any detailing package.
            $table->ulid('detailing_package_id')->nullable();
            $table->unsignedSmallInteger('valid_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['detailing_package_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_packages')->restrictOnDelete();
        });
        DB::statement('create unique index prepaid_plans_name_unique on prepaid_plans (organization_id, lower(name))');
        DB::statement('alter table prepaid_plans add constraint prepaid_plans_values_check check (price_cents > 0 and credits >= 1 and (valid_days is null or valid_days >= 1))');

        Schema::create('prepaid_packages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('customer_account_id')->index();
            $table->ulid('plan_id');
            $table->string('reference', 32);
            // As sold: the plan may change afterwards.
            $table->string('plan_name');
            $table->cents('price_cents');
            $table->unsignedSmallInteger('credits_total');
            $table->unsignedSmallInteger('credits_used')->default(0);
            $table->ulid('detailing_package_id')->nullable();
            $table->date('sold_on');
            $table->date('expires_on')->nullable();
            $table->string('payment_method', 16);
            $table->string('payment_reference')->nullable();
            $table->ulid('sold_by')->nullable();
            $table->string('sold_by_name');
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['plan_id', 'organization_id'])->references(['id', 'organization_id'])->on('prepaid_plans')->restrictOnDelete();
            $table->foreign(['detailing_package_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_packages')->restrictOnDelete();
            $table->foreign(['sold_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table prepaid_packages add constraint prepaid_packages_values_check check (
                price_cents > 0 and credits_total >= 1 and credits_used between 0 and credits_total
                and payment_method in ('cash', 'gcash', 'maya', 'card', 'bank_transfer', 'check')
                and (expires_on is null or expires_on >= sold_on)
            )
            SQL);

        Schema::create('prepaid_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('prepaid_package_id')->index();
            $table->ulid('branch_id');
            $table->ulid('detailing_booking_id')->nullable()->unique();
            $table->unsignedSmallInteger('credits')->default(1);
            // The share of the package's price this redemption earns.
            $table->cents('amount_cents');
            $table->date('redeemed_on');
            $table->string('redeemed_by_name');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['prepaid_package_id', 'organization_id'])->references(['id', 'organization_id'])->on('prepaid_packages')->restrictOnDelete();
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['detailing_booking_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_bookings')->restrictOnDelete();
        });
        DB::statement('alter table prepaid_redemptions add constraint prepaid_redemptions_values_check check (credits >= 1 and amount_cents >= 0)');
        AppendOnly::protect('prepaid_redemptions');

        Schema::create('loyalty_entries', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->integer('points');
            $table->string('reason', 12);
            $table->ulid('payment_id')->nullable();
            $table->string('note')->nullable();
            $table->string('created_by_name')->default('System');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['payment_id', 'organization_id'])->references(['id', 'organization_id'])->on('payments')->restrictOnDelete();
        });
        DB::statement("alter table loyalty_entries add constraint loyalty_entries_values_check check (points <> 0 and reason in ('earned', 'reversed', 'redeemed', 'adjusted'))");
        DB::statement('create unique index loyalty_entries_payment_unique on loyalty_entries (payment_id, reason) where payment_id is not null');
        AppendOnly::protect('loyalty_entries');

        // How many days after a job this package is due again (a wash every 14 days, a coating top-up every 180).
        Schema::table('detailing_packages', function (Blueprint $table) {
            $table->unsignedSmallInteger('repeat_interval_days')->nullable();
        });

        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment', 'detailing_booking', 'prepaid_package'))");
    }

    public function down(): void
    {
        DB::statement('alter table document_series drop constraint document_series_doc_type_check');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry', 'shop_purchase_order', 'stock_transfer', 'stock_count', 'payment', 'detailing_booking'))");
        Schema::table('detailing_packages', function (Blueprint $table) {
            $table->dropColumn('repeat_interval_days');
        });
        AppendOnly::unprotect('loyalty_entries');
        Schema::dropIfExists('loyalty_entries');
        AppendOnly::unprotect('prepaid_redemptions');
        Schema::dropIfExists('prepaid_redemptions');
        Schema::dropIfExists('prepaid_packages');
        Schema::dropIfExists('prepaid_plans');
        Schema::dropIfExists('memberships');
        Schema::dropIfExists('membership_tiers');
    }
};
