<?php

declare(strict_types=1);

namespace App\Actions\Numbering;

use App\Domain\Numbering\DocumentNumberFormat;
use App\Domain\Numbering\DocumentType;
use App\Domain\Numbering\IssuedNumber;
use App\Models\DocumentSeries;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Issues document numbers (R8): gap-free, never reused.
 *
 * Must be called inside the transaction that creates the document. The
 * series row is created on first use (`insert … on conflict do nothing`),
 * then locked (`select … for update`) and incremented. So:
 *  - a rollback of the caller's transaction returns the number (no gap);
 *  - concurrent issuers serialise on the row lock (no duplicate);
 *  - two first uses racing on a new period both see the one row (the loser's
 *    insert waits on the unique index, then does nothing).
 */
final class DocumentNumbers
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function issue(string $organizationId, ?string $branchId, DocumentType $type, DateTimeInterface $issuedAt): IssuedNumber
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException('DocumentNumbers::issue() must run inside the transaction that creates the document.');
        }

        $period = DocumentNumberFormat::periodKey($issuedAt);
        $now = CarbonImmutable::now('UTC');

        DocumentSeries::query()->insertOrIgnore([
            // Same form HasUlids generates.
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $organizationId,
            'branch_id' => $branchId,
            'doc_type' => $type->value,
            'period_key' => $period,
            'prefix' => $type->defaultPrefix(),
            'next_number' => 1,
            'padding' => DocumentNumberFormat::DEFAULT_PADDING,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $series = DocumentSeries::query()
            ->where('organization_id', $organizationId)
            ->where('branch_id', $branchId)
            ->where('doc_type', $type->value)
            ->where('period_key', $period)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $series->next_number;
        DocumentSeries::query()->whereKey($series->id)->update(['next_number' => $number + 1, 'updated_at' => $now]);

        return new IssuedNumber($number, DocumentNumberFormat::format($series->prefix, $period, $number, $series->padding), $period);
    }
}
