<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CRM (Phase 11), relationships: leads, notes, tasks, feedback, segments and
 * campaigns.
 *
 * Invariants the database holds:
 *  - a lead is in one of the seven stages; one that is won names the customer
 *    account it became, and no other lead names it;
 *  - a note is about one customer account;
 *  - a task is about a customer account or a lead (at least one), is open,
 *    done or cancelled, and a done task says when;
 *  - an invoice is asked for feedback at most once; a score is 0 to 10 and
 *    comes with the moment it was given;
 *  - a segment's name is unique in its organization; its filters are an object;
 *  - a campaign names a segment and a template of its own organization.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->nullable();
            $table->string('company_name');
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('stage', 16)->default('new');
            $table->cents('value_cents')->default(0);
            $table->ulid('owner_id')->nullable();
            $table->string('next_step')->nullable();
            $table->date('next_step_on')->nullable();
            $table->string('source', 64)->nullable();
            // A dealer sends vehicles in batches for reconditioning.
            $table->boolean('is_dealer')->default(false);
            $table->text('notes')->nullable();
            $table->text('lost_reason')->nullable();
            $table->ulid('customer_account_id')->nullable()->unique();
            $table->timestampTz('stage_changed_at');
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->index(['organization_id', 'stage']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['owner_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table crm_leads add constraint crm_leads_values_check check (
                stage in ('new', 'contacted', 'site_visit', 'quote_sent', 'negotiating', 'won', 'lost')
                and value_cents >= 0
                and (stage <> 'won' or customer_account_id is not null)
                and length(btrim(company_name)) > 0
            )
            SQL);

        Schema::create('crm_notes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->ulid('vehicle_id')->nullable();
            $table->text('body');
            $table->boolean('pinned')->default(false);
            $table->ulid('author_id')->nullable();
            $table->string('author_name');
            $table->timestampsTz();

            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['author_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement('alter table crm_notes add constraint crm_notes_body_check check (length(btrim(body)) > 0)');

        Schema::create('crm_tasks', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->nullable()->index();
            $table->ulid('lead_id')->nullable()->index();
            $table->ulid('vehicle_id')->nullable();
            $table->string('title');
            $table->text('details')->nullable();
            $table->string('kind', 24)->default('follow_up');
            $table->ulid('assignee_id')->nullable()->index();
            $table->date('due_on')->nullable();
            $table->timestampTz('remind_at')->nullable();
            // Set once, when the assignee was told.
            $table->timestampTz('reminded_at')->nullable();
            $table->string('status', 12)->default('open');
            $table->timestampTz('completed_at')->nullable();
            $table->string('completed_by_name')->nullable();
            // The reminder message a booking request answered.
            $table->ulid('source_message_id')->nullable();
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'due_on']);
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['lead_id', 'organization_id'])->references(['id', 'organization_id'])->on('crm_leads')->cascadeOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['assignee_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign(['source_message_id', 'organization_id'])->references(['id', 'organization_id'])->on('messages')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table crm_tasks add constraint crm_tasks_values_check check (
                (customer_account_id is not null or lead_id is not null)
                and kind in ('follow_up', 'call', 'booking_request', 'other')
                and status in ('open', 'done', 'cancelled')
                and ((status = 'done') = (completed_at is not null))
                and length(btrim(title)) > 0
            )
            SQL);

        Schema::create('feedback_requests', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('customer_account_id')->index();
            $table->ulid('invoice_id')->nullable()->unique();
            // The job it is about: a work order or a detailing booking.
            $table->ulid('work_order_id')->nullable();
            $table->ulid('detailing_booking_id')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->ulid('message_id')->nullable();
            $table->timestampTz('requested_at');
            $table->timestampTz('responded_at')->nullable();
            $table->unsignedSmallInteger('score')->nullable();
            $table->text('comment')->nullable();
            $table->timestampsTz();

            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['invoice_id', 'organization_id'])->references(['id', 'organization_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
            $table->foreign(['detailing_booking_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_bookings')->restrictOnDelete();
            $table->foreign(['message_id', 'organization_id'])->references(['id', 'organization_id'])->on('messages')->restrictOnDelete();
        });
        DB::statement('alter table feedback_requests add constraint feedback_requests_score_check check ((score is null or score between 0 and 10) and ((score is null) = (responded_at is null)))');

        Schema::create('crm_segments', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->jsonb('filters')->default('{}');
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
        });
        DB::statement('create unique index crm_segments_name_unique on crm_segments (organization_id, lower(name))');
        DB::statement("alter table crm_segments add constraint crm_segments_filters_check check (jsonb_typeof(filters) = 'object')");

        Schema::create('crm_campaigns', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->ulid('segment_id');
            $table->ulid('template_id');
            $table->string('status', 12)->default('draft');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            // Null = the organization's limit.
            $table->unsignedInteger('hourly_limit')->nullable();
            // The accounts the segment held when sending began; null until then.
            $table->jsonb('audience')->nullable();
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->index(['organization_id', 'status', 'scheduled_at']);
            $table->foreign(['segment_id', 'organization_id'])->references(['id', 'organization_id'])->on('crm_segments')->restrictOnDelete();
            $table->foreign(['template_id', 'organization_id'])->references(['id', 'organization_id'])->on('message_templates')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table crm_campaigns add constraint crm_campaigns_values_check check (
                status in ('draft', 'scheduled', 'sending', 'sent', 'cancelled')
                and (status not in ('scheduled', 'sending') or scheduled_at is not null)
                and (hourly_limit is null or hourly_limit >= 1)
                and (audience is null or jsonb_typeof(audience) = 'array')
            )
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_campaigns');
        Schema::dropIfExists('crm_segments');
        Schema::dropIfExists('feedback_requests');
        Schema::dropIfExists('crm_tasks');
        Schema::dropIfExists('crm_notes');
        Schema::dropIfExists('crm_leads');
    }
};
