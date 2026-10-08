<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module entitlements. Active for a branch = enabled for the organization AND
 * the branch (App\Domain\Modules\ModuleEntitlements). No row = disabled.
 */
return new class extends Migration
{
    private const string MODULES = "('repair_pms', 'detailing', 'equipment', 'pos', 'crm', 'procurement', 'accounting')";

    public function up(): void
    {
        Schema::create('organization_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->string('module', 32);
            $table->boolean('enabled');
            $table->timestampsTz();

            $table->unique(['organization_id', 'module']);
        });

        Schema::create('branch_modules', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id');
            $table->string('module', 32);
            $table->boolean('enabled');
            $table->timestampsTz();

            $table->unique(['branch_id', 'module']);
            $table->foreign(['branch_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('branches')
                ->cascadeOnDelete();
        });

        DB::statement('alter table organization_modules add constraint organization_modules_module_check check (module in '.self::MODULES.')');
        DB::statement('alter table branch_modules add constraint branch_modules_module_check check (module in '.self::MODULES.')');
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_modules');
        Schema::dropIfExists('organization_modules');
    }
};
