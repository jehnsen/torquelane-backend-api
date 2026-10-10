<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Our account → the code (Xero) or name (QuickBooks Online) the accountant's
 * own books use, so the journal export imports without remapping.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $account_id
 * @property string $target
 * @property string|null $external_code
 * @property string|null $external_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AccountExportMapping extends Model
{
    use BelongsToOrganization;
    use HasUlids;
}
