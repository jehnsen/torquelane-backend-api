<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The consent ledger (append-only, R7). Current consent = the latest decision
 * per purpose (App\Domain\Crm\ConsentLedger); withdrawing appends a
 * `granted = false` row. A decision is the account's own (contact_id null) or
 * one contact's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id');
            $table->ulid('contact_id')->nullable();
            $table->string('purpose', 32);
            $table->boolean('granted');
            $table->string('channel', 16);
            $table->timestampTz('captured_at');
            // Null only for a system import (the demo seed).
            $table->ulid('captured_by')->nullable();
            $table->text('evidence')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['customer_account_id', 'purpose', 'captured_at']);
            $table->foreign(['customer_account_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('customer_accounts')
                ->restrictOnDelete();
            $table->foreign(['contact_id', 'customer_account_id'])
                ->references(['id', 'customer_account_id'])->on('contacts')
                ->restrictOnDelete();
            $table->foreign(['captured_by', 'organization_id'])
                ->references(['id', 'organization_id'])->on('users')
                ->restrictOnDelete();
        });

        DB::statement("alter table consents add constraint consents_purpose_check check (purpose in ('service_records', 'service_reminders', 'marketing', 'vehicle_history_sharing'))");
        DB::statement("alter table consents add constraint consents_channel_check check (channel in ('in_person', 'paper_form', 'email', 'sms', 'phone', 'portal', 'import'))");

        AppendOnly::protect('consents');
    }

    public function down(): void
    {
        AppendOnly::unprotect('consents');
        Schema::dropIfExists('consents');
    }
};
