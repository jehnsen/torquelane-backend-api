<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Domain\Crm\ConsentLedger;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Crm\Timeline;
use App\Domain\Crm\TimelineEntry;
use App\Domain\Invoicing\InvoiceStatus;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Http\Resources\CrmPresenter;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\CrmNote;
use App\Models\CrmTask;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\FeedbackRequest;
use App\Models\Invoice;
use App\Models\Membership;
use App\Models\Message;
use App\Models\Payment;
use App\Models\PrepaidPackage;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Customer 360: everything on record about one customer account, in one read.
 *
 * It crosses branches on purpose. A member of staff pinned to one branch sees,
 * here, the customer's jobs, invoices, payments and bookings at EVERY branch
 * of the organization: the customer is the organization's, and whoever is
 * talking to them needs the whole picture. (It never crosses organizations:
 * every query runs inside the tenant scope.) Nothing here can be changed from
 * this view; each record is edited where it lives.
 *
 * The timeline is built on read from those same records (`Timeline::merge`).
 */
final class Customer360
{
    public const int TIMELINE_LIMIT = 150;

    private const array CONSENT_LABELS = [
        'service_records' => 'Keeping service records',
        'service_reminders' => 'Service reminders',
        'marketing' => 'Marketing',
        'vehicle_history_sharing' => 'Sharing vehicle history',
    ];

    public function __construct(private readonly ManageLoyalty $loyalty) {}

    /**
     * @return array<string, mixed>
     */
    public function for(CustomerAccount $account): array
    {
        $branches = Branch::query()->pluck('name', 'id');
        $branchName = fn (?string $id): ?string => $id === null ? null : (is_string($name = $branches->get($id)) ? $name : null);

        $vehicles = Vehicle::query()->where('customer_account_id', $account->id)->orderBy('plate_number')->get();
        $orders = WorkOrder::query()->where('customer_account_id', $account->id)->where('status', '!=', WorkOrderStatus::Draft)->orderByDesc('opened_on')->orderByDesc('id')->limit(200)->get();
        $invoices = Invoice::query()->where('customer_account_id', $account->id)->where('status', '!=', 'draft')->orderByDesc('issue_date')->orderByDesc('id')->limit(200)->get();
        $payments = Payment::query()->where('customer_account_id', $account->id)->orderByDesc('received_on')->orderByDesc('id')->limit(200)->get();
        $bookings = DetailingBooking::query()->where('customer_account_id', $account->id)->orderByDesc('scheduled_date')->orderByDesc('id')->limit(200)->get();
        $consents = Consent::query()->where('customer_account_id', $account->id)->orderBy('captured_at')->orderBy('id')->get();
        $notes = CrmNote::query()->where('customer_account_id', $account->id)->orderByDesc('pinned')->orderByDesc('created_at')->limit(100)->get();
        $tasks = CrmTask::query()->where('customer_account_id', $account->id)->with(['assignee', 'customerAccount', 'lead'])->orderByRaw("status = 'open' desc")->orderBy('due_on')->orderByDesc('created_at')->limit(100)->get();
        $messages = Message::query()->where('customer_account_id', $account->id)->orderByDesc('created_at')->limit(100)->get();
        $feedback = FeedbackRequest::query()->where('customer_account_id', $account->id)->orderByDesc('requested_at')->limit(50)->get();
        $memberships = Membership::query()->where('customer_account_id', $account->id)->with('tier')->orderByDesc('started_on')->orderByDesc('id')->get();
        $packages = PrepaidPackage::query()->where('customer_account_id', $account->id)->orderByDesc('sold_on')->orderByDesc('id')->get();
        $current = $this->loyalty->membershipOf($account->id);

        $billed = array_sum(array_map(fn (Invoice $i): int => $i->status === InvoiceStatus::Void ? 0 : $i->total_due_cents, array_values($invoices->all())));
        $paid = array_sum(array_map(fn (Payment $p): int => $p->status === PaymentStatus::Posted ? $p->amount_cents : 0, array_values($payments->all())));
        $lastVisit = max(
            (string) $orders->where('status', WorkOrderStatus::Closed)->max(fn (WorkOrder $o): string => (string) $o->completed_on?->toDateString()),
            (string) $bookings->max(fn (DetailingBooking $b): string => $b->completed_at === null ? '' : Calendar::toDate($b->completed_at)),
        );
        $vehicleLabel = [];
        foreach ($vehicles as $vehicle) {
            $vehicleLabel[$vehicle->id] = $vehicle->plate_number;
        }

        $accountDecisions = array_values($consents->whereNull('contact_id')->map(fn (Consent $c) => $c->toDecision())->all());
        $currentConsent = ConsentLedger::current($accountDecisions);
        $channelOf = $consents->keyBy('id');

        return [
            'account' => [
                'id' => $account->id,
                'display_name' => $account->display_name,
                'account_type' => $account->account_type,
                'status' => $account->status,
                'tags' => $account->tags,
                'is_dealer' => in_array('dealer', array_map(mb_strtolower(...), $account->tags), true),
                'email' => $account->email ?? $account->contact_email,
                'mobile' => $account->mobile,
                'contact_name' => $account->contact_name,
                'payment_terms_days' => $account->payment_terms_days,
                'credit_limit_cents' => $account->credit_limit_cents,
                'source' => $account->source,
                'customer_since' => $account->created_at->utc()->toIso8601ZuluString(),
            ],
            'summary' => [
                'vehicles' => $vehicles->whereNull('archived_at')->count(),
                'jobs' => $orders->count(),
                'open_jobs' => $orders->whereNotIn('status', [WorkOrderStatus::Closed, WorkOrderStatus::Cancelled, WorkOrderStatus::Declined])->count(),
                'bookings' => $bookings->count(),
                'billed_cents' => $billed,
                'paid_cents' => $paid,
                'balance_cents' => array_sum(array_map(fn (Invoice $i): int => $i->balanceCents(), array_values($invoices->all()))),
                'last_visit_on' => $lastVisit === '' ? null : $lastVisit,
                'points' => $this->loyalty->pointsBalance($account->id),
                'open_tasks' => $tasks->where('status', CrmTask::OPEN)->count(),
                'last_score' => $feedback->whereNotNull('score')->first()?->score,
            ],
            'contacts' => array_values(Contact::query()->where('customer_account_id', $account->id)->orderByDesc('is_primary')->orderBy('name')->get()->map(fn (Contact $c): array => [
                'id' => $c->id, 'name' => $c->name, 'role' => $c->role, 'mobile' => $c->mobile, 'email' => $c->email, 'is_primary' => $c->is_primary, 'receives_reminders' => $c->receives_reminders,
            ])->all()),
            'vehicles' => array_values($vehicles->map(fn (Vehicle $v): array => [
                'id' => $v->id, 'plate_number' => $v->plate_number, 'make' => $v->make, 'model' => $v->model, 'year' => $v->year, 'size_class' => $v->size_class, 'status' => $v->status, 'archived' => $v->archived_at !== null,
            ])->all()),
            'work_orders' => array_values($orders->map(fn (WorkOrder $o): array => [
                'id' => $o->id, 'reference' => $o->reference, 'title' => $o->title, 'status' => $o->status->value, 'vehicle_id' => $o->vehicle_id, 'plate_number' => $vehicleLabel[$o->vehicle_id] ?? null,
                'branch_id' => $o->assigned_branch_id ?? $o->branch_id, 'branch_name' => $branchName($o->assigned_branch_id ?? $o->branch_id),
                'opened_on' => $o->opened_on->toDateString(), 'completed_on' => $o->completed_on?->toDateString(), 'total_cents' => $o->labor_cost_cents + $o->parts_cost_cents,
            ])->all()),
            'invoices' => array_values($invoices->map(fn (Invoice $i): array => [
                'id' => $i->id, 'number' => $i->number, 'status' => $i->status->value, 'branch_id' => $i->branch_id, 'branch_name' => $branchName($i->branch_id),
                'issue_date' => $i->issue_date?->toDateString(), 'due_date' => $i->due_date?->toDateString(), 'total_due_cents' => $i->total_due_cents, 'balance_cents' => $i->balanceCents(),
            ])->all()),
            'payments' => array_values($payments->map(fn (Payment $p): array => [
                'id' => $p->id, 'number' => $p->number, 'status' => $p->status->value, 'method' => $p->method->value, 'method_label' => $p->method->label(), 'amount_cents' => $p->amount_cents,
                'received_on' => $p->received_on->toDateString(), 'branch_id' => $p->branch_id, 'branch_name' => $branchName($p->branch_id),
            ])->all()),
            'bookings' => array_values($bookings->map(fn (DetailingBooking $b): array => [
                'id' => $b->id, 'reference' => $b->reference, 'package_name' => $b->package_name, 'status' => $b->status->value, 'status_label' => $b->status->label(), 'vehicle_label' => $b->vehicle_label,
                'scheduled_date' => $b->scheduled_date->toDateString(), 'branch_id' => $b->branch_id, 'branch_name' => $branchName($b->branch_id), 'package_price_cents' => $b->package_price_cents,
            ])->all()),
            'consents' => array_map(function (ConsentPurpose $purpose) use ($currentConsent, $channelOf): array {
                $decision = $currentConsent[$purpose->value];
                $row = $decision === null ? null : $channelOf->get($decision->id);

                return [
                    'purpose' => $purpose->value,
                    'label' => self::CONSENT_LABELS[$purpose->value],
                    'granted' => $decision?->granted,
                    'captured_at' => $row?->captured_at->utc()->toIso8601ZuluString(),
                    'channel' => $row?->channel->value,
                ];
            }, ConsentPurpose::cases()),
            'notes' => array_values($notes->map(CrmPresenter::note(...))->all()),
            'tasks' => array_values($tasks->map(CrmPresenter::task(...))->all()),
            'messages' => array_values($messages->map(CrmPresenter::message(...))->all()),
            'feedback' => array_values($feedback->map(CrmPresenter::feedback(...))->all()),
            'membership' => $current === null ? null : CrmPresenter::membership($current),
            'memberships' => array_values($memberships->map(CrmPresenter::membership(...))->all()),
            'packages' => array_values($packages->map(CrmPresenter::package(...))->all()),
            'timeline' => array_map(fn (TimelineEntry $e): array => [
                'at' => CarbonImmutable::instance($e->at)->utc()->toIso8601ZuluString(),
                'kind' => $e->kind,
                'id' => $e->id,
                'title' => $e->title,
                'detail' => $e->detail,
                'href' => $e->href,
                'branch_name' => $branchName($e->branchId),
                'amount_cents' => $e->amountCents,
            ], array_slice(Timeline::merge(
                array_values($orders->map(fn (WorkOrder $o): TimelineEntry => new TimelineEntry(self::day($o->completed_on ?? $o->opened_on), 'work_order', $o->id, "{$o->reference}: {$o->title}", ($vehicleLabel[$o->vehicle_id] ?? '').' · '.str_replace('_', ' ', $o->status->value), "/work-orders/{$o->id}", $o->assigned_branch_id ?? $o->branch_id, $o->labor_cost_cents + $o->parts_cost_cents))->all()),
                array_values($invoices->map(fn (Invoice $i): TimelineEntry => new TimelineEntry($i->issued_at ?? $i->created_at, 'invoice', $i->id, "Invoice {$i->number}", str_replace('_', ' ', $i->status->value), "/invoices/{$i->id}", $i->branch_id, $i->total_due_cents))->all()),
                array_values($payments->map(fn (Payment $p): TimelineEntry => new TimelineEntry($p->created_at, 'payment', $p->id, "Payment {$p->number}", $p->method->label().($p->status === PaymentStatus::Void ? ' · void' : ''), null, $p->branch_id, $p->amount_cents))->all()),
                array_values($bookings->map(fn (DetailingBooking $b): TimelineEntry => new TimelineEntry($b->completed_at ?? self::day($b->scheduled_date), 'booking', $b->id, "{$b->reference}: {$b->package_name}", trim(($b->vehicle_label ?? '').' · '.$b->status->label(), ' ·'), "/shop/detailing/bookings/{$b->id}", $b->branch_id, $b->package_price_cents))->all()),
                array_values($messages->map(fn (Message $m): TimelineEntry => new TimelineEntry($m->sent_at ?? $m->created_at, 'message', $m->id, $m->subject ?? $m->purpose->label(), $m->status === Message::SKIPPED ? "Not sent: {$m->skip_reason}" : ucfirst($m->status)." by {$m->channel->label()}"))->all()),
                array_values($notes->map(fn (CrmNote $n): TimelineEntry => new TimelineEntry($n->created_at, 'note', $n->id, "Note by {$n->author_name}", $n->body))->all()),
                array_values($tasks->map(fn (CrmTask $t): TimelineEntry => new TimelineEntry($t->completed_at ?? $t->created_at, 'task', $t->id, $t->title, $t->status === CrmTask::DONE ? "Done by {$t->completed_by_name}" : ucfirst($t->status)))->all()),
                array_values($consents->map(fn (Consent $c): TimelineEntry => new TimelineEntry($c->captured_at, 'consent', $c->id, self::CONSENT_LABELS[$c->purpose->value].($c->granted ? ': agreed' : ': withdrawn'), str_replace('_', ' ', $c->channel->value)))->all()),
                array_values($feedback->whereNotNull('responded_at')->map(fn (FeedbackRequest $f): TimelineEntry => new TimelineEntry($f->responded_at ?? $f->requested_at, 'feedback', $f->id, "Feedback: {$f->score} / 10", (string) $f->comment, null, $f->branch_id))->all()),
                array_values($memberships->map(fn (Membership $m): TimelineEntry => new TimelineEntry(self::day($m->started_on), 'membership', $m->id, "Joined {$m->tier->name}", $m->status === 'active' ? '' : 'Since ended'))->all()),
                array_values($packages->map(fn (PrepaidPackage $p): TimelineEntry => new TimelineEntry($p->created_at, 'package', $p->id, "Bought {$p->plan_name} ({$p->reference})", "{$p->credits_used} of {$p->credits_total} credits used", null, $p->branch_id, $p->price_cents))->all()),
            ), 0, self::TIMELINE_LIMIT)),
        ];
    }

    /** A business date as an instant: its Manila midnight. */
    private static function day(DateTimeImmutable $date): DateTimeImmutable
    {
        return Calendar::parseDate($date->format('Y-m-d'));
    }
}
