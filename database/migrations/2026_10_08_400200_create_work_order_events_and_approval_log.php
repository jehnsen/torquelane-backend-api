<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The two append-only records of a work order (R7): its status history and
 * its approval log. Triggers refuse UPDATE, DELETE and TRUNCATE; a mistake is
 * answered by a later entry, never an edit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_events', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('work_order_id')->index();
            $table->string('status', 24);
            $table->timestampTz('at');
            $table->ulid('actor_id')->nullable();
            $table->string('actor_name');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
            $table->foreign(['actor_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });

        DB::statement("alter table work_order_events add constraint work_order_events_status_check check (status in ('draft', 'pending_approval', 'approved', 'partially_approved', 'scheduled', 'in_progress', 'closed', 'declined', 'cancelled'))");
        AppendOnly::protect('work_order_events');

        Schema::create('approval_log', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('work_order_id')->index();
            // Null for an order-level entry (sent, variance approved).
            $table->ulid('line_id')->nullable();
            $table->string('action', 24);
            $table->ulid('actor_id')->nullable();
            $table->string('actor_name');
            $table->timestampTz('at');
            $table->text('note')->nullable();
            // What the entry was about, in centavos, as it stood at the time.
            $table->cents('amount_at_time_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['work_order_id', 'organization_id'])->references(['id', 'organization_id'])->on('work_orders')->restrictOnDelete();
            $table->foreign('line_id')->references('id')->on('work_order_lines')->restrictOnDelete();
            $table->foreign(['actor_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });

        DB::statement("alter table approval_log add constraint approval_log_action_check check (action in ('sent_for_approval', 'auto_approved', 'approved', 'declined', 'deferred', 'escalated', 'variance_approved'))");
        AppendOnly::protect('approval_log');
    }

    public function down(): void
    {
        AppendOnly::unprotect('approval_log');
        Schema::dropIfExists('approval_log');
        AppendOnly::unprotect('work_order_events');
        Schema::dropIfExists('work_order_events');
    }
};
