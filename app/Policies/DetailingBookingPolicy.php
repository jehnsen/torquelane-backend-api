<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\DetailingBooking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Bookings and their job cards. `detailing:view` reads; `detailing:book`
 * takes and manages bookings (the source's "admin" tier); `detailing:work`
 * works the job card; `detailing:qc` signs it off, and never for one's own work.
 */
final class DetailingBookingPolicy extends DetailingPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->anywhere(Capability::DetailingView);
    }

    public function view(User $user, DetailingBooking $booking): Response
    {
        return $this->in($booking->branch_id, Capability::DetailingView);
    }

    /** Read a branch's schedule, monitor or availability. */
    public function viewBranch(User $user, string $branchId): Response
    {
        return $this->in($branchId, Capability::DetailingView);
    }

    /** Take a booking in `$branchId`. */
    public function create(User $user, string $branchId): Response
    {
        return $this->in($branchId, Capability::DetailingBook);
    }

    /** Edit, confirm, cancel, name detailers, change extras, invoice, check out. */
    public function update(User $user, DetailingBooking $booking): Response
    {
        return $this->in($booking->branch_id, Capability::DetailingBook);
    }

    /** Inspect, start, tick the checklist, complete. */
    public function work(User $user, DetailingBooking $booking): Response
    {
        return $this->in($booking->branch_id, Capability::DetailingWork);
    }

    /** Whoever may book or work a job may move its status; which statuses each may set is `ChangeBookingStatusRequest`'s. */
    public function changeStatus(User $user, DetailingBooking $booking): Response
    {
        $books = $this->in($booking->branch_id, Capability::DetailingBook);

        return $books->allowed() ? $books : $this->in($booking->branch_id, Capability::DetailingWork);
    }

    public function signOff(User $user, DetailingBooking $booking): Response
    {
        return $this->in($booking->branch_id, Capability::DetailingQc);
    }
}
