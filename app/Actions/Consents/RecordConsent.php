<?php

declare(strict_types=1);

namespace App\Actions\Consents;

use App\Models\Consent;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;

/**
 * Appends a decision to the ledger. Allowed on a suspended account too:
 * withdrawing consent is the customer's right whatever their standing.
 */
final class RecordConsent
{
    public function __construct(private readonly ConsentWriter $writer) {}

    /**
     * @param  array{purpose: string, granted: bool, channel: string, contact_id?: string|null, evidence?: string|null, captured_at?: string|null}  $decision
     */
    public function handle(CustomerAccount $account, array $decision): Consent
    {
        return DB::transaction(function () use ($account, $decision): Consent {
            // Serialise decisions on one account so "latest" is unambiguous.
            CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();

            return $this->writer->append($account, $decision);
        });
    }
}
