<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Audit\AuditTrail;
use App\Database\Cell;
use App\Domain\Crm\AccountProfile;
use App\Domain\Crm\MessagePurpose;
use App\Domain\Crm\SegmentFilter;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Receivables\PaymentStatus;
use App\Domain\Shared\Calendar;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Exceptions\ConflictException;
use App\Models\CrmCampaign;
use App\Models\CrmSegment;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\Membership;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\Payment;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Segments and campaigns.
 *
 * A segment is a saved definition; who is in it is worked out when it is read
 * (`SegmentFilter::matches` over each active customer's profile).
 *
 * A campaign sends one marketing template to a segment. Every recipient is a
 * row of the message log, written by the `Messenger`: sent where marketing
 * consent is on record, `skipped` with the reason where it is not. Sending is
 * throttled to an hourly limit and picks up where it left off, so a large
 * audience goes out over several runs.
 */
final class ManageCampaigns
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
        private readonly CrmSettings $settings,
        private readonly Messenger $messenger,
    ) {}

    /* --------------------------------------------------------------- segments */

    /**
     * @param  array{name?: string, description?: string|null, filters?: array<array-key, mixed>}  $data
     */
    public function saveSegment(?CrmSegment $segment, array $data): CrmSegment
    {
        return DB::transaction(function () use ($segment, $data): CrmSegment {
            $before = $segment === null ? null : AuditTrail::snapshot($segment);
            $segment ??= (new CrmSegment)->forceFill(['organization_id' => $this->tenancy->require()->organizationId()]);
            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                $taken = CrmSegment::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])->when($segment->exists, fn ($q) => $q->whereKeyNot($segment->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['name' => 'Another segment has that name.']);
                }
            }
            if (isset($data['filters'])) {
                $data['filters'] = SegmentFilter::fromArray($data['filters'])->toArray();
            }
            $segment->forceFill($data)->save();
            $this->audit->record($segment, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($segment));

            return $segment;
        });
    }

    public function deleteSegment(CrmSegment $segment): void
    {
        DB::transaction(function () use ($segment): void {
            if (CrmCampaign::query()->where('segment_id', $segment->id)->exists()) {
                throw new ConflictException("{$segment->name} is used by a campaign.");
            }
            $this->audit->record($segment, 'deleted', AuditTrail::snapshot($segment), null);
            $segment->delete();
        });
    }

    /**
     * The active customers a filter matches, by name.
     *
     * @return list<CustomerAccount>
     */
    public function members(SegmentFilter $filter): array
    {
        $today = CarbonImmutable::now(Calendar::zone())->startOfDay();
        $accounts = CustomerAccount::query()->where('status', 'active')->orderBy('display_name')->orderBy('id')->get()->keyBy('id');
        $members = [];
        foreach ($this->profiles(array_values($accounts->all())) as $profile) {
            $account = $accounts->get($profile->accountId);
            if ($account !== null && $filter->matches($profile, $today)) {
                $members[] = $account;
            }
        }

        return $members;
    }

    /**
     * What a segment reads about each of the given customers, in their order.
     *
     * @param  list<CustomerAccount>  $accounts
     * @return list<AccountProfile>
     */
    public function profiles(array $accounts): array
    {
        $spend = Payment::query()->where('status', PaymentStatus::Posted)->selectRaw('customer_account_id, sum(amount_cents) as paid')->groupBy('customer_account_id')->toBase()->pluck('paid', 'customer_account_id');

        $last = [];
        $branches = [];
        foreach (WorkOrder::query()->where('status', WorkOrderStatus::Closed)->whereNotNull('completed_on')->toBase()->get(['customer_account_id', 'completed_on', 'branch_id', 'assigned_branch_id']) as $row) {
            $accountId = Cell::string($row->customer_account_id);
            $on = substr(Cell::string($row->completed_on), 0, 10);
            $last[$accountId] = max($last[$accountId] ?? '', $on);
            $branch = $row->assigned_branch_id ?? $row->branch_id;
            if (is_string($branch)) {
                $branches[$accountId][$branch] = true;
            }
        }
        foreach (DetailingBooking::query()->where('status', BookingStatus::Completed)->whereNotNull('completed_at')->get(['id', 'customer_account_id', 'completed_at', 'branch_id']) as $booking) {
            $on = $booking->completed_at === null ? '' : Calendar::toDate($booking->completed_at);
            $last[$booking->customer_account_id] = max($last[$booking->customer_account_id] ?? '', $on);
            $branches[$booking->customer_account_id][$booking->branch_id] = true;
        }

        $makes = [];
        $sizes = [];
        foreach (Vehicle::query()->whereNull('archived_at')->toBase()->get(['customer_account_id', 'make', 'size_class']) as $row) {
            $accountId = Cell::string($row->customer_account_id);
            if (is_string($row->make) && trim($row->make) !== '') {
                $makes[$accountId][mb_strtolower(trim($row->make))] = true;
            }
            if (is_string($row->size_class) && $row->size_class !== '') {
                $sizes[$accountId][$row->size_class] = true;
            }
        }

        $today = Calendar::toDate(CarbonImmutable::now());
        $tiers = Membership::query()->where('status', 'active')->where('started_on', '<=', $today)
            ->where(fn ($q) => $q->whereNull('expires_on')->orWhere('expires_on', '>=', $today))->pluck('tier_id', 'customer_account_id');

        return array_map(fn (CustomerAccount $account): AccountProfile => new AccountProfile(
            $account->id,
            $account->account_type,
            $account->tags,
            is_numeric($paid = $spend->get($account->id)) ? (int) $paid : 0,
            ($last[$account->id] ?? '') === '' ? null : $last[$account->id],
            array_map(strval(...), array_keys($makes[$account->id] ?? [])),
            array_map(strval(...), array_keys($sizes[$account->id] ?? [])),
            is_string($tier = $tiers->get($account->id)) ? $tier : null,
            array_map(strval(...), array_keys($branches[$account->id] ?? [])),
        ), $accounts);
    }

    /* -------------------------------------------------------------- campaigns */

    /**
     * @param  array{name?: string, segment_id?: string, template_id?: string, scheduled_at?: string|CarbonImmutable|null, hourly_limit?: int|null}  $data
     */
    public function saveCampaign(?CrmCampaign $campaign, array $data): CrmCampaign
    {
        return DB::transaction(function () use ($campaign, $data): CrmCampaign {
            $before = $campaign === null ? null : AuditTrail::snapshot($campaign);
            $actor = User::query()->findOrFail($this->tenancy->require()->userId);
            $campaign ??= (new CrmCampaign)->forceFill(['organization_id' => $actor->organization_id, 'created_by_name' => $actor->name]);
            if ($campaign->exists && $campaign->status !== 'draft') {
                throw new ConflictException("{$campaign->name} is no longer a draft.", ['reason' => 'invalid_transition']);
            }
            if (isset($data['scheduled_at'])) {
                $data['scheduled_at'] = CarbonImmutable::parse($data['scheduled_at'])->utc();
            }
            $campaign->forceFill($data);
            if (! CrmSegment::query()->whereKey($campaign->segment_id)->exists()) {
                throw ValidationException::withMessages(['segment_id' => 'Pick a segment.']);
            }
            $template = MessageTemplate::query()->find($campaign->template_id);
            if (! $template instanceof MessageTemplate || $template->purpose !== MessagePurpose::Marketing || ! $template->is_active) {
                throw ValidationException::withMessages(['template_id' => 'Pick an active marketing template.']);
            }
            $campaign->save();
            $this->audit->record($campaign, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($campaign));

            return $campaign;
        });
    }

    /** Schedule a draft (now, when it has no time), or cancel one that has not finished. */
    public function setCampaignStatus(CrmCampaign $campaign, string $to): CrmCampaign
    {
        return DB::transaction(function () use ($campaign, $to): CrmCampaign {
            $locked = CrmCampaign::query()->lockForUpdate()->findOrFail($campaign->id);
            $allowed = ['scheduled' => ['draft'], 'cancelled' => ['draft', 'scheduled', 'sending'], 'draft' => ['scheduled']];
            if (! in_array($locked->status, $allowed[$to] ?? [], true)) {
                throw new ConflictException("A campaign that is {$locked->status} cannot be {$to}.", ['reason' => 'invalid_transition']);
            }
            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['status' => $to, 'scheduled_at' => $to === 'scheduled' ? ($locked->scheduled_at ?? CarbonImmutable::now()) : $locked->scheduled_at])->save();
            $this->audit->record($locked, $to, $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * Send what is due, for the organization in context: start the campaigns
     * whose time has come, and move each one in flight forward by up to its
     * hourly allowance.
     *
     * @return array{started: int, queued: int, skipped: int, finished: int}
     */
    public function run(): array
    {
        $result = ['started' => 0, 'queued' => 0, 'skipped' => 0, 'finished' => 0];
        $now = CarbonImmutable::now();
        $limit = $this->settings->current()->campaign_hourly_limit;

        foreach (CrmCampaign::query()->whereIn('status', ['scheduled', 'sending'])->where('scheduled_at', '<=', $now)->orderBy('scheduled_at')->orderBy('id')->get() as $due) {
            DB::transaction(function () use ($due, $now, $limit, &$result): void {
                $campaign = CrmCampaign::query()->with(['segment', 'template'])->lockForUpdate()->findOrFail($due->id);
                if (! in_array($campaign->status, ['scheduled', 'sending'], true)) {
                    return;
                }
                if ($campaign->status === 'scheduled') {
                    // The audience is fixed as sending begins: who joins the segment later is not added mid-flight.
                    $audience = array_map(fn (CustomerAccount $a): string => $a->id, $this->members(SegmentFilter::fromArray($campaign->segment->filters)));
                    $campaign->forceFill(['status' => 'sending', 'started_at' => $now, 'audience' => $audience])->save();
                    $result['started']++;
                }

                $done = array_map(Cell::string(...), Message::query()->where('source_type', 'campaign')->where('source_id', $campaign->id)->pluck('customer_account_id')->all());
                $remaining = array_values(array_diff($campaign->audience ?? [], $done));
                $sentThisHour = Message::query()->where('source_type', 'campaign')->where('source_id', $campaign->id)
                    ->where('status', '!=', Message::SKIPPED)->where('created_at', '>', $now->subHour())->count();
                $allowance = max(0, ($campaign->hourly_limit ?? $limit) - $sentThisHour);

                $accounts = CustomerAccount::query()->whereIn('id', $remaining)->get()->keyBy('id');
                foreach ($remaining as $index => $accountId) {
                    if ($allowance < 1) {
                        break;
                    }
                    $account = $accounts->get($accountId);
                    unset($remaining[$index]);
                    if (! $account instanceof CustomerAccount) {
                        continue;
                    }
                    $message = $this->messenger->queue($account, $campaign->template, [], 'campaign', $campaign->id, 'account', $account->id, "campaign:{$campaign->id}:{$account->id}", null, null, false);
                    if ($message->status === Message::SKIPPED) {
                        $result['skipped']++;
                    } else {
                        $result['queued']++;
                        $allowance--;
                    }
                }

                if ($remaining === []) {
                    $campaign->forceFill(['status' => 'sent', 'finished_at' => $now])->save();
                    $result['finished']++;
                }
            });
        }

        return $result;
    }

    /**
     * What became of a campaign's audience, from the message log.
     *
     * @return array{audience: int, queued: int, sent: int, failed: int, skipped: int, skipped_by_reason: array<string, int>, remaining: int}
     */
    public function progress(CrmCampaign $campaign): array
    {
        $rows = Message::query()->where('source_type', 'campaign')->where('source_id', $campaign->id)->toBase()
            ->selectRaw('status, skip_reason, count(*) as n')->groupBy('status', 'skip_reason')->get();
        $counts = ['queued' => 0, 'sent' => 0, 'failed' => 0, 'skipped' => 0];
        $reasons = [];
        $total = 0;
        foreach ($rows as $row) {
            $n = is_numeric($row->n) ? (int) $row->n : 0;
            $status = Cell::string($row->status);
            $counts[$status] = ($counts[$status] ?? 0) + $n;
            $total += $n;
            if ($status === Message::SKIPPED) {
                $reasons[Cell::string($row->skip_reason)] = $n;
            }
        }
        $audience = count($campaign->audience ?? []);

        return ['audience' => $audience, 'queued' => $counts['queued'], 'sent' => $counts['sent'], 'failed' => $counts['failed'], 'skipped' => $counts['skipped'], 'skipped_by_reason' => $reasons, 'remaining' => max(0, $audience - $total)];
    }
}
