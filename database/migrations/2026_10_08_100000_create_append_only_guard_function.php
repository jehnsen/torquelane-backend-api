<?php

declare(strict_types=1);

use App\Database\AppendOnly;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The trigger function behind AppendOnly::protect(). One function, shared by
 * every append-only table's triggers (R7).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('create or replace function '.AppendOnly::FUNCTION_NAME.<<<'SQL'
            () returns trigger
            language plpgsql as $$
            begin
                raise exception 'append-only table %: % is not allowed', tg_table_name, tg_op
                    using errcode = 'restrict_violation',
                          hint = 'Correct an issued record with a reversal row, never by editing it.';
            end
            $$
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('drop function if exists '.AppendOnly::FUNCTION_NAME.'()');
    }
};
