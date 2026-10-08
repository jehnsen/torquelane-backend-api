<?php

declare(strict_types=1);

namespace App\Actions\Consents;

use App\Actions\Audit\AuditTrail;
use App\Domain\Crm\ConsentChannel;
use App\Domain\Crm\ConsentPurpose;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\CustomerAccount;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Appends one consent decision. Callers own the transaction.
 *
 *  - A portal user records their own decisions, through the portal channel
 *    only. Staff record what the customer told them (in person, on paper, by
 *    phone…), never "portal" (they are not the customer) and never "import"
 *    (reserved for data carried over by a seeder).
 *  - A contact-level decision must name a contact of the same account (also a
 *    composite foreign key).
 */
final class ConsentWriter
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{purpose: string, granted: bool, channel: string, contact_id?: string|null, evidence?: string|null, captured_at?: string|null}  $decision
     */
    public function append(CustomerAccount $account, array $decision): Consent
    {
        $context = $this->tenancy->require();
        $channel = ConsentChannel::from($decision['channel']);

        if ($context->isPortal() && $channel !== ConsentChannel::Portal) {
            throw ValidationException::withMessages(['channel' => 'Decisions you make yourself are recorded through the portal channel.']);
        }
        if ($context->isStaff() && in_array($channel, [ConsentChannel::Portal, ConsentChannel::Import], true)) {
            throw ValidationException::withMessages(['channel' => 'Record how the customer gave you this decision (in person, paper form, email, SMS or phone).']);
        }

        $contactId = $decision['contact_id'] ?? null;
        if ($contactId !== null && ! Contact::query()->whereKey($contactId)->where('customer_account_id', $account->id)->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'That contact does not belong to this customer account.']);
        }

        $capturedAt = isset($decision['captured_at']) ? CarbonImmutable::parse($decision['captured_at'])->utc() : CarbonImmutable::now('UTC');

        $consent = new Consent;
        $consent->forceFill([
            'customer_account_id' => $account->id,
            'contact_id' => $contactId,
            'purpose' => ConsentPurpose::from($decision['purpose']),
            'granted' => $decision['granted'],
            'channel' => $channel,
            'captured_at' => $capturedAt,
            'captured_by' => $context->userId,
            'evidence' => $decision['evidence'] ?? null,
        ])->save();

        $this->audit->record($consent, $consent->granted ? 'granted' : 'withdrawn', null, AuditTrail::snapshot($consent));

        return $consent;
    }
}
