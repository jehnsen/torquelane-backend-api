<?php

declare(strict_types=1);

namespace App\Actions\Crm;

use App\Domain\Crm\ConsentLedger;
use App\Domain\Crm\ConsentPurpose;
use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Domain\Crm\Reminders\GateDecision;
use App\Domain\Crm\Reminders\ReminderGate;
use App\Domain\Crm\TemplateRenderer;
use App\Domain\Shared\Calendar;
use App\Jobs\SendMessage;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\CustomerAccount;
use App\Models\Message;
use App\Models\MessageSuppression;
use App\Models\MessageTemplate;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * THE writer of the message log. Every reminder, campaign message and
 * feedback request goes through `queue()`, which decides, in one place,
 * whether it may go:
 *
 *  - who it is to (a contact who takes reminders, else the account itself);
 *  - the consent its purpose needs (`service_reminders` / `marketing`): the
 *    contact's own decision where one is on record, else the account's;
 *  - the suppression list (an address that opted out);
 *  - the cooldown on its dedupe key (the same reminder about the same thing);
 *  - quiet hours, which hold it until they end.
 *
 * A message that may not go is still written, as `skipped` with its reason,
 * so the log answers "why did they not get it" (once per cooldown: a reminder
 * refused for the same reason yesterday is not logged again today). One that may go is `queued`
 * and handed to `SendMessage` once the transaction commits (or left for the
 * minute-by-minute delivery run when it is being held).
 */
final class Messenger
{
    public function __construct(private readonly CrmSettings $settings) {}

    /**
     * @param  array<string, string>  $values  what the template's placeholders become, beyond the standard ones
     * @param  string|null  $linkPath  the page the message's own link opens (`r`, `feedback`); the link carries the message's token
     * @param  bool  $cooldown  whether the dedupe key is held to the reminder cooldown (off: only the once-a-day guard applies)
     */
    public function queue(
        CustomerAccount $account,
        MessageTemplate $template,
        array $values,
        string $sourceType,
        ?string $sourceId = null,
        ?string $subjectType = null,
        ?string $subjectId = null,
        ?string $dedupeKey = null,
        ?string $branchId = null,
        ?string $linkPath = null,
        bool $cooldown = true,
    ): Message {
        $now = CarbonImmutable::now();
        $settings = $this->settings->of($account->organization_id);
        $channel = $template->channel;
        $purpose = $template->purpose;

        [$contact, $address, $name] = $this->recipient($account, $channel, $purpose);
        $consent = $purpose->consent();
        $lastSent = $dedupeKey === null || ! $cooldown ? null : Message::query()
            ->where('organization_id', $account->organization_id)->where('dedupe_key', $dedupeKey)
            ->whereIn('status', [Message::QUEUED, Message::SENT])->max('created_at');

        $decision = ReminderGate::decide(
            $address !== null,
            $consent !== null,
            $consent !== null && $this->consented($account, $contact, $consent),
            $address !== null && $this->suppressed($account->organization_id, $channel, $address),
            is_string($lastSent) ? CarbonImmutable::parse($lastSent) : null,
            $settings->reminder_cooldown_days,
            CrmSettings::quietHours($settings),
            $now,
        );

        // Nothing new to say about a reminder still inside its cooldown, or one refused for the same reason before:
        // the row already in the log stands, so a daily run does not fill it with repeats.
        if (! $decision->send && $dedupeKey !== null) {
            $standing = Message::query()->where('organization_id', $account->organization_id)->where('dedupe_key', $dedupeKey)
                ->where('created_at', '>=', $now->subDays($settings->reminder_cooldown_days))
                ->when(
                    $decision->skipReason === GateDecision::RECENTLY_SENT,
                    fn ($query) => $query->whereIn('status', [Message::QUEUED, Message::SENT]),
                    fn ($query) => $query->where('status', Message::SKIPPED)->where('skip_reason', $decision->skipReason),
                )->latest('created_at')->first();
            if ($standing instanceof Message) {
                return $standing;
            }
        }

        $token = $decision->send ? Str::random(40) : null;
        $frontend = rtrim(config()->string('app.frontend_url'), '/');
        $branch = $branchId === null ? null : Branch::query()->find($branchId);
        $organization = Organization::query()->findOrFail($account->organization_id);
        $rendered = $values + [
            'customer_name' => $name,
            'shop' => $branch === null ? $organization->name : ($branch->brand_name ?? $branch->name),
            'shop_phone' => ($branch === null ? null : $branch->contact_phone) ?? $organization->contact_phone ?? '',
            'link' => $token === null || $linkPath === null || $frontend === '' ? '' : "{$frontend}/{$linkPath}/{$token}",
            'unsubscribe_link' => $token === null || $frontend === '' ? '' : "{$frontend}/unsubscribe/{$token}",
        ];

        $row = [
            'organization_id' => $account->organization_id,
            'branch_id' => $branchId,
            'customer_account_id' => $account->id,
            'contact_id' => $contact?->id,
            'channel' => $channel,
            'purpose' => $purpose,
            'template_id' => $template->id,
            'to_address' => $address,
            'to_name' => $name,
            'subject' => $template->subject === null ? null : TemplateRenderer::render($template->subject, $rendered),
            'body' => TemplateRenderer::render($template->body, $rendered),
            'status' => $decision->send ? Message::QUEUED : Message::SKIPPED,
            'skip_reason' => $decision->skipReason,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'dedupe_key' => $dedupeKey,
            'dedupe_on' => $dedupeKey === null ? null : Calendar::toDate($now),
            'token_hash' => $token === null ? null : hash('sha256', $token),
            // Stored in UTC: quiet hours end at a Manila time, and a zoned instant would be written as if it were UTC.
            'send_after' => $decision->sendAfter === null ? null : CarbonImmutable::instance($decision->sendAfter)->utc(),
        ];

        try {
            $message = DB::transaction(function () use ($row): Message {
                $message = new Message;
                $message->forceFill($row)->save();

                return $message;
            });
        } catch (QueryException $e) {
            // Two runs at once: the other one queued this reminder first today.
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'messages_dedupe_unique')) {
                return Message::query()->where('organization_id', $account->organization_id)->where('dedupe_key', $dedupeKey)
                    ->where('dedupe_on', $row['dedupe_on'])->where('status', '!=', Message::SKIPPED)->firstOrFail();
            }
            throw $e;
        }

        if ($decision->send && $decision->sendAfter !== null && $decision->sendAfter <= $now) {
            SendMessage::dispatch($message->id)->afterCommit();
        }

        return $message;
    }

    /**
     * Who a message goes to: a contact who takes reminders (the primary one
     * first) with an address on this channel, else the account's own.
     *
     * @return array{0: Contact|null, 1: string|null, 2: string}
     */
    private function recipient(CustomerAccount $account, MessageChannel $channel, MessagePurpose $purpose): array
    {
        $contacts = Contact::query()->where('customer_account_id', $account->id)->orderByDesc('is_primary')->orderBy('id')->get();
        foreach ($contacts as $contact) {
            if ($purpose !== MessagePurpose::Marketing && ! $contact->receives_reminders) {
                continue;
            }
            $address = $channel === MessageChannel::Email ? $contact->email : $contact->mobile;
            if ($channel->accepts($address)) {
                return [$contact, $address, $contact->name];
            }
        }

        $own = $channel === MessageChannel::Email ? ($account->email ?? $account->contact_email) : $account->mobile;

        return [null, $channel->accepts($own) ? $own : null, $account->contact_name ?? $account->display_name];
    }

    /** The contact's own decision where they made one; otherwise the account's. */
    private function consented(CustomerAccount $account, ?Contact $contact, ConsentPurpose $purpose): bool
    {
        $decisions = Consent::query()->where('customer_account_id', $account->id)->where('purpose', $purpose->value)->get();
        if ($contact !== null) {
            $own = array_values($decisions->where('contact_id', $contact->id)->map(fn (Consent $c) => $c->toDecision())->all());
            if ($own !== []) {
                return ConsentLedger::isGranted($own, $purpose);
            }
        }

        return ConsentLedger::isGranted(array_values($decisions->whereNull('contact_id')->map(fn (Consent $c) => $c->toDecision())->all()), $purpose);
    }

    private function suppressed(string $organizationId, MessageChannel $channel, string $address): bool
    {
        return MessageSuppression::query()->where('organization_id', $organizationId)->where('channel', $channel->value)
            ->where('address', $channel->normalize($address))->exists();
    }
}
