<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\MessageChannel;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * An address that opted out of a channel: nothing but transactional messages goes to it.
 *
 * @property string $id
 * @property string $organization_id
 * @property MessageChannel $channel
 * @property string $address
 * @property string $reason
 * @property string|null $customer_account_id
 * @property CarbonImmutable $created_at
 */
final class MessageSuppression extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const UPDATED_AT = null;

    protected $attributes = ['reason' => 'unsubscribed'];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
        ];
    }
}
