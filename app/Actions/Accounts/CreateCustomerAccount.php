<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Actions\Audit\AuditTrail;
use App\Actions\Consents\ConsentWriter;
use App\Domain\Crm\ConsentDecision;
use App\Domain\Crm\ConsentLedger;
use App\Domain\Crm\ConsentPurpose;
use App\Models\CustomerAccount;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Opens a customer account together with its opening consent decisions, in
 * one transaction. `service_records` must be among them, granted: without it
 * the shop cannot keep the job history the account exists for.
 */
final class CreateCustomerAccount
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly ConsentWriter $consents,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by StoreCustomerAccountRequest
     * @param  list<array{purpose: string, granted: bool, channel: string, contact_id?: string|null, evidence?: string|null, captured_at?: string|null}>  $consents
     */
    public function handle(array $attributes, array $consents): CustomerAccount
    {
        $opening = array_map(fn (array $decision, int $i): ConsentDecision => new ConsentDecision(
            sprintf('%026d', $i),
            ConsentPurpose::from($decision['purpose']),
            $decision['granted'],
            CarbonImmutable::now('UTC'),
        ), $consents, array_keys($consents));

        if (! ConsentLedger::satisfiesAccountOpening($opening)) {
            throw ValidationException::withMessages(['consents' => 'Record the customer\'s consent to keeping service records before opening the account.']);
        }

        if (($attributes['account_type'] ?? null) === CustomerAccount::INDIVIDUAL && ! isset($attributes['display_name'])) {
            $attributes['display_name'] = trim(sprintf('%s %s', self::text($attributes, 'first_name'), self::text($attributes, 'last_name')));
        }

        return DB::transaction(function () use ($attributes, $consents): CustomerAccount {
            $account = new CustomerAccount;
            $account->forceFill($attributes)->save();
            $this->audit->record($account, 'created', null, AuditTrail::snapshot($account));

            foreach ($consents as $decision) {
                $this->consents->append($account, $decision);
            }

            return $account;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function text(array $attributes, string $key): string
    {
        return is_string($attributes[$key] ?? null) ? $attributes[$key] : '';
    }
}
