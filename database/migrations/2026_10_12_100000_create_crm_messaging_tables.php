<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (Phase 11), messaging: the organization's CRM settings, message
 * templates, reminder rules, the message log and the suppression list.
 *
 * Invariants the database holds:
 *  - an organization has at most one settings row; quiet hours are minutes of
 *    the Manila day; cooldowns, limits and rates are positive;
 *  - a template's name is unique in its organization whatever its case; its
 *    channel and purpose are of the known lists;
 *  - there is at most one reminder rule of a kind per organization;
 *  - a message is to one customer account, on one channel, for one purpose;
 *    a skipped message says why; one that counts for dedupe (`dedupe_key`)
 *    exists at most once per business day unless it was skipped;
 *  - a public link's token is stored as its SHA-256 only, and is unique;
 *  - an address is suppressed at most once per channel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_settings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->unique()->constrained()->restrictOnDelete();
            // No reminder, campaign or feedback message leaves between these (Asia/Manila, R9).
            $table->unsignedSmallInteger('quiet_start_minutes')->default(1260);
            $table->unsignedSmallInteger('quiet_end_minutes')->default(480);
            // The same reminder is not repeated to the same subject within this many days.
            $table->unsignedSmallInteger('reminder_cooldown_days')->default(14);
            // A customer with no visit for this long is "lapsed".
            $table->unsignedSmallInteger('lapsed_after_days')->default(180);
            $table->unsignedInteger('campaign_hourly_limit')->default(200);
            $table->boolean('feedback_enabled')->default(true);
            $table->boolean('loyalty_enabled')->default(false);
            // Centavos paid per point earned (10000 = one point per ₱100).
            $table->unsignedInteger('loyalty_cents_per_point')->default(10000);
            $table->timestampsTz();
        });
        DB::statement(<<<'SQL'
            alter table crm_settings add constraint crm_settings_values_check check (
                quiet_start_minutes between 0 and 1439 and quiet_end_minutes between 0 and 1439
                and reminder_cooldown_days >= 1 and lapsed_after_days >= 1
                and campaign_hourly_limit >= 1 and loyalty_cents_per_point >= 1
            )
            SQL);

        Schema::create('message_templates', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('channel', 8)->default('email');
            $table->string('purpose', 24);
            $table->string('subject')->nullable();
            // Placeholders in double braces: {{customer_name}}, {{link}}, …
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
        });
        DB::statement('create unique index message_templates_name_unique on message_templates (organization_id, lower(name))');
        DB::statement("alter table message_templates add constraint message_templates_values_check check (channel in ('email', 'sms') and purpose in ('service_reminder', 'marketing', 'feedback', 'transactional') and length(btrim(body)) > 0)");

        Schema::create('reminder_rules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('kind', 24);
            $table->boolean('enabled')->default(true);
            $table->ulid('template_id');
            // Document expiry: how many days ahead. Null where the kind has no lead time.
            $table->unsignedSmallInteger('lead_days')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'kind']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['template_id', 'organization_id'])->references(['id', 'organization_id'])->on('message_templates')->restrictOnDelete();
        });
        DB::statement("alter table reminder_rules add constraint reminder_rules_kind_check check (kind in ('pms_due', 'detailing_repeat', 'document_expiry', 'lapsed_customer'))");

        Schema::create('messages', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->ulid('customer_account_id')->index();
            $table->ulid('contact_id')->nullable();
            $table->string('channel', 8);
            $table->string('purpose', 24);
            $table->ulid('template_id')->nullable();
            $table->string('to_address')->nullable();
            $table->string('to_name')->nullable();
            $table->string('subject')->nullable();
            $table->text('body');
            $table->string('status', 12)->default('queued');
            $table->string('skip_reason', 32)->nullable();
            // What produced it (a reminder rule, a campaign, a feedback request, a task) and what it is about.
            $table->string('source_type', 16);
            $table->ulid('source_id')->nullable();
            $table->string('subject_type', 24)->nullable();
            $table->ulid('subject_id')->nullable();
            // Same key = the same reminder about the same thing; `dedupe_on` is the Manila day it was queued.
            $table->string('dedupe_key', 120)->nullable();
            $table->date('dedupe_on')->nullable();
            // SHA-256 of the token in the message's own link (booking, feedback, unsubscribe).
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestampTz('send_after')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->text('failed_reason')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->string('created_by_name')->default('System');
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->index(['customer_account_id', 'created_at']);
            $table->index(['organization_id', 'source_type', 'source_id']);
            $table->index(['organization_id', 'dedupe_key', 'created_at']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['template_id', 'organization_id'])->references(['id', 'organization_id'])->on('message_templates')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table messages add constraint messages_values_check check (
                channel in ('email', 'sms')
                and purpose in ('service_reminder', 'marketing', 'feedback', 'transactional')
                and status in ('queued', 'sent', 'failed', 'skipped')
                and source_type in ('reminder', 'campaign', 'feedback', 'task', 'manual')
                and ((status = 'skipped') = (skip_reason is not null))
                and ((dedupe_key is null) = (dedupe_on is null))
            )
            SQL);
        // A contact removed leaves its messages in the log, addressed as they were sent.
        DB::statement('alter table messages add constraint messages_contact_foreign foreign key (contact_id, organization_id) references contacts (id, organization_id) on delete set null (contact_id)');
        DB::statement("create unique index messages_dedupe_unique on messages (organization_id, dedupe_key, dedupe_on) where dedupe_key is not null and status <> 'skipped'");

        Schema::create('message_suppressions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('channel', 8);
            // Lower-cased email, or digits-only mobile.
            $table->string('address');
            $table->string('reason', 24)->default('unsubscribed');
            $table->ulid('customer_account_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['organization_id', 'channel', 'address']);
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
        });
        DB::statement("alter table message_suppressions add constraint message_suppressions_channel_check check (channel in ('email', 'sms'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('message_suppressions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('reminder_rules');
        Schema::dropIfExists('message_templates');
        Schema::dropIfExists('crm_settings');
    }
};
