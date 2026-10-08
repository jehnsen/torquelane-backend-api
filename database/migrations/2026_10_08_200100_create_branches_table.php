<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->text('address')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone', 32)->nullable();
            $table->string('tin', 11)->nullable();
            // BIR branch code (000 = head office).
            $table->string('branch_code', 5)->nullable();
            $table->boolean('is_vat_registered')->default(true);
            $table->boolean('prices_include_vat')->default(true);
            $table->string('timezone', 64)->default('Asia/Manila');
            // Branding overrides; null inherits the organization's, field by field.
            $table->string('brand_name')->nullable();
            $table->string('logo_url', 2048)->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->jsonb('theme_tokens')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['organization_id', 'slug']);
            // Target of the composite foreign keys that keep children in their branch's organization.
            $table->unique(['id', 'organization_id']);
        });

        DB::statement("alter table branches add constraint branches_status_check check (status in ('active', 'inactive'))");
        DB::statement("alter table branches add constraint branches_brand_color_check check (brand_color is null or brand_color ~ '^#[0-9a-fA-F]{6}$')");
        DB::statement("alter table branches add constraint branches_theme_tokens_check check (theme_tokens is null or jsonb_typeof(theme_tokens) = 'object')");
    }

    public function down(): void
    {
        Schema::dropIfExists('branches');
    }
};
