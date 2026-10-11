<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Actions\Audit\AuditTrail;
use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Domain\Crm\Reminders\ReminderKind;
use App\Exceptions\ConflictException;
use App\Models\CrmCampaign;
use App\Models\MessageTemplate;
use App\Models\ReminderRule;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Message templates and the reminder rules that use them. */
final class ManageMessaging
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{name?: string, channel?: string, purpose?: string, subject?: string|null, body?: string, is_active?: bool}  $data  only the keys present change on an edit
     */
    public function saveTemplate(?MessageTemplate $template, array $data): MessageTemplate
    {
        return DB::transaction(function () use ($template, $data): MessageTemplate {
            $before = $template === null ? null : AuditTrail::snapshot($template);
            $template ??= (new MessageTemplate)->forceFill(['organization_id' => $this->tenancy->require()->organizationId()]);

            if (isset($data['name'])) {
                $data['name'] = trim($data['name']);
                $taken = MessageTemplate::query()->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
                    ->when($template->exists, fn ($query) => $query->whereKeyNot($template->id))->exists();
                if ($taken) {
                    throw ValidationException::withMessages(['name' => 'Another template has that name.']);
                }
            }
            $template->forceFill($data);
            if ($template->channel === MessageChannel::Email && trim((string) $template->subject) === '') {
                throw ValidationException::withMessages(['subject' => 'An email needs a subject.']);
            }
            // A rule or a campaign in flight holds a template to its purpose.
            if ($template->exists && $template->isDirty('purpose')) {
                $inUse = ReminderRule::query()->where('template_id', $template->id)->exists() || CrmCampaign::query()->where('template_id', $template->id)->exists();
                if ($inUse) {
                    throw new ConflictException('A reminder rule or a campaign uses this template; its purpose cannot change.');
                }
            }
            $template->save();
            $this->audit->record($template, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($template));

            return $template;
        });
    }

    public function deleteTemplate(MessageTemplate $template): void
    {
        DB::transaction(function () use ($template): void {
            $inUse = ReminderRule::query()->where('template_id', $template->id)->exists()
                || CrmCampaign::query()->where('template_id', $template->id)->exists()
                || DB::table('messages')->where('template_id', $template->id)->exists();
            if ($inUse) {
                throw new ConflictException("{$template->name} has been used; switch it off instead of deleting it.");
            }
            $this->audit->record($template, 'deleted', AuditTrail::snapshot($template), null);
            $template->delete();
        });
    }

    /**
     * Set one kind of reminder: whether it is sent, with which template, and
     * (for documents) how many days ahead.
     *
     * @param  array{template_id: string, enabled?: bool, lead_days?: int|null}  $data
     */
    public function saveRule(ReminderKind $kind, array $data): ReminderRule
    {
        return DB::transaction(function () use ($kind, $data): ReminderRule {
            $template = MessageTemplate::query()->find($data['template_id']);
            if (! $template instanceof MessageTemplate || $template->purpose !== MessagePurpose::ServiceReminder) {
                throw ValidationException::withMessages(['template_id' => 'Pick a service-reminder template.']);
            }
            $rule = ReminderRule::query()->where('kind', $kind->value)->lockForUpdate()->first();
            $before = $rule === null ? null : AuditTrail::snapshot($rule);
            $rule ??= (new ReminderRule)->forceFill(['organization_id' => $this->tenancy->require()->organizationId(), 'kind' => $kind]);
            $rule->forceFill([
                'template_id' => $template->id,
                'enabled' => $data['enabled'] ?? $rule->enabled,
                'lead_days' => $kind->hasLeadDays() ? ($data['lead_days'] ?? $rule->lead_days ?? 30) : null,
            ])->save();
            $this->audit->record($rule, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($rule));

            return $rule;
        });
    }
}
