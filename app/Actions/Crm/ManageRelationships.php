<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Accounts\CreateCustomerAccount;
use App\Actions\Accounts\SetApprovalOverrides;
use App\Actions\Audit\AuditTrail;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Crm\LeadStage;
use App\Exceptions\ConflictException;
use App\Mail\CrmMessageMail;
use App\Models\Branch;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\CrmTask;
use App\Models\CustomerAccount;
use App\Models\User;
use App\Models\Vehicle;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Notes, tasks and the B2B pipeline: what staff write down about a customer,
 * what they have promised to do, and the companies they are trying to win.
 */
final class ManageRelationships
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
        private readonly CreateCustomerAccount $accounts,
        private readonly SetApprovalOverrides $overrides,
    ) {}

    /* ------------------------------------------------------------------ notes */

    /**
     * @param  array{body: string, vehicle_id?: string|null, pinned?: bool}  $data
     */
    public function addNote(CustomerAccount $account, array $data): CrmNote
    {
        return DB::transaction(function () use ($account, $data): CrmNote {
            $vehicleId = $data['vehicle_id'] ?? null;
            if ($vehicleId !== null && ! Vehicle::query()->whereKey($vehicleId)->where('customer_account_id', $account->id)->exists()) {
                throw ValidationException::withMessages(['vehicle_id' => 'That vehicle is not this customer\'s.']);
            }
            $actor = $this->actor();
            $note = new CrmNote;
            $note->forceFill([
                'organization_id' => $account->organization_id,
                'customer_account_id' => $account->id,
                'vehicle_id' => $vehicleId,
                'body' => trim($data['body']),
                'pinned' => $data['pinned'] ?? false,
                'author_id' => $actor->id,
                'author_name' => $actor->name,
            ])->save();
            $this->audit->record($note, 'created', null, AuditTrail::snapshot($note));

            return $note;
        });
    }

    /**
     * @param  array{body?: string, pinned?: bool}  $data
     */
    public function editNote(CrmNote $note, array $data): CrmNote
    {
        return DB::transaction(function () use ($note, $data): CrmNote {
            $before = AuditTrail::snapshot($note);
            $note->forceFill(array_filter(['body' => isset($data['body']) ? trim($data['body']) : null, 'pinned' => $data['pinned'] ?? null], fn (mixed $v): bool => $v !== null))->save();
            $this->audit->record($note, 'updated', $before, AuditTrail::snapshot($note));

            return $note;
        });
    }

    public function deleteNote(CrmNote $note): void
    {
        DB::transaction(function () use ($note): void {
            $this->audit->record($note, 'deleted', AuditTrail::snapshot($note), null);
            $note->delete();
        });
    }

    /* ------------------------------------------------------------------ tasks */

    /**
     * @param  array{title?: string, details?: string|null, kind?: string, customer_account_id?: string|null, lead_id?: string|null, vehicle_id?: string|null, assignee_id?: string|null, due_on?: string|null, remind_at?: string|CarbonImmutable|null}  $data  only the keys present change on an edit
     */
    public function saveTask(?CrmTask $task, array $data): CrmTask
    {
        return DB::transaction(function () use ($task, $data): CrmTask {
            $before = $task === null ? null : AuditTrail::snapshot($task);
            $actor = $this->actor();
            $task ??= (new CrmTask)->forceFill(['organization_id' => $actor->organization_id, 'created_by' => $actor->id, 'created_by_name' => $actor->name]);

            // An instant is stored in UTC: one given with an offset would otherwise be written as if it were UTC.
            if (isset($data['remind_at'])) {
                $data['remind_at'] = CarbonImmutable::parse($data['remind_at'])->utc();
            }
            $task->forceFill($data);
            if ($task->customer_account_id === null && $task->lead_id === null) {
                throw ValidationException::withMessages(['customer_account_id' => 'A task is about a customer or a lead.']);
            }
            if ($task->customer_account_id !== null && ! CustomerAccount::query()->whereKey($task->customer_account_id)->exists()) {
                throw ValidationException::withMessages(['customer_account_id' => 'That customer was not found.']);
            }
            if ($task->lead_id !== null && ! CrmLead::query()->whereKey($task->lead_id)->exists()) {
                throw ValidationException::withMessages(['lead_id' => 'That lead was not found.']);
            }
            if ($task->assignee_id !== null && ! User::query()->whereKey($task->assignee_id)->where('side', 'staff')->where('status', 'active')->exists()) {
                throw ValidationException::withMessages(['assignee_id' => 'Assign the task to an active member of staff.']);
            }
            // A reminder moved to a new time is due again.
            if ($task->isDirty('remind_at')) {
                $task->reminded_at = null;
            }
            $task->save();
            $this->audit->record($task, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($task));

            return $task;
        });
    }

    /** Done, cancelled, or open again. */
    public function setTaskStatus(CrmTask $task, string $status): CrmTask
    {
        return DB::transaction(function () use ($task, $status): CrmTask {
            $locked = CrmTask::query()->lockForUpdate()->findOrFail($task->id);
            $before = AuditTrail::snapshot($locked);
            $done = $status === CrmTask::DONE;
            $locked->forceFill([
                'status' => $status,
                'completed_at' => $done ? CarbonImmutable::now() : null,
                'completed_by_name' => $done ? $this->actor()->name : null,
            ])->save();
            $this->audit->record($locked, 'status_changed', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * Tell each assignee about the open tasks whose reminder time has come, once.
     * Runs without a session, as the organization (`CrmContexts`).
     */
    public function sendTaskReminders(): int
    {
        $sent = 0;
        $due = CrmTask::query()->where('status', CrmTask::OPEN)->whereNotNull('assignee_id')->whereNull('reminded_at')
            ->where('remind_at', '<=', CarbonImmutable::now())->with(['assignee', 'customerAccount', 'lead'])->orderBy('remind_at')->limit(200)->get();
        foreach ($due as $task) {
            DB::transaction(function () use ($task, &$sent): void {
                $locked = CrmTask::query()->lockForUpdate()->findOrFail($task->id);
                if ($locked->reminded_at !== null || $locked->status !== CrmTask::OPEN) {
                    return;
                }
                $locked->forceFill(['reminded_at' => CarbonImmutable::now()])->save();
                $about = $task->customerAccount->display_name ?? $task->lead->company_name ?? '';
                $assignee = $task->assignee;
                if ($assignee !== null && filter_var($assignee->email, FILTER_VALIDATE_EMAIL) !== false) {
                    $body = trim(sprintf("%s\n%s%s\n\n%s", $task->title, $about === '' ? '' : "About: {$about}\n", $task->due_on === null ? '' : 'Due: '.$task->due_on->format('j F Y'), (string) $task->details));
                    Mail::to($assignee->email, $assignee->name)->send(new CrmMessageMail($task->id, "Reminder: {$task->title}", $body));
                    $sent++;
                }
            });
        }

        return $sent;
    }

    /* ------------------------------------------------------------------ leads */

    /**
     * @param  array<string, mixed>  $data  validated; only the keys present change on an edit
     */
    public function saveLead(?CrmLead $lead, array $data): CrmLead
    {
        return DB::transaction(function () use ($lead, $data): CrmLead {
            $before = $lead === null ? null : AuditTrail::snapshot($lead);
            $actor = $this->actor();
            $lead ??= (new CrmLead)->forceFill(['organization_id' => $actor->organization_id, 'created_by_name' => $actor->name, 'stage_changed_at' => CarbonImmutable::now(), 'owner_id' => $actor->id]);
            if ($lead->exists && $lead->stage === LeadStage::Won) {
                throw new ConflictException("{$lead->company_name} is won; it is a customer account now.");
            }
            $lead->forceFill($data);
            if ($lead->owner_id !== null && ! User::query()->whereKey($lead->owner_id)->where('side', 'staff')->exists()) {
                throw ValidationException::withMessages(['owner_id' => 'A lead is owned by a member of staff.']);
            }
            if ($lead->branch_id !== null && ! Branch::query()->whereKey($lead->branch_id)->exists()) {
                throw ValidationException::withMessages(['branch_id' => 'That branch was not found.']);
            }
            $lead->save();
            $this->audit->record($lead, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($lead));

            return $lead;
        });
    }

    /** A plain stage change. Losing a lead says why; winning one is `convertLead`. */
    public function moveLead(CrmLead $lead, LeadStage $to, ?string $lostReason = null): CrmLead
    {
        return DB::transaction(function () use ($lead, $to, $lostReason): CrmLead {
            $locked = CrmLead::query()->lockForUpdate()->findOrFail($lead->id);
            if ($to === LeadStage::Won) {
                throw new ConflictException('A lead is won by converting it into a customer account.', ['reason' => 'convert_to_win']);
            }
            if (! $locked->stage->canMoveTo($to)) {
                throw new ConflictException(sprintf('A lead that is %s cannot be moved to %s.', mb_strtolower($locked->stage->label()), mb_strtolower($to->label())), ['reason' => 'invalid_transition']);
            }
            if ($to === LeadStage::Lost && trim((string) $lostReason) === '') {
                throw ValidationException::withMessages(['lost_reason' => 'Say why the lead was lost.']);
            }
            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['stage' => $to, 'stage_changed_at' => CarbonImmutable::now(), 'lost_reason' => $to === LeadStage::Lost ? trim((string) $lostReason) : null])->save();
            $this->audit->record($locked, 'stage_changed', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * Won: the lead becomes a company account, with its terms and approval
     * overrides, tagged `dealer` when the lead was one. The customer's consent
     * to keeping service records is recorded as the account opens, as for any
     * account; its open tasks move to the account.
     *
     * @param  array{consent_channel: string, payment_terms_days?: int, credit_limit_cents?: int|null, registered_name?: string|null, tin?: string|null, address?: string|null, approval_overrides?: array<string, int|string|null>, service_reminders?: bool, marketing?: bool}  $data
     */
    public function convertLead(CrmLead $lead, array $data): CustomerAccount
    {
        return DB::transaction(function () use ($lead, $data): CustomerAccount {
            $locked = CrmLead::query()->lockForUpdate()->findOrFail($lead->id);
            if (! $locked->stage->isOpen()) {
                throw new ConflictException(sprintf('%s is already %s.', $locked->company_name, mb_strtolower($locked->stage->label())), ['reason' => 'invalid_transition']);
            }

            $consent = fn (ConsentPurpose $purpose, bool $granted): array => ['purpose' => $purpose->value, 'granted' => $granted, 'channel' => $data['consent_channel'], 'evidence' => "Agreed when {$locked->company_name} was won"];
            $account = $this->accounts->handle(array_filter([
                'account_type' => CustomerAccount::COMPANY,
                'display_name' => $locked->company_name,
                'registered_name' => $data['registered_name'] ?? $locked->company_name,
                'tin' => $data['tin'] ?? null,
                'address' => $data['address'] ?? null,
                'contact_name' => $locked->contact_name,
                'contact_email' => $locked->contact_email,
                'mobile' => $locked->contact_phone,
                'payment_terms_days' => $data['payment_terms_days'] ?? 30,
                'credit_limit_cents' => $data['credit_limit_cents'] ?? null,
                'tags' => $locked->is_dealer ? ['dealer'] : [],
                'source' => 'lead',
                'notes' => $locked->notes,
            ], fn (mixed $value): bool => $value !== null), [
                $consent(ConsentPurpose::ServiceRecords, true),
                $consent(ConsentPurpose::ServiceReminders, $data['service_reminders'] ?? false),
                $consent(ConsentPurpose::Marketing, $data['marketing'] ?? false),
            ]);
            if (($data['approval_overrides'] ?? []) !== []) {
                $account = $this->overrides->handle($account, $data['approval_overrides']);
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['stage' => LeadStage::Won, 'stage_changed_at' => CarbonImmutable::now(), 'customer_account_id' => $account->id, 'lost_reason' => null])->save();
            CrmTask::query()->where('lead_id', $locked->id)->whereNull('customer_account_id')->update(['customer_account_id' => $account->id]);
            $this->audit->record($locked, 'won', $before, AuditTrail::snapshot($locked));

            return $account;
        });
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }
}
