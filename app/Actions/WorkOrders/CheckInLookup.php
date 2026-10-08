<?php

declare(strict_types=1);

namespace App\Actions\WorkOrders;

use App\Actions\Fleet\FleetQueries;
use App\Domain\CheckIn\CheckIn;
use App\Domain\CheckIn\CheckInCandidate;
use App\Domain\CheckIn\CheckInCustomer;
use App\Domain\CheckIn\CheckInForm;
use App\Domain\CheckIn\CheckInResult;
use App\Domain\Fleet\Pms;
use App\Domain\Fleet\PmsItem;
use App\Models\CustomerAccount;
use App\Models\Vehicle;

/**
 * Plate or VIN → the counter form, over the caller's SCOPED vehicles only (a
 * lookup over the whole organization would tell a portal user that another
 * account's vehicle exists).
 *
 * Endpoint rule, deliberately stricter than ../web's form hydration (which
 * pre-fills a stale reading and flags it): a stale odometer is NEVER
 * pre-filled. The form's odometer is null with odometer_needs_confirmation
 * set, and the last reading travels separately, for reference only —
 * accepting an old reading unchallenged shifts every due date behind it.
 */
final class CheckInLookup
{
    public function __construct(private readonly FleetQueries $fleet) {}

    /**
     * @return array{result: CheckInResult, form: CheckInForm, vehicle: Vehicle|null, account: CustomerAccount|null, suggested: list<PmsItem>}
     */
    public function handle(string $input): array
    {
        $vehicles = array_values($this->fleet->vehicles()->orderBy('id')->get()->all());
        $facts = $this->fleet->facts($vehicles);
        $byId = [];
        $candidates = [];
        foreach ($vehicles as $vehicle) {
            $byId[$vehicle->id] = $vehicle;
            $candidates[] = new CheckInCandidate(
                $facts[$vehicle->id],
                (string) $vehicle->vin,
                $vehicle->customer_account_id,
                (string) $vehicle->make,
                (string) $vehicle->model,
                $vehicle->year,
                $vehicle->vehicle_class,
                $vehicle->fuel_type,
            );
        }

        $result = CheckIn::lookup($input, $candidates, $this->fleet->today());
        $vehicle = $result->candidate === null ? null : $byId[$result->candidate->vehicle->id];
        $account = $vehicle === null ? null : CustomerAccount::query()->find($vehicle->customer_account_id);
        $customer = $account === null ? null : new CheckInCustomer($account->display_name, (string) $account->contact_name, (string) ($account->contact_email ?? $account->email));

        $form = CheckIn::hydrate($result, $customer);
        if ($result->odometerStale) {
            $form = new CheckInForm(
                $form->vehicleId, $form->plateNumber, $form->vin, $form->make, $form->model, $form->year,
                $form->vehicleClass, $form->fuelType, $form->customerName, $form->customerContact,
                $form->customerEmail, $form->assignedTo,
                odometer: null,
                odometerNeedsConfirmation: true,
                isExistingVehicle: $form->isExistingVehicle,
            );
        }

        $suggested = [];
        if ($result->candidate !== null && $this->fleet->pmsActive()) {
            $suggested = CheckIn::suggestedWork(Pms::evaluateVehicle($result->candidate->vehicle, $this->fleet->taskFacts(), $this->fleet->today()));
        }

        return ['result' => $result, 'form' => $form, 'vehicle' => $vehicle, 'account' => $account, 'suggested' => $suggested];
    }
}
