<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Crm\Nps;
use App\Domain\Crm\Prepaid;
use App\Domain\Crm\TemplateRenderer;
use App\Domain\Shared\Calendar;
use App\Models\CrmCampaign;
use App\Models\CrmLead;
use App\Models\CrmNote;
use App\Models\CrmSegment;
use App\Models\CrmSetting;
use App\Models\CrmTask;
use App\Models\FeedbackRequest;
use App\Models\Membership;
use App\Models\MembershipTier;
use App\Models\Message;
use App\Models\MessageTemplate;
use App\Models\PrepaidPackage;
use App\Models\PrepaidPlan;
use App\Models\ReminderRule;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * How each CRM record reads in JSON, in one place so the Customer 360 and the
 * list screens say the same thing. Money is integer centavos; instants are
 * UTC; business dates are `Y-m-d` (Asia/Manila). Flags a screen needs
 * (`overdue`, `reminder_due`, `credits_left`, `unearned_cents`) are worked out
 * here, not in the browser (R2).
 */
final class CrmPresenter
{
    /** @return array<string, mixed> */
    public static function settings(CrmSetting $s): array
    {
        return [
            'quiet_start_minutes' => $s->quiet_start_minutes,
            'quiet_end_minutes' => $s->quiet_end_minutes,
            'reminder_cooldown_days' => $s->reminder_cooldown_days,
            'lapsed_after_days' => $s->lapsed_after_days,
            'campaign_hourly_limit' => $s->campaign_hourly_limit,
            'feedback_enabled' => $s->feedback_enabled,
            'loyalty_enabled' => $s->loyalty_enabled,
            'loyalty_cents_per_point' => $s->loyalty_cents_per_point,
        ];
    }

    /** @return array<string, mixed> */
    public static function template(MessageTemplate $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'channel' => $t->channel->value,
            'channel_label' => $t->channel->label(),
            'purpose' => $t->purpose->value,
            'purpose_label' => $t->purpose->label(),
            'subject' => $t->subject,
            'body' => $t->body,
            'placeholders' => TemplateRenderer::placeholders(($t->subject ?? '').' '.$t->body),
            'is_active' => $t->is_active,
        ];
    }

    /** @return array<string, mixed> */
    public static function rule(ReminderRule $r): array
    {
        return ['id' => $r->id, 'enabled' => $r->enabled, 'template_id' => $r->template_id, 'template_name' => $r->template->name, 'lead_days' => $r->lead_days];
    }

    /** @return array<string, mixed> */
    public static function message(Message $m): array
    {
        return [
            'id' => $m->id,
            'customer_account_id' => $m->customer_account_id,
            'customer_name' => $m->relationLoaded('customerAccount') ? $m->customerAccount->display_name : null,
            'channel' => $m->channel->value,
            'purpose' => $m->purpose->value,
            'purpose_label' => $m->purpose->label(),
            'to_address' => $m->to_address,
            'to_name' => $m->to_name,
            'subject' => $m->subject,
            'body' => $m->body,
            'status' => $m->status,
            'skip_reason' => $m->skip_reason,
            'source_type' => $m->source_type,
            'source_id' => $m->source_id,
            'subject_type' => $m->subject_type,
            'subject_id' => $m->subject_id,
            'send_after' => $m->send_after?->utc()->toIso8601ZuluString(),
            'sent_at' => $m->sent_at?->utc()->toIso8601ZuluString(),
            'failed_reason' => $m->failed_reason,
            'responded_at' => $m->responded_at?->utc()->toIso8601ZuluString(),
            'created_at' => $m->created_at->utc()->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function note(CrmNote $n): array
    {
        return ['id' => $n->id, 'customer_account_id' => $n->customer_account_id, 'vehicle_id' => $n->vehicle_id, 'body' => $n->body, 'pinned' => $n->pinned, 'author_name' => $n->author_name, 'created_at' => $n->created_at->utc()->toIso8601ZuluString()];
    }

    /** @return array<string, mixed> */
    public static function task(CrmTask $t): array
    {
        $now = CarbonImmutable::now();
        $open = $t->status === CrmTask::OPEN;

        return [
            'id' => $t->id,
            'title' => $t->title,
            'details' => $t->details,
            'kind' => $t->kind,
            'status' => $t->status,
            'customer_account_id' => $t->customer_account_id,
            'customer_name' => $t->customerAccount?->display_name,
            'lead_id' => $t->lead_id,
            'lead_name' => $t->lead?->company_name,
            'vehicle_id' => $t->vehicle_id,
            'assignee_id' => $t->assignee_id,
            'assignee_name' => $t->assignee?->name,
            'due_on' => $t->due_on?->toDateString(),
            'remind_at' => $t->remind_at?->utc()->toIso8601ZuluString(),
            'overdue' => $open && $t->due_on !== null && $t->due_on->toDateString() < Calendar::toDate($now),
            'reminder_due' => $open && $t->remind_at !== null && $t->remind_at <= $now,
            'completed_at' => $t->completed_at?->utc()->toIso8601ZuluString(),
            'completed_by_name' => $t->completed_by_name,
            'created_by_name' => $t->created_by_name,
            'created_at' => $t->created_at->utc()->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function lead(CrmLead $l): array
    {
        return [
            'id' => $l->id,
            'branch_id' => $l->branch_id,
            'company_name' => $l->company_name,
            'contact_name' => $l->contact_name,
            'contact_email' => $l->contact_email,
            'contact_phone' => $l->contact_phone,
            'stage' => $l->stage->value,
            'stage_label' => $l->stage->label(),
            'is_open' => $l->stage->isOpen(),
            'value_cents' => $l->value_cents,
            'owner_id' => $l->owner_id,
            'owner_name' => $l->owner?->name,
            'next_step' => $l->next_step,
            'next_step_on' => $l->next_step_on?->toDateString(),
            'next_step_overdue' => $l->stage->isOpen() && $l->next_step_on !== null && $l->next_step_on->toDateString() < Calendar::toDate(CarbonImmutable::now()),
            'source' => $l->source,
            'is_dealer' => $l->is_dealer,
            'notes' => $l->notes,
            'lost_reason' => $l->lost_reason,
            'customer_account_id' => $l->customer_account_id,
            'stage_changed_at' => $l->stage_changed_at->utc()->toIso8601ZuluString(),
            'created_at' => $l->created_at->utc()->toIso8601ZuluString(),
        ];
    }

    /** @return array<string, mixed> */
    public static function tier(MembershipTier $t, int $members = 0): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'discount_pct' => (string) $t->discount_pct->toScale(2, RoundingMode::HalfUp), 'priority_booking' => $t->priority_booking, 'free_addon_ids' => $t->free_addon_ids, 'perks' => $t->perks, 'is_active' => $t->is_active, 'position' => $t->position, 'members' => $members];
    }

    /** @return array<string, mixed> */
    public static function membership(Membership $m): array
    {
        $today = Calendar::toDate(CarbonImmutable::now());

        return [
            'id' => $m->id,
            'customer_account_id' => $m->customer_account_id,
            'tier_id' => $m->tier_id,
            'tier_name' => $m->tier->name,
            'discount_pct' => (string) $m->tier->discount_pct->toScale(2, RoundingMode::HalfUp),
            'priority_booking' => $m->tier->priority_booking,
            'free_addon_ids' => $m->tier->free_addon_ids,
            'perks' => $m->tier->perks,
            'started_on' => $m->started_on->toDateString(),
            'expires_on' => $m->expires_on?->toDateString(),
            'status' => $m->status,
            'in_force' => $m->status === 'active' && $m->started_on->toDateString() <= $today && ($m->expires_on === null || $m->expires_on->toDateString() >= $today),
            'notes' => $m->notes,
        ];
    }

    /** @return array<string, mixed> */
    public static function plan(PrepaidPlan $p): array
    {
        return ['id' => $p->id, 'name' => $p->name, 'price_cents' => $p->price_cents, 'credits' => $p->credits, 'detailing_package_id' => $p->detailing_package_id, 'valid_days' => $p->valid_days, 'is_active' => $p->is_active];
    }

    /** @return array<string, mixed> */
    public static function package(PrepaidPackage $p): array
    {
        $today = Calendar::toDate(CarbonImmutable::now());
        $expired = $p->expires_on !== null && $p->expires_on->toDateString() < $today;

        return [
            'id' => $p->id,
            'reference' => $p->reference,
            'customer_account_id' => $p->customer_account_id,
            'customer_name' => $p->relationLoaded('customerAccount') ? $p->customerAccount->display_name : null,
            'branch_id' => $p->branch_id,
            'plan_id' => $p->plan_id,
            'plan_name' => $p->plan_name,
            'price_cents' => $p->price_cents,
            // The VAT inside the price, charged when it was sold; the rest is what its credits earn.
            'vat_cents' => $p->vat_cents,
            'net_price_cents' => $p->netPriceCents(),
            'credits_total' => $p->credits_total,
            'credits_used' => $p->credits_used,
            'credits_left' => $p->credits_total - $p->credits_used,
            // What is still owed as service: this package's share of Unearned Revenue.
            'unearned_cents' => Prepaid::unearnedCents($p->netPriceCents(), $p->credits_total, $p->credits_used),
            'detailing_package_id' => $p->detailing_package_id,
            'sold_on' => $p->sold_on->toDateString(),
            'expires_on' => $p->expires_on?->toDateString(),
            'expired' => $expired,
            'usable' => ! $expired && $p->credits_used < $p->credits_total,
            'payment_method' => $p->payment_method->value,
            'payment_reference' => $p->payment_reference,
            'sold_by_name' => $p->sold_by_name,
        ];
    }

    /** @return array<string, mixed> */
    public static function feedback(FeedbackRequest $f): array
    {
        return [
            'id' => $f->id,
            'customer_account_id' => $f->customer_account_id,
            'customer_name' => $f->relationLoaded('customerAccount') ? $f->customerAccount->display_name : null,
            'branch_id' => $f->branch_id,
            'invoice_id' => $f->invoice_id,
            'work_order_id' => $f->work_order_id,
            'detailing_booking_id' => $f->detailing_booking_id,
            'requested_at' => $f->requested_at->utc()->toIso8601ZuluString(),
            'responded_at' => $f->responded_at?->utc()->toIso8601ZuluString(),
            'score' => $f->score,
            'band' => $f->score === null ? null : Nps::band($f->score),
            'comment' => $f->comment,
        ];
    }

    /** @return array<string, mixed> */
    public static function segment(CrmSegment $s, ?int $size = null): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'description' => $s->description, 'filters' => (object) $s->filters, 'size' => $size];
    }

    /**
     * @param  array<string, mixed>|null  $progress
     * @return array<string, mixed>
     */
    public static function campaign(CrmCampaign $c, ?array $progress = null): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'segment_id' => $c->segment_id,
            'segment_name' => $c->segment->name,
            'template_id' => $c->template_id,
            'template_name' => $c->template->name,
            'channel' => $c->template->channel->value,
            'status' => $c->status,
            'scheduled_at' => $c->scheduled_at?->utc()->toIso8601ZuluString(),
            'started_at' => $c->started_at?->utc()->toIso8601ZuluString(),
            'finished_at' => $c->finished_at?->utc()->toIso8601ZuluString(),
            'hourly_limit' => $c->hourly_limit,
            'can_edit' => $c->status === 'draft',
            'can_schedule' => $c->status === 'draft',
            'can_cancel' => in_array($c->status, ['draft', 'scheduled', 'sending'], true),
            'progress' => $progress,
            'created_by_name' => $c->created_by_name,
        ];
    }
}
