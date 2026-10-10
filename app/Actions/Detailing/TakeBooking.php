<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Detailing\Admission;
use App\Domain\Detailing\BookingCapacity;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\DayPlan;
use App\Domain\Detailing\DayPlanner;
use App\Domain\Detailing\Refusal;
use App\Domain\Detailing\ServiceDuration;
use App\Domain\Detailing\SizeClass;
use App\Domain\Numbering\DocumentType;
use App\Exceptions\ConflictException;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingAddon;
use App\Models\DetailingBlockedSlot;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingAddon;
use App\Models\DetailingBookingDetailer;
use App\Models\DetailingBookingSlot;
use App\Models\DetailingPackage;
use App\Models\Technician;
use App\Models\Vehicle;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Take a booking: the source's `add_booking`, with the plan the source's
 * client computed now computed here (R2).
 *
 * In one transaction: plan the days (sequential fill from the start time),
 * lock every (branch, date) the plan touches, refuse a blocked slot, let
 * `BookingCapacity` decide over what is already held, then write the booking
 * numbered from the `detailing_booking` series (R8), its slots, its named
 * detailers, its extras and the first row of its status log.
 */
final class TakeBooking
{
    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly DocumentNumbers $numbers,
    ) {}

    /**
     * @param  array{
     *     date: string,
     *     start_minutes: int,
     *     detailers_count?: int|null,
     *     detailer_ids?: list<string>,
     *     addons?: list<array{addon_id?: string|null, name?: string|null, quantity?: string|null, unit_price_cents?: int|null}>,
     *     notes?: string|null,
     *     customer_name?: string|null,
     *     customer_email?: string|null,
     *     customer_phone?: string|null,
     *     vehicle_label?: string|null,
     *     bay_id?: string|null,
     *     status?: BookingStatus,
     *     source?: string,
     *     public_token_hash?: string|null,
     * }  $data
     */
    public function handle(Branch $branch, CustomerAccount $account, ?Vehicle $vehicle, DetailingPackage $package, SizeClass $size, array $data): DetailingBooking
    {
        if ($package->branch_id !== $branch->id) {
            throw ValidationException::withMessages(['package_id' => 'That package is not on this branch\'s menu.']);
        }
        if (! $package->is_active) {
            throw ValidationException::withMessages(['package_id' => "{$package->name} is no longer offered."]);
        }
        $package->loadMissing('prices', 'category');
        $price = $package->priceFor($size) ?? throw ValidationException::withMessages(['size_class' => "{$package->name} has no price for a {$size->label()} vehicle."]);
        if ($vehicle !== null && $vehicle->customer_account_id !== $account->id) {
            throw ValidationException::withMessages(['vehicle_id' => 'That vehicle belongs to another account.']);
        }

        $status = $data['status'] ?? BookingStatus::Pending;
        if ($status !== BookingStatus::Pending && $status !== BookingStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'A booking is made pending or confirmed.']);
        }
        $detailerIds = array_values(array_unique($data['detailer_ids'] ?? []));
        $duration = $package->durationFor($size);

        return DB::transaction(function () use ($branch, $account, $vehicle, $package, $size, $data, $price, $status, $detailerIds, $duration): DetailingBooking {
            $settings = $this->journal->settings($branch->organization_id, $branch->id);
            $plan = DayPlanner::plan($data['date'], $data['start_minutes'], ServiceDuration::slotBudget($duration), $settings->closing_minutes);
            if ($plan->isEmpty()) {
                throw ValidationException::withMessages(['start_time' => Admission::refused(Refusal::InvalidSlot)->message()]);
            }

            $this->journal->lockDates($branch->id, $plan->dates());
            $names = $this->detailerNames($branch, $detailerIds);
            $this->refuseBlocked($branch, $plan);

            $admission = BookingCapacity::admit(
                $plan,
                $this->journal->occupancy($branch->id, $plan->dates()),
                $settings->detailer_pool_size,
                $package->min_detailers,
                $data['detailers_count'] ?? $settings->default_detailers_per_booking,
                $detailerIds,
            );
            if (! $admission->isAdmitted()) {
                throw self::refusal($admission, $names, $package->min_detailers, false);
            }

            $now = CarbonImmutable::now();
            $actor = $this->journal->actor();
            $booking = new DetailingBooking;
            $booking->forceFill([
                'organization_id' => $branch->organization_id,
                'branch_id' => $branch->id,
                'customer_account_id' => $account->id,
                'vehicle_id' => $vehicle?->id,
                'reference' => $this->numbers->issue($branch->organization_id, null, DocumentType::DetailingBooking, $now)->formatted,
                'status' => $status,
                'source' => $data['source'] ?? DetailingBooking::SOURCE_STAFF,
                'package_id' => $package->id,
                'package_name' => $package->name,
                'category_name' => $package->category?->name,
                'size_class' => $size,
                'package_price_cents' => $price->price_cents,
                'duration_text' => $duration,
                'min_detailers' => $package->min_detailers,
                'scheduled_date' => $data['date'],
                'start_minutes' => $data['start_minutes'],
                'detailers_count' => $admission->headcount,
                'bay_id' => $this->bayId($branch, $data['bay_id'] ?? null),
                'customer_name' => self::text($data['customer_name'] ?? null) ?? $account->display_name,
                'customer_email' => self::text($data['customer_email'] ?? null) ?? $account->email,
                'customer_phone' => self::text($data['customer_phone'] ?? null) ?? $account->mobile,
                'vehicle_label' => self::text($data['vehicle_label'] ?? null) ?? self::vehicleLabel($vehicle),
                'notes' => trim($data['notes'] ?? ''),
                'checklist' => array_map(
                    fn (string $label): array => ['label' => $label, 'done' => false, 'done_by_name' => null, 'done_at' => null],
                    array_values(array_filter($package->inclusions, fn (string $label): bool => trim($label) !== '')),
                ),
                'public_token_hash' => $data['public_token_hash'] ?? null,
                'created_by' => $actor?->id,
                'created_by_name' => $this->journal->actorName(),
            ])->save();

            foreach ($plan->cells() as $cell) {
                $slot = new DetailingBookingSlot;
                $slot->forceFill([
                    'organization_id' => $booking->organization_id,
                    'branch_id' => $booking->branch_id,
                    'booking_id' => $booking->id,
                    'date' => $cell['date'],
                    'slot' => $cell['slot'],
                ])->save();
            }
            self::writeDetailers($booking, $admission->detailerIds);
            $this->writeAddons($booking, $data['addons'] ?? []);

            $this->journal->log($booking, null, $status, null, $now);
            $this->journal->audit($booking, 'booked', null);
            $this->journal->announce($booking, 'booked');

            return $booking;
        });
    }

    /**
     * Replace a booking's extras. A listed add-on is priced for the booking's
     * size unless a price is typed over it; a typed-in extra needs a name and a price.
     *
     * @param  list<array{addon_id?: string|null, name?: string|null, quantity?: string|null, unit_price_cents?: int|null}>  $rows
     */
    public function writeAddons(DetailingBooking $booking, array $rows): void
    {
        DetailingBookingAddon::query()->where('booking_id', $booking->id)->delete();

        $ids = array_values(array_filter(array_map(fn (array $row): ?string => $row['addon_id'] ?? null, $rows)));
        /** @var array<string, DetailingAddon> $listed */
        $listed = [];
        if ($ids !== []) {
            foreach (DetailingAddon::query()->where('branch_id', $booking->branch_id)->whereIn('id', $ids)->with('prices')->get() as $found) {
                $listed[$found->id] = $found;
            }
        }

        foreach ($rows as $index => $row) {
            $addon = isset($row['addon_id']) ? ($listed[$row['addon_id']] ?? null) : null;
            if (isset($row['addon_id']) && $addon === null) {
                throw ValidationException::withMessages(["addons.{$index}.addon_id" => 'That extra is not on this branch\'s list.']);
            }
            $name = self::text($row['name'] ?? null) ?? $addon?->name;
            $price = $row['unit_price_cents'] ?? $addon?->priceFor($booking->size_class)?->price_cents;
            if ($name === null) {
                throw ValidationException::withMessages(["addons.{$index}.name" => 'Name the extra.']);
            }
            if ($price === null) {
                throw ValidationException::withMessages(["addons.{$index}.unit_price_cents" => "{$name} has no price for a {$booking->size_class->label()} vehicle; type one."]);
            }
            $quantity = BigDecimal::of($row['quantity'] ?? '1');
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(["addons.{$index}.quantity" => 'A quantity is above zero.']);
            }

            $line = new DetailingBookingAddon;
            $line->forceFill([
                'organization_id' => $booking->organization_id,
                'booking_id' => $booking->id,
                'addon_id' => $addon?->id,
                'name' => $name,
                'quantity' => $quantity,
                'unit_price_cents' => $price,
                'position' => $index,
            ])->save();
        }
    }

    /**
     * @param  list<string>  $technicianIds
     */
    public static function writeDetailers(DetailingBooking $booking, array $technicianIds): void
    {
        DetailingBookingDetailer::query()->where('booking_id', $booking->id)->delete();
        foreach ($technicianIds as $position => $technicianId) {
            $row = new DetailingBookingDetailer;
            $row->forceFill([
                'organization_id' => $booking->organization_id,
                'branch_id' => $booking->branch_id,
                'booking_id' => $booking->id,
                'technician_id' => $technicianId,
                'position' => $position,
            ])->save();
        }
    }

    /**
     * The named detailers, by id: active technicians of this branch who detail.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public function detailerNames(Branch $branch, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $names = [];
        foreach (Technician::query()->where('branch_id', $branch->id)->whereIn('id', $ids)->get() as $technician) {
            if ($technician->status === 'active' && in_array('detailer', $technician->skill_tags, true)) {
                $names[$technician->id] = $technician->name;
            }
        }
        foreach ($ids as $index => $id) {
            if (! isset($names[$id])) {
                throw ValidationException::withMessages(["detailer_ids.{$index}" => 'Name an active detailer of this branch.']);
            }
        }

        return $names;
    }

    /**
     * What the caller is told when the engine says no. A full slot, a busy
     * detailer and a blocked slot are conflicts with what is already there
     * (409, `details.reason`); the rest are mistakes in the request (422).
     *
     * @param  array<string, string>  $names
     */
    public static function refusal(Admission $admission, array $names, int $minDetailers, bool $reassigning): ConflictException|ValidationException
    {
        $message = $admission->message($names, $minDetailers, $reassigning);

        return match ($admission->refusal) {
            Refusal::Full, Refusal::OverCapacity => new ConflictException($message, ['reason' => $admission->refusal->value, 'remaining' => $admission->remaining ?? 0]),
            Refusal::DetailerConflict => new ConflictException($message, ['reason' => $admission->refusal->value, 'detailer_ids' => $admission->conflictIds]),
            Refusal::Blocked => new ConflictException($message, ['reason' => Refusal::Blocked->value]),
            Refusal::InvalidSlot, Refusal::NoSlots => ValidationException::withMessages(['start_time' => $message]),
            default => ValidationException::withMessages(['detailer_ids' => $message]),
        };
    }

    private function refuseBlocked(Branch $branch, DayPlan $plan): void
    {
        $blocked = [];
        foreach (DetailingBlockedSlot::query()->where('branch_id', $branch->id)->whereIn('date', $plan->dates())->get(['date', 'slot']) as $row) {
            $blocked[$row->date->toDateString().'|'.$row->slot] = true;
        }
        foreach ($plan->cells() as $cell) {
            if (isset($blocked[$cell['date'].'|'.$cell['slot']])) {
                throw self::refusal(Admission::refused(Refusal::Blocked), [], 1, false);
            }
        }
    }

    private function bayId(Branch $branch, ?string $bayId): ?string
    {
        if ($bayId === null) {
            return null;
        }
        if (! Bay::query()->where('branch_id', $branch->id)->whereKey($bayId)->exists()) {
            throw ValidationException::withMessages(['bay_id' => 'That bay is not in this branch.']);
        }

        return $bayId;
    }

    private static function vehicleLabel(?Vehicle $vehicle): ?string
    {
        if ($vehicle === null) {
            return null;
        }
        $label = trim(implode(' ', array_filter([$vehicle->year === null ? null : (string) $vehicle->year, $vehicle->make, $vehicle->model])));

        return trim($label.($vehicle->plate_number !== '' ? " · {$vehicle->plate_number}" : ''), ' ·');
    }

    private static function text(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
