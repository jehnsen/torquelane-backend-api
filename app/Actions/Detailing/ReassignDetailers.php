<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\BookingCapacity;
use App\Models\Branch;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingSlot;
use App\Models\DetailingPackage;
use Illuminate\Support\Facades\DB;

/**
 * Name a booking's detailers afresh: the source's `update_booking_detailers`.
 * Capacity and each detailer's other jobs are checked across the booking's
 * whole span, continuation days included. The heads reserved follow the names.
 */
final class ReassignDetailers
{
    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly TakeBooking $bookings,
    ) {}

    /**
     * @param  list<string>  $technicianIds  in the order named
     */
    public function handle(DetailingBooking $booking, array $technicianIds): DetailingBooking
    {
        $technicianIds = array_values(array_unique($technicianIds));

        return DB::transaction(function () use ($booking, $technicianIds): DetailingBooking {
            $locked = $this->journal->lockBooking($booking);
            $before = AuditTrail::snapshot($locked) + ['detailer_ids' => $locked->load('detailers')->detailerIds()];

            $span = array_values(DetailingBookingSlot::query()->where('booking_id', $locked->id)->orderBy('date')->orderBy('slot')->get()
                ->map(fn (DetailingBookingSlot $slot): array => ['date' => $slot->date->toDateString(), 'slot' => $slot->slot])->all());
            $dates = array_values(array_unique(array_map(fn (array $cell): string => $cell['date'], $span)));
            $this->journal->lockDates($locked->branch_id, $dates);

            $names = $this->bookings->detailerNames(Branch::query()->findOrFail($locked->branch_id), $technicianIds);
            $settings = $this->journal->settings($locked->organization_id, $locked->branch_id);
            // The package's minimum as it stands NOW, as the source read it (not the booking's snapshot).
            $minimum = DetailingPackage::query()->whereKey($locked->package_id)->value('min_detailers');
            $minimum = is_int($minimum) ? $minimum : $locked->min_detailers;
            $admission = BookingCapacity::reassign(
                $span,
                $this->journal->occupancy($locked->branch_id, $dates, $locked->id),
                $settings->detailer_pool_size,
                $minimum,
                $technicianIds,
            );
            if (! $admission->isAdmitted()) {
                throw TakeBooking::refusal($admission, $names, $minimum, true);
            }

            $locked->forceFill(['detailers_count' => $admission->headcount])->save();
            TakeBooking::writeDetailers($locked, $admission->detailerIds);

            $this->journal->audit($locked, 'detailers_assigned', $before);
            $this->journal->announce($locked, 'detailers');

            return $locked;
        });
    }
}
