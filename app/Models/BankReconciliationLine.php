<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A journal line ticked off against a statement: it has cleared the bank. A
 * line clears once (unique), and is ticked or unticked only while its
 * reconciliation is open (trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $bank_reconciliation_id
 * @property string $journal_line_id
 * @property CarbonImmutable $created_at
 */
final class BankReconciliationLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;
}
