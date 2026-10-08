<?php

declare(strict_types=1);

namespace App\Actions\Contacts;

use App\Actions\Audit\AuditTrail;
use App\Models\Contact;
use App\Models\CustomerAccount;
use Illuminate\Support\Facades\DB;

/**
 * At most one primary contact per account: marking one primary demotes the
 * previous one in the same transaction (a partial unique index backs this up).
 */
final class SaveContact
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveContactRequest
     */
    public function create(CustomerAccount $account, array $attributes): Contact
    {
        return DB::transaction(function () use ($account, $attributes): Contact {
            CustomerAccount::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
            if (($attributes['is_primary'] ?? false) === true) {
                $this->demotePrimary($account->id, null);
            }

            $contact = new Contact;
            $contact->forceFill(['customer_account_id' => $account->id] + $attributes)->save();
            $this->audit->record($contact, 'created', null, AuditTrail::snapshot($contact));

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $attributes  validated by SaveContactRequest
     */
    public function update(Contact $contact, array $attributes): Contact
    {
        return DB::transaction(function () use ($contact, $attributes): Contact {
            CustomerAccount::query()->whereKey($contact->customer_account_id)->lockForUpdate()->firstOrFail();
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);
            if (($attributes['is_primary'] ?? false) === true) {
                $this->demotePrimary($locked->customer_account_id, $locked->id);
            }

            $before = AuditTrail::snapshot($locked);
            $locked->forceFill($attributes)->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    private function demotePrimary(string $accountId, ?string $except): void
    {
        $current = Contact::query()
            ->where('customer_account_id', $accountId)
            ->where('is_primary', true)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->first();

        if ($current !== null) {
            $before = AuditTrail::snapshot($current);
            $current->forceFill(['is_primary' => false])->save();
            $this->audit->record($current, 'updated', $before, AuditTrail::snapshot($current));
        }
    }
}
