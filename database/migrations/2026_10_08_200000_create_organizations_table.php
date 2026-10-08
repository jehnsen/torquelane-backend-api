<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root (the frontend's "provider"). Not itself tenant-scoped: an
 * organization is found by the session's own organization_id, never listed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('legal_name')->nullable();
            // BIR TIN, 9 digits as NNN-NNN-NNN. Branch codes live on branches.
            $table->string('tin', 11)->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->text('address')->nullable();
            $table->string('support_email')->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->jsonb('theme_tokens')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();
        });

        DB::statement("alter table organizations add constraint organizations_status_check check (status in ('active', 'suspended'))");
        DB::statement("alter table organizations add constraint organizations_brand_color_check check (brand_color is null or brand_color ~ '^#[0-9a-fA-F]{6}$')");
        DB::statement("alter table organizations add constraint organizations_theme_tokens_check check (theme_tokens is null or jsonb_typeof(theme_tokens) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
