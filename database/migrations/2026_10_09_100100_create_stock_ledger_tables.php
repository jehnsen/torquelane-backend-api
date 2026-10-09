<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The stock ledger (Phase 6): signed, append-only stock moves, and the
 * balance each (location, item) pair stands at.
 *
 * Invariants the database holds, so a bug in the application cannot break them:
 *  - `stock_moves` is append-only (R7): a correction is a compensating move;
 *  - a move's sign agrees with its type, it moves something, an adjustment
 *    says why, and its source is named;
 *  - `stock_balances` change ONLY through the stock ledger: a row trigger
 *    refuses any write that is not inside a transaction where the ledger has
 *    switched on `torquelane.stock_ledger` (PostStockMove does, with the
 *    balance row locked);
 *  - at commit, every touched balance equals the sum of its moves
 *    (deferred constraint triggers), so stock can only ever change through
 *    moves.
 *
 * Both tables carry the branch of their location (composite FK), so scoping
 * and the isolation suite need no join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('location_id');
            $table->ulid('item_id')->index();
            $table->quantity('on_hand')->default(0);
            $table->cents('avg_cost_cents')->default(0);
            $table->timestampsTz();

            $table->unique(['location_id', 'item_id']);
            $table->foreign(['location_id', 'branch_id'])->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['location_id', 'organization_id'])->references(['id', 'organization_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
        });
        DB::statement('alter table stock_balances add constraint stock_balances_cost_check check (avg_cost_cents >= 0)');

        Schema::create('stock_moves', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('location_id');
            $table->ulid('item_id');
            $table->quantity('quantity');
            $table->cents('unit_cost_cents');
            $table->string('move_type', 16);
            $table->string('source_type', 24);
            $table->ulid('source_id')->nullable();
            $table->timestampTz('occurred_at');
            $table->ulid('actor_id')->nullable();
            $table->string('actor_name');
            $table->text('reason')->nullable();
            $table->boolean('negative_flag')->default(false);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['location_id', 'item_id', 'occurred_at']);
            $table->index(['item_id', 'occurred_at']);
            $table->index(['source_type', 'source_id']);
            $table->foreign(['location_id', 'branch_id'])->references(['id', 'branch_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['location_id', 'organization_id'])->references(['id', 'organization_id'])->on('stock_locations')->restrictOnDelete();
            $table->foreign(['item_id', 'organization_id'])->references(['id', 'organization_id'])->on('items')->restrictOnDelete();
            $table->foreign(['actor_id', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
        });
        DB::statement("alter table stock_moves add constraint stock_moves_type_check check (move_type in ('opening', 'receipt', 'issue', 'return', 'adjustment', 'transfer_out', 'transfer_in', 'consumption'))");
        DB::statement("alter table stock_moves add constraint stock_moves_source_check check (source_type in ('manual', 'goods_receipt', 'work_order_line', 'stock_count', 'stock_transfer') and (source_type = 'manual' or source_id is not null))");
        DB::statement(<<<'SQL'
            alter table stock_moves add constraint stock_moves_quantity_check check (
                quantity <> 0 and unit_cost_cents >= 0 and (
                    (move_type in ('opening', 'receipt', 'transfer_in') and quantity > 0)
                    or (move_type in ('issue', 'transfer_out', 'consumption') and quantity < 0)
                    or move_type in ('return', 'adjustment')
                )
            )
            SQL);
        DB::statement("alter table stock_moves add constraint stock_moves_adjustment_reason_check check (move_type <> 'adjustment' or length(btrim(coalesce(reason, ''))) > 0)");
        AppendOnly::protect('stock_moves');

        // Balances change only through the ledger, which says so for its own transaction.
        DB::unprepared(<<<'SQL'
            create or replace function stock_balances_ledger_only() returns trigger
            language plpgsql as $$
            begin
                if current_setting('torquelane.stock_ledger', true) is distinct from 'on' then
                    raise exception 'stock balances change only through the stock ledger (% on %)', tg_op, tg_table_name
                        using errcode = 'restrict_violation',
                              hint = 'Record a stock move; the ledger updates the balance in the same transaction.';
                end if;
                return case when tg_op = 'DELETE' then old else new end;
            end
            $$;
            create trigger stock_balances_ledger_only
                before insert or update or delete on stock_balances
                for each row execute function stock_balances_ledger_only();
            create trigger stock_balances_ledger_only_truncate
                before truncate on stock_balances
                for each statement execute function forbid_append_only_mutation();
            SQL);

        // At commit, a balance is exactly the sum of its moves.
        DB::unprepared(<<<'SQL'
            create or replace function stock_balances_match_moves() returns trigger
            language plpgsql as $$
            declare
                held numeric;
                summed numeric;
            begin
                -- A deferred trigger sees the row as it was when queued, so always
                -- compare the CURRENT balance with the moves as they stand at commit.
                select on_hand into held from stock_balances where location_id = new.location_id and item_id = new.item_id;
                select coalesce(sum(quantity), 0) into summed from stock_moves where location_id = new.location_id and item_id = new.item_id;
                if held is null or held <> summed then
                    raise exception 'stock balance for item % at location % is % but its moves sum to %', new.item_id, new.location_id, coalesce(held::text, 'missing'), summed
                        using errcode = 'integrity_constraint_violation';
                end if;
                return null;
            end
            $$;
            create constraint trigger stock_balances_match_moves
                after insert or update on stock_balances
                deferrable initially deferred
                for each row execute function stock_balances_match_moves();
            create constraint trigger stock_moves_match_balance
                after insert on stock_moves
                deferrable initially deferred
                for each row execute function stock_balances_match_moves();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop trigger if exists stock_moves_match_balance on stock_moves; drop trigger if exists stock_balances_match_moves on stock_balances;');
        DB::unprepared('drop trigger if exists stock_balances_ledger_only_truncate on stock_balances; drop trigger if exists stock_balances_ledger_only on stock_balances;');
        AppendOnly::unprotect('stock_moves');
        Schema::dropIfExists('stock_moves');
        Schema::dropIfExists('stock_balances');
        DB::unprepared('drop function if exists stock_balances_match_moves(); drop function if exists stock_balances_ledger_only();');
    }
};
