<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A draft cancelled before it was ever sent never drew a number, and must not
 * draw one to be cancelled (a draft burns no number). The Phase 3 CHECK let
 * only a draft lack a reference, so cancelling one failed in the database.
 * Every other status still needs its reference.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table work_orders drop constraint work_orders_reference_check');
        DB::statement("alter table work_orders add constraint work_orders_reference_check check (status in ('draft', 'cancelled') or reference <> '')");
    }

    public function down(): void
    {
        DB::statement('alter table work_orders drop constraint work_orders_reference_check');
        DB::statement("alter table work_orders add constraint work_orders_reference_check check (status = 'draft' or reference <> '')");
    }
};
