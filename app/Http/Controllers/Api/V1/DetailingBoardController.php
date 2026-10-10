<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Detailing\DetailingBranches;
use App\Actions\Detailing\DetailingQueries;
use App\Actions\Detailing\Realtime;
use App\Domain\Detailing\MonitorChannel;
use App\Http\Requests\Detailing\BranchRequest;
use App\Http\Resources\DetailingBookingResource;
use App\Models\DetailingBooking;
use App\Models\DetailingPackage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * A detailing branch's day, shaped for its screens (staff, `detailing:view`):
 * the schedule by bay and by detailer, the shop monitor, and which starts are
 * free for a package. The branch is `branch_id`, else the one the session
 * works in.
 */
final class DetailingBoardController
{
    public function __construct(
        private readonly DetailingQueries $queries,
        private readonly DetailingBranches $branches,
    ) {}

    /**
     * The schedule
     *
     * `from`/`to` (default today, at most 31 days): every booking that sits
     * on those days, the bays and detailers to lay them out by, the blocked
     * slots, and how many heads each slot holds against the pool.
     */
    public function schedule(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewBranch', [DetailingBooking::class, $branch->id]);
        [$from, $to] = $request->span();
        $schedule = $this->queries->schedule($branch, $from, $to);

        return new JsonResponse(['data' => [
            ...$schedule,
            'bookings' => DetailingBookingResource::collection($schedule['bookings'])->resolve($request),
        ]]);
    }

    /**
     * The shop monitor
     *
     * The confirmed, on-going and completed jobs that sit on `date` (default
     * today), as the wall screen shows them.
     */
    public function monitor(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewBranch', [DetailingBooking::class, $branch->id]);

        return new JsonResponse(['data' => $this->queries->monitor($branch, $request->day())]);
    }

    /**
     * How to listen for changes
     *
     * The branch's realtime channel and what a browser needs to join it.
     * `enabled: false` means no realtime server is configured: read again
     * every `poll_seconds`.
     */
    public function realtime(BranchRequest $request, Realtime $realtime): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewBranch', [DetailingBooking::class, $branch->id]);

        return new JsonResponse(['data' => ['branch_id' => $branch->id] + $realtime->client($branch->id)]);
    }

    /**
     * Authorise a socket
     *
     * `detailing:view` in the channel's branch. Signs `socket_id` onto
     * `channel_name` (`private-detailing.branch.{id}`).
     */
    public function authorizeChannel(Request $request, Realtime $realtime): JsonResponse
    {
        $socket = $request->input('socket_id');
        $channel = $request->input('channel_name');
        $branchId = MonitorChannel::branchOf(is_string($channel) ? $channel : '')
            ?? throw ValidationException::withMessages(['channel_name' => 'That is not a detailing channel.']);
        Gate::authorize('viewBranch', [DetailingBooking::class, $branchId]);

        return new JsonResponse($realtime->authorize($branchId, is_string($socket) ? $socket : '', is_string($channel) ? $channel : ''));
    }

    /**
     * Free starts for a package
     *
     * `package_id`, `size_class`, `date`: every grid slot as a start, whether
     * it is free and why not (`blocked`, `capacity`, `overflow`), the days
     * the job would take, when it would end, and which detailers are already
     * busy in those hours. `start_time` answers for that one time instead
     * (it need not be on the grid); `except_booking_id` leaves a booking out
     * when re-timing it.
     */
    public function availability(BranchRequest $request): JsonResponse
    {
        $branch = $this->branches->resolve($request->branchId());
        Gate::authorize('viewBranch', [DetailingBooking::class, $branch->id]);
        $packageId = $request->packageId();
        $size = $request->size();
        if ($packageId === null || $size === null) {
            throw ValidationException::withMessages(['package_id' => 'Say which package and which vehicle size.']);
        }
        $package = DetailingPackage::query()->where('branch_id', $branch->id)->findOrFail($packageId);

        return new JsonResponse(['data' => $this->queries->availability($branch, $package, $size, $request->day(), $request->exceptBookingId(), $request->startMinutes())]);
    }
}
