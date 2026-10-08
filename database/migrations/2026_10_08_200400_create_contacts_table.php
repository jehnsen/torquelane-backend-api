<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * People at a customer account. At most one primary per account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('mobile', 32)->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->boolean('receives_invoices')->default(false);
            $table->boolean('receives_reminders')->default(false);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            // Target for consents, so a contact's consent cannot name another account.
            $table->unique(['id', 'customer_account_id']);
            $table->foreign(['customer_account_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('customer_accounts')
                ->restrictOnDelete();
        });

        DB::statement('create unique index contacts_one_primary_per_account on contacts (customer_account_id) where is_primary');
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
