<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A document attached to a work order (../web documents.workOrderId: the
 * invoice, the service report). The composite key makes a document filed
 * under one account but attached to another account's order
 * unrepresentable: the order's stamped account is the document's account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unique(['id', 'customer_account_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->ulid('work_order_id')->nullable()->index();
            $table->foreign(['work_order_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('work_orders')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['work_order_id', 'customer_account_id']);
            $table->dropColumn('work_order_id');
        });

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropUnique(['id', 'customer_account_id']);
        });
    }
};
