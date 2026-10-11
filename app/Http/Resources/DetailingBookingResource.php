<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Actions\Detailing\DetailingJournal;
use App\Actions\Detailing\DetailingQueries;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\Materials;
use App\Domain\Detailing\SlotGrid;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Invoicing\Invoicing;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingAddon;
use App\Models\DetailingBookingDetailer;
use App\Models\DetailingBookingStatusLog;
use App\Models\DetailingInspection;
use App\Tenancy\TenantManager;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A detailing booking and its job card. Everything a screen would otherwise
 * work out is here (R2): where the job sits day by day, when it should
 * finish (or did), what it comes to, how far the checklist is, whether it
 * still needs its inspection, and which steps are open to the caller now
 * (`next`). Staff only; `materials` and `margin` appear once the job's
 * materials have left the shelf.
 *
 * @property DetailingBooking $resource
 */
final class DetailingBookingResource extends JsonResource
{
    public function __construct(DetailingBooking $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $booking = $this->resource;
        $booking->loadMissing(DetailingQueries::WITH);

        $addons = array_values($booking->addons->map(function (DetailingBookingAddon $addon) use ($booking): array {
            $line = new InvoiceLineDraft(InvoiceLineKind::Addon, $addon->name, (string) $addon->quantity, $addon->unit_price_cents, detailingBookingId: $booking->id);

            return [
                'id' => $addon->id,
                'addon_id' => $addon->addon_id,
                'name' => $addon->name,
                'quantity' => Invoicing::quantity((string) $addon->quantity),
                'unit_price_cents' => $addon->unit_price_cents,
                'line_total_cents' => $line->totalCents(),
            ];
        })->all());
        $addonsTotal = array_sum(array_map(fn (array $addon): int => $addon['line_total_cents'], $addons));
        $total = $booking->package_price_cents + $addonsTotal;

        $done = count(array_filter($booking->checklist, fn (array $line): bool => $line['done']));
        $checkIn = $booking->inspections->first(fn (DetailingInspection $i): bool => $i->stage === DetailingInspection::STAGE_CHECK_IN);
        $checkOut = $booking->inspections->first(fn (DetailingInspection $i): bool => $i->stage === DetailingInspection::STAGE_CHECK_OUT);
        $needsInspection = $checkIn === null && $booking->inspection_override_reason === null && $this->inspectionRequired($request, $booking);

        $link = $booking->standingInvoiceLink();
        $invoice = $link?->invoice;
        $first = $booking->slots->first();
        $completed = $booking->status === BookingStatus::Completed;
        // Settled by a prepaid credit (Phase 11): there is nothing left to invoice.
        $redemption = $booking->prepaidRedemption;
        $signedOff = $booking->qc_status === DetailingBooking::QC_PASSED;
        $worksIt = $this->callerWorkedIt($booking);

        return [
            'id' => $booking->id,
            'reference' => $booking->reference,
            'branch_id' => $booking->branch_id,
            'status' => $booking->status->value,
            'status_label' => $booking->status->label(),
            'source' => $booking->source,
            'customer_account_id' => $booking->customer_account_id,
            'customer' => ['name' => $booking->customer_name, 'email' => $booking->customer_email, 'phone' => $booking->customer_phone],
            'vehicle_id' => $booking->vehicle_id,
            'vehicle_label' => $booking->vehicle_label,
            'size_class' => $booking->size_class->value,
            'size_label' => $booking->size_class->label(),
            'package' => [
                'id' => $booking->package_id,
                'name' => $booking->package_name,
                'category_name' => $booking->category_name,
                'price_cents' => $booking->package_price_cents,
                'duration_text' => $booking->duration_text,
            ],
            // The day and time asked for; `days` is where the work sits.
            'scheduled_date' => $booking->scheduled_date->toDateString(),
            'start_minutes' => $booking->start_minutes,
            'start_time' => SlotGrid::time($booking->start_minutes),
            'start_label' => SlotGrid::label($booking->start_minutes),
            'first_day' => $first?->date->toDateString(),
            'days' => DetailingQueries::days($booking),
            'spans_days' => $booking->slots->map(fn ($slot): string => $slot->date->toDateString())->unique()->count() > 1,
            'finish' => DetailingQueries::finish($booking),
            'min_detailers' => $booking->min_detailers,
            'detailers_count' => $booking->detailers_count,
            'detailers' => array_values($booking->detailers->map(fn (DetailingBookingDetailer $row): array => ['id' => $row->technician_id, 'name' => $row->technician->name])->all()),
            'bay' => $booking->bay === null ? null : ['id' => $booking->bay->id, 'name' => $booking->bay->name],
            'addons' => $addons,
            'totals' => ['package_cents' => $booking->package_price_cents, 'addons_cents' => $addonsTotal, 'total_cents' => $total],
            'notes' => $booking->notes,
            'cancellation_reason' => $booking->cancellation_reason,
            'checklist' => array_map(fn (int $index, array $line): array => ['index' => $index] + $line, array_keys($booking->checklist), $booking->checklist),
            'checklist_progress' => ['done' => $done, 'total' => count($booking->checklist)],
            'started_at' => $booking->started_at?->utc()->toIso8601ZuluString(),
            'completed_at' => $booking->completed_at?->utc()->toIso8601ZuluString(),
            'inspection' => [
                'needed_before_start' => $needsInspection,
                'check_in_id' => $checkIn?->id,
                'check_out_id' => $checkOut?->id,
                'override_reason' => $booking->inspection_override_reason,
                'overridden_by_name' => $booking->inspection_overridden_by_name,
            ],
            'qc' => [
                'status' => $booking->qc_status,
                'by_name' => $booking->qc_by_name,
                'at' => $booking->qc_at?->utc()->toIso8601ZuluString(),
                'notes' => $booking->qc_notes,
            ],
            'materials' => $booking->materials_consumed_at === null ? null : [
                'consumed_at' => $booking->materials_consumed_at->utc()->toIso8601ZuluString(),
                'cost_cents' => $booking->materials_cost_cents ?? 0,
            ],
            'margin' => $booking->materials_consumed_at === null ? null : Materials::margin($total, $booking->materials_cost_cents ?? 0),
            'invoice' => $invoice === null ? null : [
                'id' => $invoice->id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'total_due_cents' => $invoice->total_due_cents,
                'balance_cents' => $invoice->balanceCents(),
            ],
            'prepaid' => $redemption === null ? null : [
                'package_id' => $redemption->prepaid_package_id,
                'reference' => $redemption->package->reference,
                'plan_name' => $redemption->package->plan_name,
                'amount_cents' => $redemption->amount_cents,
                'redeemed_on' => $redemption->redeemed_on->toDateString(),
            ],
            // What the job is waiting for; the API still checks each step when it is taken.
            'next' => [
                'needs_inspection' => $needsInspection,
                'can_sign_off' => $completed && ! $signedOff && $invoice === null && $redemption === null && ! $worksIt,
                'sign_off_blocked_reason' => $completed && ! $signedOff && $worksIt ? 'A job is signed off by someone other than the detailer who worked it.' : null,
                'can_invoice' => $completed && $signedOff && $invoice === null && $redemption === null,
                'can_check_out' => $completed && $signedOff && $redemption === null && ($invoice === null || in_array($invoice->status, [InvoiceStatus::Draft, InvoiceStatus::Issued, InvoiceStatus::PartiallyPaid], true)),
                // A prepaid credit may settle it instead of an invoice (`POST /crm/prepaid-packages/{id}/redeem`).
                'can_redeem' => $completed && $signedOff && $invoice === null && $redemption === null,
                'locked_by_invoice' => $invoice !== null || $redemption !== null,
            ],
            'status_log' => $booking->relationLoaded('statusLogs')
                ? array_values($booking->statusLogs->map(fn (DetailingBookingStatusLog $log): array => [
                    'id' => $log->id,
                    'from_status' => $log->from_status,
                    'to_status' => $log->to_status,
                    'notes' => $log->notes,
                    'actor_name' => $log->actor_name,
                    'changed_at' => $log->changed_at->utc()->toIso8601ZuluString(),
                ])->all())
                : null,
            'created_by_name' => $booking->created_by_name,
            'created_at' => $booking->created_at->utc()->toIso8601ZuluString(),
            'updated_at' => $booking->updated_at->utc()->toIso8601ZuluString(),
        ];
    }

    /** The branch's setting, read once per request however many bookings are listed. */
    private function inspectionRequired(Request $request, DetailingBooking $booking): bool
    {
        $key = 'detailing.inspection_required.'.$booking->branch_id;
        if (! $request->attributes->has($key)) {
            $request->attributes->set($key, app(DetailingJournal::class)->settings($booking->organization_id, $booking->branch_id)->inspection_required);
        }

        return $request->attributes->get($key) === true;
    }

    /** Whether the caller is one of the booking's named detailers (and so may not sign it off). */
    private function callerWorkedIt(DetailingBooking $booking): bool
    {
        $userId = app(TenantManager::class)->context()?->userId;
        if ($userId === null) {
            return false;
        }

        return $booking->detailers->contains(fn (DetailingBookingDetailer $row): bool => $row->technician->user_id === $userId);
    }
}
