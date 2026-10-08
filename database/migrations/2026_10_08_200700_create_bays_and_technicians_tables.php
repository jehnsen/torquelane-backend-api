<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch-owned shop floor, replacing the frontend's static lib/bays.ts and
 * lib/technicians.ts. `focus` stays advisory, as it was: nothing stops a job
 * being assigned to a bay outside its specialty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bays', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('name');
            $table->string('focus')->nullable();
            // Working hours a bay absorbs in a day: the utilisation denominator.
            $table->rate('capacity_hours_per_day')->default(9);
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['branch_id', 'name']);
            $table->unique(['id', 'branch_id']);
            $table->foreign(['branch_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('branches')
                ->restrictOnDelete();
        });

        DB::statement("alter table bays add constraint bays_status_check check (status in ('active', 'inactive'))");
        DB::statement('alter table bays add constraint bays_capacity_check check (capacity_hours_per_day > 0 and capacity_hours_per_day <= 24)');

        Schema::create('technicians', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('name');
            // e.g. ["mechanic"], ["detailer"]: what work they may be assigned.
            $table->jsonb('skill_tags')->default('[]');
            $table->string('specialty')->nullable();
            $table->ulid('home_bay_id')->nullable();
            // The technician's own login, when they have one.
            $table->ulid('user_id')->nullable()->unique();
            $table->string('status', 16)->default('active');
            $table->timestampsTz();

            $table->unique(['branch_id', 'name']);
            $table->foreign(['branch_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('branches')
                ->restrictOnDelete();
            // A home bay must be in the technician's own branch.
            $table->foreign(['home_bay_id', 'branch_id'])
                ->references(['id', 'branch_id'])->on('bays')
                ->restrictOnDelete();
            $table->foreign(['user_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('users')
                ->restrictOnDelete();
        });

        DB::statement("alter table technicians add constraint technicians_status_check check (status in ('active', 'inactive'))");
        DB::statement("alter table technicians add constraint technicians_skill_tags_check check (jsonb_typeof(skill_tags) = 'array')");
    }

    public function down(): void
    {
        Schema::dropIfExists('technicians');
        Schema::dropIfExists('bays');
    }
};
