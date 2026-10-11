<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\BookingClock;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\SlotRelease;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingSlot;
use App\Models\DetailingInspection;
use App\Notifications\DetailingBookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Move a booking's status: the source's `update_booking_status`.
 *
 * As in the source, any status may follow any other, and what the move does
 * is the same: `started_at` is stamped the first time work starts and
 * `completed_at` each time it completes (`BookingClock`); completing gives
 * back the slots from the 30-minute bucket of the completion onward, and
 * re-opening takes every slot back without re-checking the pool
 * (`SlotRelease`); the reason is kept on a cancellation; one row goes to the
 * append-only log.
 *
 * What the job card adds on top of the source:
 *  - work may not START before the check-in inspection is recorded, unless
 *    the branch does not require one or the move carries a reason to override;
 *  - completing takes the package's materials from stock, once;
 *  - re-opening a job clears its sign-off (it is checked again);
 *  - a booking on a standing invoice only ever stays completed;
 *  - confirming a booking emails the customer, as the source did.
 */
final class ChangeBookingStatus
{
    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly ConsumeMaterials $materials,
        private readonly BookingMail $mail,
    ) {}

    public function handle(DetailingBooking $booking, BookingStatus $to, ?string $reason = null, ?string $inspectionOverride = null): DetailingBooking
    {
        return DB::transaction(function () use ($booking, $to, $reason, $inspectionOverride): DetailingBooking {
            $locked = $this->journal->lockBooking($booking);
            $before = AuditTrail::snapshot($locked);
            $from = $locked->status;
            $now = CarbonImmutable::now();

            if ($to !== BookingStatus::Completed && $locked->load('invoiceLinks')->standingInvoiceLink() !== null) {
                throw new InvalidTransitionException("{$locked->reference} is on an invoice; void the invoice before changing the booking.");
            }
            if ($to !== BookingStatus::Completed && $locked->prepaidRedemption()->exists()) {
                throw new InvalidTransitionException("{$locked->reference} was settled with a prepaid credit; it stays completed.");
            }
            if ($to === BookingStatus::OnGoing) {
                $this->requireInspection($locked, $inspectionOverride);
            }

            $stamps = BookingClock::stamp($to, $locked->started_at?->toDateTimeImmutable(), $locked->completed_at?->toDateTimeImmutable(), $now->toDateTimeImmutable());
            $locked->forceFill([
                'status' => $to,
                'started_at' => $stamps['started_at'],
                'completed_at' => $stamps['completed_at'],
            ]);
            if ($to === BookingStatus::Cancelled) {
                $locked->cancellation_reason = $reason;
            }
            if ($to->reopens()) {
                $locked->forceFill(['qc_status' => null, 'qc_by' => null, 'qc_by_name' => null, 'qc_at' => null, 'qc_notes' => null]);
            }
            $locked->save();

            if ($to === BookingStatus::Completed) {
                $this->release($locked, $now);
                $this->materials->handle($locked, $now);
            } elseif ($to->reopens()) {
                DetailingBookingSlot::query()->where('booking_id', $locked->id)->where('released', true)->update(['released' => false]);
            }

            $this->journal->log($locked, $from, $to, $reason, $now);
            $this->journal->audit($locked, 'status_changed', $before);
            $this->journal->announce($locked, 'status');
            // As in the source: confirming is what tells the customer.
            if ($to === BookingStatus::Confirmed && $from !== BookingStatus::Confirmed) {
                $this->mail->send($locked, DetailingBookingNotification::CONFIRMED);
            }

            return $locked;
        });
    }

    /** Give back the slots from the bucket of the completion onward. Slots already released stay released. */
    private function release(DetailingBooking $booking, CarbonImmutable $now): void
    {
        $slots = DetailingBookingSlot::query()->where('booking_id', $booking->id)->get();
        $local = Calendar::local($now);
        $released = SlotRelease::onCompletion(
            array_values($slots->map(fn (DetailingBookingSlot $slot): array => ['date' => $slot->date->toDateString(), 'slot' => $slot->slot])->all()),
            $local->format('Y-m-d'),
            (int) $local->format('G') * 60 + (int) $local->format('i'),
        );
        $keys = array_flip(array_map(fn (array $cell): string => $cell['date'].'|'.$cell['slot'], $released));
        $ids = array_values($slots->filter(fn (DetailingBookingSlot $slot): bool => isset($keys[$slot->date->toDateString().'|'.$slot->slot]))
            ->map(fn (DetailingBookingSlot $slot): string => $slot->id)->all());
        if ($ids !== []) {
            DetailingBookingSlot::query()->whereIn('id', $ids)->update(['released' => true]);
        }
    }

    private function requireInspection(DetailingBooking $booking, ?string $override): void
    {
        $override = trim($override ?? '');
        if ($override !== '') {
            $booking->forceFill([
                'inspection_override_reason' => $override,
                'inspection_overridden_by_name' => $this->journal->actorName(),
            ]);

            return;
        }
        if ($booking->inspection_override_reason !== null) {
            return;
        }
        if (! $this->journal->settings($booking->organization_id, $booking->branch_id)->inspection_required) {
            return;
        }
        $inspected = DetailingInspection::query()->where('booking_id', $booking->id)->where('stage', DetailingInspection::STAGE_CHECK_IN)->exists();
        if (! $inspected) {
            throw new ConflictException(
                "Inspect the vehicle before starting {$booking->reference}, or give a reason to start without an inspection.",
                ['reason' => 'inspection_required'],
            );
        }
    }
}
