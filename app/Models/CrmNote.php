<?php

declare(strict_types=1);

namespace App\Models;

use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A note staff keep about a customer account.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $customer_account_id
 * @property string|null $vehicle_id
 * @property string $body
 * @property bool $pinned
 * @property string|null $author_id
 * @property string $author_name
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class CrmNote extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $table = 'crm_notes';

    protected $attributes = ['pinned' => false];

    protected function casts(): array
    {
        return [
            'pinned' => 'boolean',
        ];
    }
}
