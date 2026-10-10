<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Detailing\BillBooking;
use App\Actions\Detailing\ChangeBookingStatus;
use App\Actions\Detailing\DetailingBranches;
use App\Actions\Detailing\DetailingQueries;
use App\Actions\Detailing\ManageJobCard;
use App\Actions\Detailing\ReassignDetailers;
use App\Actions\Detailing\RecordInspection;
use App\Actions\Detailing\TakeBooking;
use App\Domain\Detailing\BookingStatus;
use App\Http\Requests\Detailing\AssignDetailersRequest;
use App\Http\Requests\Detailing\BookingAddonsRequest;
use App\Http\Requests\Detailing\ChangeBookingStatusRequest;
use App\Http\Requests\Detailing\CheckoutRequest;
use App\Http\Requests\Detailing\InspectionPhotoRequest;
use App\Http\Requests\Detailing\ListBookingsRequest;
use App\Http\Requests\Detailing\RecordInspectionRequest;
use App\Http\Requests\Detailing\SignOffRequest;
use App\Http\Requests\Detailing\TakeBookingRequest;
use App\Http\Requests\Detailing\TickChecklistRequest;
use App\Http\Requests\Detailing\UpdateBookingRequest;
use App\Http\Resources\DetailingBookingCollection;
use App\Http\Resources\DetailingBookingResource;
use App\Http\Resources\DetailingInspectionResource;
use App\Http\Resources\InvoiceResource;
use App\Http\Resources\PaymentResource;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\DetailingInspection;
use App\Models\DetailingInspectionPhoto;
use App\Models\DetailingPackage;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Detailing bookings and their job cards (staff, `detailing` module): take a
 * booking, move it through its day, inspect the vehicle, tick the package
 * checklist, sign the work off, and bill it.
 */
final class DetailingBookingController
{
    /**
     * List bookings
     *
     * `detailing:view`. Newest day first, over the detailing branches the
     * session works in (`branch_id` narrows it). `meta.summary` counts the
     * same filters by status and says what today holds.
     */
    public function index(ListBookingsRequest $request, DetailingQueries $queries): DetailingBookingCollection
    {
        Gate::authorize('viewAny', DetailingBooking::class);
        $filters = $request->filters();

        return (new DetailingBookingCollection($queries->bookings($filters, $request->perPage())))
            ->additional(['meta' => ['summary' => $queries->summary(['status' => []] + $filters)]]);
    }

    /**
     * Take a booking
     *
     * `detailing:book` in the branch. The API plans the days (a job that does
     * not fit before closing rolls into the next morning), prices the package
     * for the vehicle's size, and reserves the detailers. A slot that has
     * filled, a blocked slot or a detailer already on another job is 409 with
     * `details.reason` (`full`, `blocked`, `detailer_conflict`). The booking
     * is numbered from the `detailing_booking` series (BK-).
     */
    public function store(TakeBookingRequest $request, TakeBooking $bookings, DetailingBranches $branches): JsonResponse
    {
        $branch = $branches->resolve($request->branchId());
        Gate::authorize('create', [DetailingBooking::class, $branch->id]);
        $account = CustomerAccount::query()->findOrFail($request->accountId());
        Gate::authorize('createWorkFor', $account);

        $booking = $bookings->handle(
            $branch,
            $account,
            $request->vehicleId() === null ? null : Vehicle::query()->findOrFail($request->vehicleId()),
            DetailingPackage::query()->where('branch_id', $branch->id)->findOrFail($request->packageId()),
            $request->size(),
            $request->booking(),
        );

        return (new DetailingBookingResource($booking->load('statusLogs')))->response()->setStatusCode(201);
    }

    /**
     * Show a booking
     *
     * With its status log.
     */
    public function show(DetailingBooking $detailingBooking): DetailingBookingResource
    {
        Gate::authorize('view', $detailingBooking);

        return new DetailingBookingResource($detailingBooking->load('statusLogs'));
    }

    /**
     * Edit a booking
     *
     * `detailing:book`. Its bay, notes, contact details and vehicle.
     */
    public function update(UpdateBookingRequest $request, DetailingBooking $detailingBooking, ManageJobCard $card): DetailingBookingResource
    {
        Gate::authorize('update', $detailingBooking);

        return new DetailingBookingResource($card->update($detailingBooking, $request->changes())->load('statusLogs'));
    }

    /**
     * Change a booking's status
     *
     * Starting (`on_going`) and completing need `detailing:work` or
     * `detailing:book`; every other move `detailing:book`. Any status may
     * follow any other. Starting needs the check-in inspection (409,
     * `details.reason` = `inspection_required`) unless
     * `inspection_override_reason` is given. Completing releases the slots
     * the job no longer needs and takes its materials from stock, once.
     */
    public function status(ChangeBookingStatusRequest $request, DetailingBooking $detailingBooking, ChangeBookingStatus $change): DetailingBookingResource
    {
        $to = $request->status();
        Gate::authorize($to === BookingStatus::OnGoing || $to === BookingStatus::Completed ? 'changeStatus' : 'update', $detailingBooking);

        return new DetailingBookingResource($change->handle($detailingBooking, $to, $request->reason(), $request->inspectionOverride())->load('statusLogs'));
    }

    /**
     * Name a booking's detailers
     *
     * `detailing:book`. Checked across the booking's whole span; the heads
     * reserved follow the names.
     */
    public function detailers(AssignDetailersRequest $request, DetailingBooking $detailingBooking, ReassignDetailers $reassign): DetailingBookingResource
    {
        Gate::authorize('update', $detailingBooking);

        return new DetailingBookingResource($reassign->handle($detailingBooking, $request->detailerIds())->load('statusLogs'));
    }

    /**
     * Replace a booking's extras
     *
     * `detailing:book`. The whole list; a listed add-on is priced for the
     * booking's size unless a price is typed.
     */
    public function addons(BookingAddonsRequest $request, DetailingBooking $detailingBooking, ManageJobCard $card): DetailingBookingResource
    {
        Gate::authorize('update', $detailingBooking);

        return new DetailingBookingResource($card->addons($detailingBooking, $request->addons())->load('statusLogs'));
    }

    /**
     * Tick the checklist
     *
     * `detailing:work`.
     */
    public function checklist(TickChecklistRequest $request, DetailingBooking $detailingBooking, ManageJobCard $card): DetailingBookingResource
    {
        Gate::authorize('work', $detailingBooking);

        return new DetailingBookingResource($card->tick($detailingBooking, $request->index(), $request->done())->load('statusLogs'));
    }

    /**
     * Sign a job off
     *
     * `detailing:qc`, on a completed job, by someone other than a detailer
     * named on it (403 otherwise). `passed: false` needs `notes`.
     */
    public function signOff(SignOffRequest $request, DetailingBooking $detailingBooking, ManageJobCard $card): DetailingBookingResource
    {
        Gate::authorize('signOff', $detailingBooking);

        return new DetailingBookingResource($card->signOff($detailingBooking, $request->passed(), $request->notes())->load('statusLogs'));
    }

    /**
     * List a booking's inspections
     */
    public function inspections(DetailingBooking $detailingBooking): JsonResponse
    {
        Gate::authorize('view', $detailingBooking);

        return new JsonResponse(['data' => DetailingInspectionResource::collection($detailingBooking->inspections()->get())->resolve(request())]);
    }

    /**
     * Record an inspection
     *
     * `detailing:work`. One per stage (`check_in`, `check_out`); sending a
     * stage again replaces it and, when `damage_points` is sent, its map. A
     * `mileage` is recorded as an odometer reading of the booking's vehicle:
     * an implausible one is 422 until sent again with `confirm_mileage`.
     */
    public function inspect(RecordInspectionRequest $request, DetailingBooking $detailingBooking, RecordInspection $inspections): DetailingInspectionResource
    {
        Gate::authorize('work', $detailingBooking);

        return new DetailingInspectionResource($inspections->handle($detailingBooking, $request->stage(), $request->inspection()));
    }

    /**
     * Add an inspection photo
     *
     * `detailing:work`. Multipart; an image, `phase` `before` or `after`.
     */
    public function addPhoto(InspectionPhotoRequest $request, DetailingBooking $detailingBooking, DetailingInspection $inspection, RecordInspection $inspections): JsonResponse
    {
        Gate::authorize('work', $detailingBooking);
        $inspections->addPhoto($inspection, $request->phase(), $request->photo(), $request->caption());

        return (new DetailingInspectionResource($inspection->refresh()))->response()->setStatusCode(201);
    }

    /**
     * Remove an inspection photo
     */
    public function removePhoto(DetailingBooking $detailingBooking, DetailingInspection $inspection, DetailingInspectionPhoto $photo, RecordInspection $inspections): Response
    {
        Gate::authorize('work', $detailingBooking);
        $inspections->removePhoto($photo);

        return response()->noContent();
    }

    /**
     * Invoice a booking
     *
     * `detailing:book` and `billing:manage`. A draft invoice for a completed,
     * signed-off job: the package at the price it was booked at, and each
     * extra as a line. A booking is invoiced once (409 otherwise).
     */
    public function invoice(DetailingBooking $detailingBooking, BillBooking $billing): JsonResponse
    {
        Gate::authorize('update', $detailingBooking);
        Gate::authorize('create', [Invoice::class, CustomerAccount::query()->findOrFail($detailingBooking->customer_account_id), $detailingBooking->branch_id]);

        return (new InvoiceResource($billing->invoice($detailingBooking)))->response()->setStatusCode(201);
    }

    /**
     * Check a booking out at the counter
     *
     * `detailing:book` and `billing:manage`. Invoice and payment in one
     * action: raises the invoice if there is none, issues it, and records the
     * payment against it, all or nothing. Requires an `Idempotency-Key`.
     */
    public function checkout(CheckoutRequest $request, DetailingBooking $detailingBooking, BillBooking $billing): JsonResponse
    {
        Gate::authorize('update', $detailingBooking);
        $account = CustomerAccount::query()->findOrFail($detailingBooking->customer_account_id);
        Gate::authorize('create', [Invoice::class, $account, $detailingBooking->branch_id]);
        Gate::authorize('create', [Payment::class, $account, $detailingBooking->branch_id]);

        $result = $billing->checkout($detailingBooking, $request->payment());

        return new JsonResponse(['data' => [
            'booking' => (new DetailingBookingResource($detailingBooking->refresh()->load('statusLogs')))->resolve($request),
            'invoice' => (new InvoiceResource($result['invoice']))->resolve($request),
            'payment' => $result['payment'] === null ? null : (new PaymentResource($result['payment']))->resolve($request),
        ]], 201);
    }
}
