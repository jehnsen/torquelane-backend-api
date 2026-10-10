<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detailing bookings (Phase 9): the source's `bookings`, `booking_day_slots`,
 * `booking_status_logs`, `blocked_slots` and `recurring_schedules`, per branch.
 *
 * Invariants the database holds:
 *  - a booking is for one customer account, in one branch, and carries the
 *    package, price, duration and size it was booked at (snapshots: the menu
 *    may change afterwards);
 *  - it reserves at least one head; its named detailers and its bay are of its
 *    own branch (composite keys);
 *  - `detailing_booking_slots` holds every (date, slot) the job touches, once,
 *    each a 30-minute slot of the grid; `released` rows count for nothing;
 *  - the status log is append-only (R7);
 *  - a (branch, date, slot) is blocked at most once;
 *  - a booking's reference comes from the `detailing_booking` series (R8) and
 *    is unique in the organization; bookings are never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detailing_bookings', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->ulid('vehicle_id')->nullable()->index();
            $table->string('reference', 32);
            $table->string('status', 16)->default('pending');
            $table->string('source', 16)->default('staff');

            // What was booked, as it stood then.
            $table->ulid('package_id');
            $table->string('package_name');
            $table->string('category_name')->nullable();
            $table->string('size_class', 8);
            $table->cents('package_price_cents');
            $table->string('duration_text', 64);
            $table->unsignedSmallInteger('min_detailers')->default(1);

            // The day and time ASKED for (Asia/Manila, R9). A start at or after
            // closing is planned from the next morning; the slots say where it sits.
            $table->date('scheduled_date');
            $table->unsignedSmallInteger('start_minutes');
            // Heads reserved against the pool; the named detailers are a subset.
            $table->unsignedSmallInteger('detailers_count')->default(1);
            $table->ulid('bay_id')->nullable();

            $table->string('customer_name');
            $table->string('customer_email')->nullable();
            $table->string('customer_phone', 32)->nullable();
            $table->string('vehicle_label')->nullable();
            $table->text('notes')->default('');
            $table->text('cancellation_reason')->nullable();

            // The job card.
            $table->jsonb('checklist')->default('[]');
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->text('inspection_override_reason')->nullable();
            $table->string('inspection_overridden_by_name')->nullable();
            $table->string('qc_status', 16)->nullable();
            $table->ulid('qc_by')->nullable();
            $table->string('qc_by_name')->nullable();
            $table->timestampTz('qc_at')->nullable();
            $table->text('qc_notes')->nullable();
            // Set once, when the package's materials were taken from stock.
            $table->timestampTz('materials_consumed_at')->nullable();
            $table->cents('materials_cost_cents')->nullable();

            // SHA-256 of the token in a customer's confirmation link (public bookings).
            $table->string('public_token_hash', 64)->nullable()->unique();
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'branch_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->index(['branch_id', 'scheduled_date']);
            $table->index(['branch_id', 'status']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['package_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_packages')->restrictOnDelete();
            $table->foreign(['bay_id', 'branch_id'])->references(['id', 'branch_id'])->on('bays')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign(['qc_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table detailing_bookings add constraint detailing_bookings_values_check check (
                status in ('pending', 'confirmed', 'on_going', 'completed', 'cancelled', 'no_show')
                and source in ('staff', 'public', 'recurring')
                and size_class in ('small', 'medium', 'large', 'xl')
                and package_price_cents >= 0
                and start_minutes between 0 and 1439
                and detailers_count >= 1 and min_detailers >= 1
                and jsonb_typeof(checklist) = 'array'
                and (qc_status is null or qc_status in ('passed', 'failed'))
                and ((qc_status is null) = (qc_at is null))
                and ((materials_consumed_at is null) = (materials_cost_cents is null))
            )
            SQL);

        Schema::create('detailing_booking_detailers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('booking_id')->index();
            $table->ulid('technician_id')->index();
            // The order they were named in: the first `detailers_count` are kept.
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['booking_id', 'technician_id']);
            $table->foreign(['booking_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_bookings')->cascadeOnDelete();
            $table->foreign(['technician_id', 'branch_id'])->references(['id', 'branch_id'])->on('technicians')->restrictOnDelete();
        });

        Schema::create('detailing_booking_slots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->ulid('booking_id')->index();
            $table->date('date');
            // The slot's start, minutes since midnight.
            $table->unsignedSmallInteger('slot');
            // A job finished early gives back what it had left; a re-opened one takes it back.
            $table->boolean('released')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['booking_id', 'date', 'slot']);
            $table->index(['branch_id', 'date', 'slot']);
            $table->foreign(['booking_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_bookings')->cascadeOnDelete();
        });
        DB::statement('alter table detailing_booking_slots add constraint detailing_booking_slots_grid_check check (slot between 0 and 1410 and slot % 30 = 0)');

        Schema::create('detailing_booking_addons', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('booking_id')->index();
            // Null for an extra typed in at the counter.
            $table->ulid('addon_id')->nullable();
            $table->string('name');
            $table->quantity('quantity')->default(1);
            $table->cents('unit_price_cents');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->foreign(['booking_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_bookings')->cascadeOnDelete();
            $table->foreign(['addon_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_addons')->restrictOnDelete();
        });
        DB::statement('alter table detailing_booking_addons add constraint detailing_booking_addons_values_check check (quantity > 0 and unit_price_cents >= 0)');

        Schema::create('detailing_booking_status_logs', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('booking_id');
            // Null on the row written when the booking was made.
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->text('notes')->nullable();
            $table->ulid('actor_id')->nullable();
            $table->string('actor_name');
            $table->timestampTz('changed_at');

            $table->index(['booking_id', 'changed_at']);
            $table->foreign(['booking_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_bookings')->restrictOnDelete();
            $table->foreign(['actor_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        AppendOnly::protect('detailing_booking_status_logs');

        Schema::create('detailing_blocked_slots', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->date('date');
            $table->unsignedSmallInteger('slot');
            $table->string('label')->default('Unavailable');
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->unique(['branch_id', 'date', 'slot']);
            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
        DB::statement('alter table detailing_blocked_slots add constraint detailing_blocked_slots_grid_check check (slot between 0 and 1410 and slot % 30 = 0)');

        Schema::create('detailing_recurring_schedules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->ulid('vehicle_id')->nullable();
            $table->ulid('package_id');
            $table->string('size_class', 8);
            // 0 = Sunday … 6 = Saturday, as in the source.
            $table->unsignedTinyInteger('day_of_week');
            $table->unsignedSmallInteger('start_minutes');
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['package_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_packages')->restrictOnDelete();
        });
        DB::statement("alter table detailing_recurring_schedules add constraint detailing_recurring_schedules_values_check check (day_of_week between 0 and 6 and start_minutes between 0 and 1439 and size_class in ('small', 'medium', 'large', 'xl'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('detailing_recurring_schedules');
        Schema::dropIfExists('detailing_blocked_slots');
        AppendOnly::unprotect('detailing_booking_status_logs');
        Schema::dropIfExists('detailing_booking_status_logs');
        Schema::dropIfExists('detailing_booking_addons');
        Schema::dropIfExists('detailing_booking_slots');
        Schema::dropIfExists('detailing_booking_detailers');
        Schema::dropIfExists('detailing_bookings');
    }
};
