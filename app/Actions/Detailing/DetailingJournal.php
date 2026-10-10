<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\Occupancy;
use App\Events\DetailingBookingChanged;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingDetailer;
use App\Models\DetailingBookingSlot;
use App\Models\DetailingBookingStatusLog;
use App\Models\DetailingSetting;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What every detailing write shares: who is acting, the branch's settings,
 * the locks, the occupancy the engine decides over, the status log, the audit
 * row and the after-commit announcement.
 *
 * Concurrency. The source serialised bookings with `pg_advisory_xact_lock`
 * per date; here the lock is per (branch, date), taken for every date a
 * booking touches, in date order, inside the transaction. Two requests for the
 * same branch and day therefore decide one after the other, each seeing the
 * other's reservation. A change to one booking also locks its row.
 */
final class DetailingJournal
{
    /** Who a booking made from the public page is recorded as made by. */
    public const string ONLINE = 'Online booking';

    private ?User $actor = null;

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /** The signed-in user; null for a booking made from the public page. */
    public function actor(): ?User
    {
        $userId = $this->tenancy->context()?->userId;
        if ($userId === null) {
            return null;
        }
        if ($this->actor === null || $this->actor->id !== $userId) {
            $this->actor = User::query()->findOrFail($userId);
        }

        return $this->actor;
    }

    public function actorName(): string
    {
        return $this->actor()->name ?? self::ONLINE;
    }

    /** A branch's settings: its row, or the defaults while it has none. */
    public function settings(string $organizationId, string $branchId): DetailingSetting
    {
        return DetailingSetting::query()->where('branch_id', $branchId)->first()
            ?? DetailingSetting::defaults($organizationId, $branchId);
    }

    public function lockBooking(DetailingBooking $booking): DetailingBooking
    {
        self::inTransaction();

        return DetailingBooking::query()->lockForUpdate()->findOrFail($booking->id);
    }

    /**
     * Serialise every booking decision of a branch on these business dates.
     *
     * @param  list<string>  $dates  Y-m-d
     */
    public function lockDates(string $branchId, array $dates): void
    {
        self::inTransaction();
        $dates = array_values(array_unique($dates));
        sort($dates);
        foreach ($dates as $date) {
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["detailing:{$branchId}:{$date}"]);
        }
    }

    /**
     * Who holds what on the given dates: every slot not released, of every
     * booking that still holds capacity, but `$exceptBookingId`.
     *
     * @param  list<string>  $dates
     */
    public function occupancy(string $branchId, array $dates, ?string $exceptBookingId = null): Occupancy
    {
        if ($dates === []) {
            return Occupancy::of([]);
        }

        $slots = DetailingBookingSlot::query()
            ->where('branch_id', $branchId)
            ->whereIn('date', $dates)
            ->where('released', false)
            ->when($exceptBookingId !== null, fn ($query) => $query->where('booking_id', '!=', $exceptBookingId))
            ->get(['booking_id', 'date', 'slot']);
        if ($slots->isEmpty()) {
            return Occupancy::of([]);
        }

        $bookingIds = array_values(array_unique($slots->map(fn (DetailingBookingSlot $slot): string => $slot->booking_id)->all()));
        $heads = [];
        foreach (DetailingBooking::query()->whereIn('id', $bookingIds)->get(['id', 'status', 'detailers_count']) as $booking) {
            if ($booking->status->holdsCapacity()) {
                $heads[$booking->id] = $booking->detailers_count;
            }
        }
        $named = [];
        foreach (DetailingBookingDetailer::query()->whereIn('booking_id', array_keys($heads))->get(['booking_id', 'technician_id']) as $row) {
            $named[$row->booking_id][] = $row->technician_id;
        }

        $rows = [];
        foreach ($slots as $slot) {
            if (isset($heads[$slot->booking_id])) {
                $rows[] = [
                    'date' => $slot->date->toDateString(),
                    'slot' => $slot->slot,
                    'heads' => $heads[$slot->booking_id],
                    'detailer_ids' => $named[$slot->booking_id] ?? [],
                ];
            }
        }

        return Occupancy::of($rows);
    }

    /** One row of the append-only status log. `$from` is null for the booking being made. */
    public function log(DetailingBooking $booking, ?BookingStatus $from, BookingStatus $to, ?string $notes, CarbonImmutable $at): void
    {
        $log = new DetailingBookingStatusLog;
        $log->forceFill([
            'organization_id' => $booking->organization_id,
            'booking_id' => $booking->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'notes' => $notes,
            'actor_id' => $this->actor()?->id,
            'actor_name' => $this->actorName(),
            'changed_at' => $at,
        ])->save();
    }

    /**
     * @param  array<string, mixed>|null  $before
     */
    public function audit(DetailingBooking $booking, string $action, ?array $before): void
    {
        $this->audit->record($booking, $action, $before, AuditTrail::snapshot($booking));
    }

    /** Tell whoever is watching, once the transaction has committed. */
    public function announce(DetailingBooking $booking, string $change): void
    {
        $dates = array_values(array_unique(array_merge(
            [$booking->scheduled_date->toDateString()],
            DetailingBookingSlot::query()->where('booking_id', $booking->id)->distinct()->orderBy('date')->pluck('date')
                ->map(fn (mixed $date): string => $date instanceof CarbonImmutable ? $date->toDateString() : substr((string) (is_string($date) ? $date : ''), 0, 10))
                ->all(),
        )));

        event(new DetailingBookingChanged($booking->organization_id, $booking->branch_id, $booking->id, $change, $dates));
    }

    public static function inTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Detailing writes run inside the booking\'s transaction.');
        }
    }
}
