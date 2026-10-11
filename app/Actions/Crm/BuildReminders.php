<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Fleet\FleetQueries;
use App\Database\Cell;
use App\Domain\Crm\Reminders\AccountActivity;
use App\Domain\Crm\Reminders\CandidateBuilder;
use App\Domain\Crm\Reminders\DetailingVisit;
use App\Domain\Crm\Reminders\ExpiringDocument;
use App\Domain\Crm\Reminders\ReminderKind;
use App\Domain\Crm\Reminders\ServiceDue;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\DetailingPackage;
use App\Models\Document;
use App\Models\Message;
use App\Models\ReminderRule;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;

/**
 * The service-reminder run, for the organization in context: gather what is
 * on record, let the pure `CandidateBuilder` say what is worth sending, and
 * hand each candidate to the `Messenger`, which decides whether it may go.
 *
 * It reads the whole organization (every branch): a reminder is about a
 * customer, whichever branch last served them. Running it twice sends nothing
 * twice: the Messenger holds each reminder to its cooldown.
 */
final class BuildReminders
{
    public function __construct(
        private readonly FleetQueries $fleet,
        private readonly CrmSettings $settings,
        private readonly Messenger $messenger,
    ) {}

    /**
     * @return array{candidates: int, queued: int, skipped: array<string, int>}
     */
    public function run(): array
    {
        $settings = $this->settings->current();
        $rules = ReminderRule::query()->where('enabled', true)->with('template')->get()
            ->filter(fn (ReminderRule $rule): bool => $rule->template->is_active)->keyBy(fn (ReminderRule $rule): string => $rule->kind->value);
        $enabled = array_values($rules->map(fn (ReminderRule $rule): ReminderKind => $rule->kind)->all());
        if ($enabled === []) {
            return ['candidates' => 0, 'queued' => 0, 'skipped' => []];
        }

        $today = CarbonImmutable::now(Calendar::zone())->startOfDay();
        $lead = $rules->get(ReminderKind::DocumentExpiry->value)->lead_days ?? 30;
        $candidates = CandidateBuilder::build(
            $enabled,
            in_array(ReminderKind::PmsDue, $enabled, true) ? $this->serviceDue() : [],
            in_array(ReminderKind::DetailingRepeat, $enabled, true) ? $this->detailingVisits($today) : [],
            in_array(ReminderKind::DocumentExpiry, $enabled, true) ? $this->documents($today, $lead) : [],
            in_array(ReminderKind::LapsedCustomer, $enabled, true) ? $this->activity($today) : [],
            $lead,
            $settings->lapsed_after_days,
            $today,
        );

        $accounts = CustomerAccount::query()->whereIn('id', array_values(array_unique(array_map(fn ($c): string => $c->accountId, $candidates))))
            ->where('status', 'active')->get()->keyBy('id');
        $result = ['candidates' => count($candidates), 'queued' => 0, 'skipped' => []];
        foreach ($candidates as $candidate) {
            $account = $accounts->get($candidate->accountId);
            $rule = $rules->get($candidate->kind->value);
            if (! $account instanceof CustomerAccount || ! $rule instanceof ReminderRule) {
                continue;
            }
            $message = $this->messenger->queue($account, $rule->template, $candidate->values, 'reminder', $rule->id, $candidate->subjectType, $candidate->subjectId, $candidate->dedupeKey, $candidate->branchId, 'r');
            if ($message->status === Message::SKIPPED) {
                $reason = (string) $message->skip_reason;
                $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;
            } elseif ($message->wasRecentlyCreated) {
                $result['queued']++;
            } else {
                $result['skipped']['recently_sent'] = ($result['skipped']['recently_sent'] ?? 0) + 1;
            }
        }

        return $result;
    }

    /**
     * Every item the maintenance engine has in its due-soon or overdue band.
     *
     * @return list<ServiceDue>
     */
    private function serviceDue(): array
    {
        if (! $this->fleet->pmsActive()) {
            return [];
        }
        $vehicles = array_values(Vehicle::query()->whereNull('archived_at')->orderBy('id')->get()->all());
        $owner = [];
        foreach ($vehicles as $vehicle) {
            $owner[$vehicle->id] = $vehicle->customer_account_id;
        }

        $due = [];
        foreach ($this->fleet->fleetHealth($vehicles) as $health) {
            foreach ($health->items as $item) {
                if ($item->status !== 'ok') {
                    $due[] = new ServiceDue($owner[$health->vehicle->id], $health->vehicle->id, $health->vehicle->plateNumber, $item->task->name, $item->status === 'overdue', $item->daysRemaining);
                }
            }
        }

        return $due;
    }

    /**
     * The last finished job of each repeating package on each vehicle.
     *
     * @return list<DetailingVisit>
     */
    private function detailingVisits(CarbonImmutable $today): array
    {
        $packages = DetailingPackage::query()->whereNotNull('repeat_interval_days')->get()->keyBy('id');
        if ($packages->isEmpty()) {
            return [];
        }
        $upcoming = [];
        foreach (DetailingBooking::query()->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnGoing])
            ->where('scheduled_date', '>=', $today->toDateString())->get(['id', 'customer_account_id', 'branch_id']) as $booking) {
            $upcoming["{$booking->customer_account_id}:{$booking->branch_id}"] = true;
        }

        /** @var array<string, DetailingBooking> $latest */
        $latest = [];
        foreach (DetailingBooking::query()->whereIn('package_id', $packages->keys()->all())->where('status', BookingStatus::Completed)
            ->whereNotNull('completed_at')->orderBy('completed_at')->orderBy('id')->get() as $booking) {
            $vehicleKey = $booking->vehicle_id ?? mb_strtolower(trim((string) $booking->vehicle_label));
            $latest["{$booking->customer_account_id}|{$vehicleKey}|{$booking->package_id}"] = $booking;
        }

        $visits = [];
        foreach ($latest as $key => $booking) {
            $package = $packages->get($booking->package_id);
            if ($package === null || $booking->completed_at === null) {
                continue;
            }
            $visits[] = new DetailingVisit(
                $booking->customer_account_id,
                $booking->branch_id,
                $booking->package_id,
                $package->name,
                explode('|', $key)[1],
                (string) ($booking->vehicle_label ?? 'your vehicle'),
                Calendar::toDate($booking->completed_at),
                (int) $package->repeat_interval_days,
                isset($upcoming["{$booking->customer_account_id}:{$booking->branch_id}"]),
            );
        }

        return $visits;
    }

    /**
     * @return list<ExpiringDocument>
     */
    private function documents(CarbonImmutable $today, int $leadDays): array
    {
        $from = $today->subDays(CandidateBuilder::EXPIRED_GRACE_DAYS)->toDateString();
        $to = $today->addDays($leadDays)->toDateString();
        $documents = Document::query()->whereNotNull('expires_on')->whereBetween('expires_on', [$from, $to])->orderBy('id')->get();
        $plates = Vehicle::query()->whereIn('id', $documents->pluck('vehicle_id')->filter()->all())->pluck('plate_number', 'id');

        return array_values($documents->map(fn (Document $document): ExpiringDocument => new ExpiringDocument(
            $document->customer_account_id,
            $document->id,
            $document->name,
            $document->kind->label(),
            $document->vehicle_id === null ? null : (is_string($plate = $plates->get($document->vehicle_id)) ? $plate : null),
            (string) $document->expires_on?->toDateString(),
        ))->all());
    }

    /**
     * When each active customer was last served, and whether anything is ahead.
     *
     * @return list<AccountActivity>
     */
    private function activity(CarbonImmutable $today): array
    {
        $last = [];
        $note = function (string $accountId, ?string $date) use (&$last): void {
            if ($date !== null && ($last[$accountId] ?? '') < $date) {
                $last[$accountId] = $date;
            }
        };
        foreach (WorkOrder::query()->where('status', WorkOrderStatus::Closed)->whereNotNull('completed_on')
            ->selectRaw('customer_account_id, max(completed_on) as last_on')->groupBy('customer_account_id')->toBase()->get() as $row) {
            $note(Cell::string($row->customer_account_id), is_string($row->last_on) ? substr($row->last_on, 0, 10) : null);
        }
        foreach (DetailingBooking::query()->where('status', BookingStatus::Completed)->whereNotNull('completed_at')
            ->selectRaw('customer_account_id, max(completed_at) as last_at')->groupBy('customer_account_id')->toBase()->get() as $row) {
            $note(Cell::string($row->customer_account_id), is_string($row->last_at) ? Calendar::toDate(CarbonImmutable::parse($row->last_at)) : null);
        }

        $ahead = [];
        $open = [WorkOrderStatus::PendingApproval, WorkOrderStatus::Approved, WorkOrderStatus::PartiallyApproved, WorkOrderStatus::Scheduled, WorkOrderStatus::InProgress];
        foreach (WorkOrder::query()->whereIn('status', $open)->distinct()->pluck('customer_account_id') as $accountId) {
            $ahead[Cell::string($accountId)] = true;
        }
        foreach (DetailingBooking::query()->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed, BookingStatus::OnGoing])
            ->where('scheduled_date', '>=', $today->toDateString())->distinct()->pluck('customer_account_id') as $accountId) {
            $ahead[Cell::string($accountId)] = true;
        }

        $activity = [];
        foreach (CustomerAccount::query()->where('status', 'active')->orderBy('id')->pluck('id') as $accountId) {
            $accountId = Cell::string($accountId);
            $activity[] = new AccountActivity($accountId, $last[$accountId] ?? null, isset($ahead[$accountId]));
        }

        return $activity;
    }
}
