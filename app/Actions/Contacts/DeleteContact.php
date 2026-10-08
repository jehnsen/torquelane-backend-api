<?php

declare(strict_types=1);

namespace App\Actions\Contacts;

use App\Actions\Audit\AuditTrail;
use App\Exceptions\ConflictException;
use App\Models\Consent;
use App\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * A contact with consent decisions on record cannot be deleted: the ledger is
 * evidence and must keep naming who decided.
 */
final class DeleteContact
{
    public function __construct(private readonly AuditTrail $audit) {}

    public function handle(Contact $contact): void
    {
        DB::transaction(function () use ($contact): void {
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            if (Consent::query()->where('contact_id', $locked->id)->exists()) {
                throw new ConflictException('This contact has consent decisions on record and cannot be deleted.');
            }

            $before = AuditTrail::snapshot($locked);
            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);
        });
    }
}
