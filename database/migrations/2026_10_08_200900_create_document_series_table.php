<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free document numbering (R8). One row per (organization, branch,
 * document type, period). `next_number` is incremented under a row lock
 * inside the issuing document's own transaction
 * (App\Actions\Numbering\DocumentNumbers::issue), so a rollback returns the
 * number and two concurrent issuers serialise on the row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_series', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            // Null = an organization-wide series.
            $table->ulid('branch_id')->nullable();
            $table->string('doc_type', 32);
            $table->string('period_key', 16);
            $table->string('prefix', 16);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedSmallInteger('padding')->default(4);
            $table->timestampsTz();

            $table->foreign(['branch_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('branches')
                ->restrictOnDelete();
        });

        // NULLS NOT DISTINCT so the organization-wide series (branch_id null) is unique too.
        DB::statement('create unique index document_series_unique on document_series (organization_id, branch_id, doc_type, period_key) nulls not distinct');
        DB::statement("alter table document_series add constraint document_series_doc_type_check check (doc_type in ('work_order', 'invoice', 'receipt', 'purchase_order', 'goods_receipt', 'journal_entry'))");
        DB::statement('alter table document_series add constraint document_series_next_number_check check (next_number >= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_series');
    }
};
