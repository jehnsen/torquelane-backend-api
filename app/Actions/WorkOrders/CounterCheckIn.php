<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Accounts\CreateCustomerAccount;
use App\Actions\Fleet\CreateVehicle;
use App\Models\CustomerAccount;
use App\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * No vehicle matched: the counter opens the customer (with the consent to
 * keep service records) and registers the vehicle with the reading taken at
 * the counter — one transaction, so a refused vehicle leaves no orphan
 * customer behind.
 */
final class CounterCheckIn
{
    public function __construct(
        private readonly CreateCustomerAccount $accounts,
        private readonly CreateVehicle $vehicles,
    ) {}

    /**
     * @param  array<string, mixed>  $customer
     * @param  list<array{purpose: string, granted: bool, channel: string, contact_id?: string|null, evidence?: string|null, captured_at?: string|null}>  $consents
     * @param  array<string, mixed>  $vehicle
     * @param  array{value: string, read_on: string|null}  $odometer
     * @return array{account: CustomerAccount, vehicle: Vehicle}
     */
    public function handle(array $customer, array $consents, array $vehicle, array $odometer): array
    {
        return DB::transaction(function () use ($customer, $consents, $vehicle, $odometer): array {
            $account = $this->accounts->handle($customer, $consents);

            return ['account' => $account, 'vehicle' => $this->vehicles->handle($account, $vehicle, $odometer, [])];
        });
    }
}
