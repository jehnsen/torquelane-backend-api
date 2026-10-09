<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock counts and inter-branch transfers (Phase 6).
 *
 * A count is a sheet for one location: counted quantities are entered while
 * it is open, then posting it turns every variance into an adjustment move
 * with a reason. A transfer is ONE document that moves goods out of one
 * location and into another (both moves, one transaction). Both are issued
 * stock documents (R7): a posted count's lines and a transfer never change;
 * a transfer is corrected by a reversing transfer that names it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('location_id');
            $table->string('reference', 32);
            $table->string('status', 12)->default('open');
            // The reason every variance without its own is posted under.
            $table->text('reason')->nullable();
            $table->text('notes')->default('');
            $table->date('created_on');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampTz('posted_at')->nullable();
            $table->string('posted_by_name')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->string('cancelled_by_name')->nullable();
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['location_id', 'branch_id'])->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table stock_counts add constraint stock_counts_status_check check (
                status in ('open', 'posted', 'cancelled')
                and ((status = 'posted') = (posted_at is not null))
                and ((status = 'cancelled') = (cancelled_at is not null))
            )
            SQL);

        Schema::create('stock_count_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('stock_count_id');
            $table->ulid('item_id');
            // What the books held: when the sheet was drawn, then refreshed at posting.
            $table->quantity('expected_quantity');
            $table->quantity('counted_quantity')->nullable();
            // Counted − expected, fixed at posting.
            $table->quantity('variance_quantity')->nullable();
            $table->cents('unit_cost_cents')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->unique(['stock_count_id', 'item_id']);
            $table->foreign(['stock_count_id', 'organization_id'])->references(['id', 'organization_id'])->on('stock_counts')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
        });
        DB::statement('alter table stock_count_lines add constraint stock_count_lines_values_check check (coalesce(counted_quantity, 0) >= 0)');

        // A count sheet is edited only while it is open.
        DB::unprepared(<<<'SQL'
            create or replace function stock_count_lines_open_only() returns trigger language plpgsql as $$
            declare
                parent_status text;
                parent_id char(26);
            begin
                parent_id := case when tg_op = 'INSERT' then new.stock_count_id else old.stock_count_id end;
                select status into parent_status from stock_counts where id = parent_id;
                if parent_status is not null and parent_status <> 'open' then
                    raise exception 'a count that has been posted or cancelled is not edited' using errcode = 'restrict_violation';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end
            $$;
            create trigger stock_count_lines_open_only
                before insert or update or delete on stock_count_lines
                for each row execute function stock_count_lines_open_only();

            create or replace function stock_counts_keep_posted() returns trigger language plpgsql as $$
            begin
                if tg_op = 'DELETE' then
                    raise exception 'stock counts are never deleted; cancel instead' using errcode = 'restrict_violation';
                end if;
                if old.status <> 'open' then
                    raise exception 'stock count % is %; it does not change', old.reference, old.status using errcode = 'restrict_violation';
                end if;
                return new;
            end
            $$;
            create trigger stock_counts_keep_posted
                before update or delete on stock_counts
                for each row execute function stock_counts_keep_posted();
            SQL);

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('reference', 32);
            $table->ulid('from_branch_id')->index();
            $table->ulid('from_location_id');
            $table->ulid('to_branch_id')->index();
            $table->ulid('to_location_id');
            // The transfer this one undoes, if it is a reversal (at most one reversal each).
            $table->ulid('reverses_transfer_id')->nullable()->unique();
            $table->text('notes')->default('');
            $table->date('transferred_on');
            $table->ulid('created_by')->nullable();
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->unique(['organization_id', 'reference']);
            $table->unique(['id', 'organization_id']);
            $table->foreign(['from_location_id', 'from_branch_id'])->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['to_location_id', 'to_branch_id'])->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['created_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign(['reverses_transfer_id', 'organization_id'])->references(['id', 'organization_id'])->on('stock_transfers')->restrictOnDelete();
        });
        DB::statement('alter table stock_transfers add constraint stock_transfers_locations_check check (from_location_id <> to_location_id)');
        AppendOnly::protect('stock_transfers');

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('stock_transfer_id')->index();
            $table->unsignedSmallInteger('position')->default(0);
            $table->ulid('item_id');
            $table->quantity('quantity');
            // What it left the source at (its average); the destination takes it in at the same cost.
            $table->cents('unit_cost_cents');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign(['stock_transfer_id', 'organization_id'])->references(['id', 'organization_id'])->on('stock_transfers')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
        });
        DB::statement('alter table stock_transfer_lines add constraint stock_transfer_lines_values_check check (quantity > 0 and unit_cost_cents >= 0)');
        AppendOnly::protect('stock_transfer_lines');
    }

    public function down(): void
    {
        AppendOnly::unprotect('stock_transfer_lines');
        Schema::dropIfExists('stock_transfer_lines');
        AppendOnly::unprotect('stock_transfers');
        Schema::dropIfExists('stock_transfers');
        DB::unprepared('drop trigger if exists stock_counts_keep_posted on stock_counts; drop function if exists stock_counts_keep_posted();');
        DB::unprepared('drop trigger if exists stock_count_lines_open_only on stock_count_lines; drop function if exists stock_count_lines_open_only();');
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_counts');
    }
};
