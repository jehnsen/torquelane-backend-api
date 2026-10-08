<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Actions\Audit\AuditTrail;
use App\Actions\Fleet\FleetQueries;
use App\Documents\DocumentStorage;
use App\Domain\Documents\DocumentKind;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\WorkOrder;
use App\Tenancy\TenantManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Files a document under an account (and optionally a vehicle). A vehicle's
 * document is filed under the account that owns the vehicle NOW; it stays
 * with that account if the vehicle is later sold on.
 *
 * The file is written first, then the row in a transaction; if the
 * transaction fails the file is deleted, so there is never a row without
 * its file. On delete the row goes first and the file after commit, so a
 * rolled-back delete never loses a file (a leftover file with no row is
 * unreachable: every download goes through the row).
 */
final class ManageDocuments
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
        private readonly DocumentStorage $storage,
        private readonly FleetQueries $fleet,
    ) {}

    /**
     * @param  array{name?: string|null, expires_on?: string|null, reference_number?: string|null, issued_on?: string|null, issuing_body?: string|null, notes?: string|null}  $meta
     */
    public function upload(CustomerAccount $account, ?Vehicle $vehicle, DocumentKind $kind, UploadedFile $file, array $meta, ?WorkOrder $order = null): Document
    {
        $context = $this->tenancy->require();
        if (($meta['expires_on'] ?? null) !== null && ! $kind->expires()) {
            throw ValidationException::withMessages(['expires_on' => 'Only registration, insurance, emission test, franchise and warranty documents carry an expiry.']);
        }

        $document = new Document;
        $document->forceFill([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $context->organizationId(),
            'customer_account_id' => $account->id,
            'vehicle_id' => $vehicle?->id,
            'work_order_id' => $order?->id,
            'kind' => $kind,
            'name' => $meta['name'] ?? $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'expires_on' => $meta['expires_on'] ?? null,
            'reference_number' => $meta['reference_number'] ?? null,
            'issued_on' => $meta['issued_on'] ?? null,
            'issuing_body' => $meta['issuing_body'] ?? null,
            'notes' => $meta['notes'] ?? null,
            'uploaded_by' => $context->userId,
            'uploaded_by_name' => User::query()->findOrFail($context->userId)->name,
            'uploaded_on' => $this->fleet->todayDate(),
        ]);

        $path = $this->storage->store($document, $file);

        try {
            return DB::transaction(function () use ($document, $path): Document {
                $document->forceFill(['storage_path' => $path])->save();
                $this->audit->record($document, 'uploaded', null, AuditTrail::snapshot($document));

                return $document;
            });
        } catch (Throwable $e) {
            $this->storage->delete($path);

            throw $e;
        }
    }

    public function delete(Document $document): void
    {
        DB::transaction(function () use ($document): void {
            $locked = Document::query()->lockForUpdate()->findOrFail($document->id);
            $before = AuditTrail::snapshot($locked);
            $path = $locked->storage_path;

            $locked->delete();
            $this->audit->record($locked, 'deleted', $before, null);

            if ($path !== null) {
                DB::afterCommit(fn () => $this->storage->delete($path));
            }
        });
    }
}
