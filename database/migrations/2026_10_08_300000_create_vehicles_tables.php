<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Vehicles and who owns them over time.
 *
 * `customer_account_id` is the CURRENT owner (what portal scoping reads);
 * `vehicle_ownerships` is the history. Service history (readings,
 * maintenance state) stays with the vehicle across owners; documents carry
 * the account that owned the vehicle when they were filed, so a new owner
 * never sees the previous owner's papers.
 *
 * Vehicles are archived, never deleted: plate uniqueness applies among
 * unarchived vehicles only, so a plate can come back (resold, re-registered).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('customer_account_id')->index();
            $table->string('plate_number', 32);
            // Upper case, no spaces or dashes (App\Domain\Fleet\PlateNumber).
            $table->string('plate_normalized', 32);
            $table->string('make', 64)->nullable();
            $table->string('model', 128)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('vin', 32)->nullable();
            $table->string('vin_normalized', 32)->nullable();
            $table->string('vehicle_class', 16)->nullable();
            $table->string('fuel_type', 16)->nullable();
            // For detailing pricing (a later phase).
            $table->string('size_class', 8)->nullable();
            $table->string('color', 64)->nullable();
            $table->string('status', 16)->default('active');
            $table->string('assigned_to')->nullable();
            $table->string('department')->nullable();
            $table->string('location')->nullable();
            $table->date('acquired_on')->nullable();
            $table->date('registration_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->date('driver_licence_expiry')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();

            $table->unique(['id', 'organization_id']);
            $table->foreign(['customer_account_id', 'organization_id'])
                ->references(['id', 'organization_id'])->on('customer_accounts')
                ->restrictOnDelete();
        });

        DB::statement('create unique index vehicles_plate_unique on vehicles (organization_id, plate_normalized) where archived_at is null');
        DB::statement('create unique index vehicles_vin_unique on vehicles (organization_id, vin_normalized) where archived_at is null and vin_normalized is not null');
        DB::statement("alter table vehicles add constraint vehicles_status_check check (status in ('active', 'in_service', 'down'))");
        DB::statement("alter table vehicles add constraint vehicles_class_check check (vehicle_class is null or vehicle_class in ('sedan', 'suv', 'pickup', 'van', 'truck'))");
        DB::statement("alter table vehicles add constraint vehicles_fuel_check check (fuel_type is null or fuel_type in ('gasoline', 'diesel', 'hybrid', 'electric'))");
        DB::statement("alter table vehicles add constraint vehicles_size_check check (size_class is null or size_class in ('small', 'medium', 'large', 'xl'))");
        DB::statement("alter table vehicles add constraint vehicles_plate_normalized_check check (plate_normalized <> '' and plate_normalized = upper(plate_normalized))");

        Schema::create('vehicle_ownerships', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('organization_id')->index()->constrained()->restrictOnDelete();
            $table->ulid('vehicle_id')->index();
            $table->ulid('customer_account_id')->index();
            $table->date('from_date');
            // Null while current.
            $table->date('to_date')->nullable();
            $table->timestampsTz();

            $table->foreign(['vehicle_id', 'organization_id'])->references(['id', 'organization_id'])->on('vehicles')->restrictOnDelete();
            $table->foreign(['customer_account_id', 'organization_id'])->references(['id', 'organization_id'])->on('customer_accounts')->restrictOnDelete();
        });

        DB::statement('create unique index vehicle_ownerships_one_current on vehicle_ownerships (vehicle_id) where to_date is null');
        DB::statement('alter table vehicle_ownerships add constraint vehicle_ownerships_dates_check check (to_date is null or to_date >= from_date)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_ownerships');
        Schema::dropIfExists('vehicles');
    }
};
