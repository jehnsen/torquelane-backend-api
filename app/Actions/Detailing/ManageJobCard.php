<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\BookingStatus;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\Bay;
use App\Models\DetailingBooking;
use App\Models\Technician;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The job card: what changes on a booking besides its status and its
 * detailers. Its bay, notes and contact details; its extras; the package
 * checklist being ticked off; and the sign-off.
 *
 * Quality control is signed by someone OTHER than a detailer who worked the
 * job: a user who is one of the booking's named detailers cannot sign it,
 * whatever their role.
 */
final class ManageJobCard
{
    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly TakeBooking $bookings,
    ) {}

    /**
     * @param  array{notes?: string|null, bay_id?: string|null, customer_name?: string|null, customer_email?: string|null, customer_phone?: string|null, vehicle_id?: string|null, vehicle_label?: string|null}  $data  only the keys present change
     */
    public function update(DetailingBooking $booking, array $data): DetailingBooking
    {
        return DB::transaction(function () use ($booking, $data): DetailingBooking {
            $locked = $this->journal->lockBooking($booking);
            $before = AuditTrail::snapshot($locked);

            if (array_key_exists('bay_id', $data)) {
                if ($data['bay_id'] !== null && ! Bay::query()->where('branch_id', $locked->branch_id)->whereKey($data['bay_id'])->exists()) {
                    throw ValidationException::withMessages(['bay_id' => 'That bay is not in this branch.']);
                }
                $locked->bay_id = $data['bay_id'];
            }
            if (array_key_exists('vehicle_id', $data)) {
                if ($data['vehicle_id'] !== null && ! Vehicle::query()->where('customer_account_id', $locked->customer_account_id)->whereKey($data['vehicle_id'])->exists()) {
                    throw ValidationException::withMessages(['vehicle_id' => 'That vehicle is not this customer\'s.']);
                }
                $locked->vehicle_id = $data['vehicle_id'];
            }
            if (array_key_exists('notes', $data)) {
                $locked->notes = trim($data['notes'] ?? '');
            }
            if (array_key_exists('customer_name', $data) && trim($data['customer_name'] ?? '') !== '') {
                $locked->customer_name = trim((string) $data['customer_name']);
            }
            foreach (['customer_email', 'customer_phone', 'vehicle_label'] as $field) {
                if (array_key_exists($field, $data)) {
                    $locked->setAttribute($field, trim($data[$field] ?? '') === '' ? null : trim((string) $data[$field]));
                }
            }
            $locked->save();

            $this->journal->audit($locked, 'updated', $before);
            $this->journal->announce($locked, 'updated');

            return $locked;
        });
    }

    /**
     * Replace the booking's extras (the source's `update_booking_addons`).
     *
     * @param  list<array{addon_id?: string|null, name?: string|null, quantity?: string|null, unit_price_cents?: int|null}>  $rows
     */
    public function addons(DetailingBooking $booking, array $rows): DetailingBooking
    {
        return DB::transaction(function () use ($booking, $rows): DetailingBooking {
            $locked = $this->journal->lockBooking($booking);
            $this->refuseInvoiced($locked);
            $before = AuditTrail::snapshot($locked) + ['addons' => $locked->addons()->get()->toArray()];

            $this->bookings->writeAddons($locked, $rows);
            $this->journal->audit($locked, 'addons_changed', $before);
            $this->journal->announce($locked, 'addons');

            return $locked;
        });
    }

    /** Tick, or un-tick, one line of the package checklist. */
    public function tick(DetailingBooking $booking, int $index, bool $done): DetailingBooking
    {
        return DB::transaction(function () use ($booking, $index, $done): DetailingBooking {
            $locked = $this->journal->lockBooking($booking);
            $checklist = $locked->checklist;
            if (! isset($checklist[$index])) {
                throw ValidationException::withMessages(['index' => 'That is not a line of this job\'s checklist.']);
            }
            if (! $locked->status->holdsCapacity()) {
                throw new InvalidTransitionException("{$locked->reference} is {$locked->status->label()}; its checklist is closed.");
            }
            $before = AuditTrail::snapshot($locked);

            $checklist[$index]['done'] = $done;
            $checklist[$index]['done_by_name'] = $done ? $this->journal->actorName() : null;
            $checklist[$index]['done_at'] = $done ? CarbonImmutable::now()->utc()->toIso8601ZuluString() : null;
            $locked->forceFill(['checklist' => $checklist])->save();

            $this->journal->audit($locked, 'checklist_ticked', $before);
            $this->journal->announce($locked, 'checklist');

            return $locked;
        });
    }

    /**
     * Sign a completed job off, or fail it with what is wrong. A failed job
     * stays completed until someone re-opens it (which clears the sign-off).
     */
    public function signOff(DetailingBooking $booking, bool $passed, ?string $notes): DetailingBooking
    {
        return DB::transaction(function () use ($booking, $passed, $notes): DetailingBooking {
            $locked = $this->journal->lockBooking($booking);
            if ($locked->status !== BookingStatus::Completed) {
                throw new InvalidTransitionException("{$locked->reference} is {$locked->status->label()}; only a completed job is signed off.");
            }
            $this->refuseInvoiced($locked);
            $actor = $this->journal->actor() ?? throw new AuthorizationException('A job is signed off by a signed-in member of staff.');

            $worked = Technician::query()
                ->whereIn('id', $locked->load('detailers')->detailerIds())
                ->where('user_id', $actor->id)
                ->exists();
            if ($worked) {
                throw new AuthorizationException('A job is signed off by someone other than the detailer who worked it.');
            }
            $notes = trim($notes ?? '');
            if (! $passed && $notes === '') {
                throw ValidationException::withMessages(['notes' => 'Say what is wrong with the job.']);
            }
            $before = AuditTrail::snapshot($locked);

            $locked->forceFill([
                'qc_status' => $passed ? DetailingBooking::QC_PASSED : DetailingBooking::QC_FAILED,
                'qc_by' => $actor->id,
                'qc_by_name' => $actor->name,
                'qc_at' => CarbonImmutable::now(),
                'qc_notes' => $notes === '' ? null : $notes,
            ])->save();

            $this->journal->audit($locked, $passed ? 'qc_passed' : 'qc_failed', $before);
            $this->journal->announce($locked, 'qc');

            return $locked;
        });
    }

    private function refuseInvoiced(DetailingBooking $booking): void
    {
        if ($booking->load('invoiceLinks')->standingInvoiceLink() !== null) {
            throw new ConflictException("{$booking->reference} is on an invoice; void the invoice before changing the booking.");
        }
    }
}
