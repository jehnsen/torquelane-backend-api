<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Detailing (Phase 9): the vehicle's inspection and the shop monitor's tokens.
 *
 * An inspection is the source's `car_condition_logs` (ratings out of ten,
 * exterior and interior condition, mileage, notes), taken per booking at
 * check-in and again at hand-back, with a damage map (points on a top-down
 * outline, each with a note) and before/after photos. The photos are
 * `documents` (the private disk, filed under the account at upload); a row
 * here only says which inspection a photo belongs to.
 *
 * The mileage is not stored here: an odometer is a meter reading (Phase 2).
 * The inspection points at the reading it recorded.
 *
 * A display token lets a wall screen read ONE branch's monitor and nothing
 * else. Only its SHA-256 is kept; revoking it is a timestamp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('detailing_inspections', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->ulid('booking_id');
            $table->ulid('customer_account_id')->index();
            $table->ulid('vehicle_id')->nullable()->index();
            $table->string('stage', 16);
            $table->unsignedTinyInteger('overall_rating');
            $table->unsignedTinyInteger('exterior_rating')->nullable();
            $table->unsignedTinyInteger('interior_rating')->nullable();
            $table->string('exterior_condition', 16)->nullable();
            $table->string('interior_condition', 16)->nullable();
            $table->ulid('meter_reading_id')->nullable();
            $table->text('notes')->nullable();
            $table->ulid('recorded_by')->nullable();
            $table->string('recorded_by_name');
            $table->timestampTz('recorded_at');
            $table->timestampsTz();

            $table->unique(['booking_id', 'stage']);
            $table->unique(['id', 'organization_id']);
            $table->unique(['id', 'customer_account_id']);
            $table->foreign(['booking_id', 'branch_id'])->references(['id', 'branch_id'])->on('detailing_bookings')->restrictOnDelete();
            $table->foreign(['booking_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('detailing_bookings')->restrictOnDelete();
            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['recorded_by', 'organization_id'])->references(['id', 'organization_id'])->on('users')->restrictOnDelete();
            $table->foreign('meter_reading_id')->references('id')->on('meter_readings')->restrictOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table detailing_inspections add constraint detailing_inspections_values_check check (
                stage in ('check_in', 'check_out')
                and overall_rating between 1 and 10
                and (exterior_rating is null or exterior_rating between 1 and 10)
                and (interior_rating is null or interior_rating between 1 and 10)
                and (exterior_condition is null or exterior_condition in ('excellent', 'good', 'fair', 'poor'))
                and (interior_condition is null or interior_condition in ('excellent', 'good', 'fair', 'poor'))
            )
            SQL);

        Schema::create('detailing_damage_points', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('inspection_id')->index();
            // Where on the top-down outline, in thousandths of its width and length.
            $table->unsignedSmallInteger('x_permille');
            $table->unsignedSmallInteger('y_permille');
            $table->string('kind', 16)->default('other');
            $table->string('note', 500);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->foreign(['inspection_id', 'organization_id'])->references(['id', 'organization_id'])->on('detailing_inspections')->cascadeOnDelete();
        });
        DB::statement(<<<'SQL'
            alter table detailing_damage_points add constraint detailing_damage_points_values_check check (
                x_permille between 0 and 1000 and y_permille between 0 and 1000
                and kind in ('scratch', 'dent', 'chip', 'crack', 'stain', 'other')
                and length(btrim(note)) > 0
            )
            SQL);

        // So a photo row can only point at a document of the same account.
        DB::statement('alter table documents add constraint documents_id_account_unique unique (id, customer_account_id)');

        Schema::create('detailing_inspection_photos', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('inspection_id')->index();
            $table->ulid('customer_account_id');
            $table->ulid('document_id')->unique();
            $table->string('phase', 8);
            $table->string('caption')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            // A photo is of the inspection's own account, as its document is filed.
            $table->foreign(['inspection_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('detailing_inspections')->cascadeOnDelete();
            $table->foreign(['document_id', 'customer_account_id'])->references(['id', 'customer_account_id'])->on('documents')->restrictOnDelete();
        });
        DB::statement("alter table detailing_inspection_photos add constraint detailing_inspection_photos_phase_check check (phase in ('before', 'after'))");

        Schema::create('detailing_display_tokens', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('branch_id')->index();
            $table->string('name');
            $table->string('token_hash', 64)->unique();
            // The last characters of the token, so staff can tell two screens apart.
            $table->string('token_hint', 8);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->string('created_by_name');
            $table->timestampsTz();

            $table->foreign(['branch_id', 'organization_id'])->references(['id', 'organization_id'])->on('branches')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('detailing_display_tokens');
        Schema::dropIfExists('detailing_inspection_photos');
        DB::statement('alter table documents drop constraint if exists documents_id_account_unique');
        Schema::dropIfExists('detailing_damage_points');
        Schema::dropIfExists('detailing_inspections');
    }
};
