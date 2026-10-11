<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Crm\MessageChannel;
use App\Domain\Crm\MessagePurpose;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A message's wording for one channel and purpose, with `{{placeholders}}`.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property MessageChannel $channel
 * @property MessagePurpose $purpose
 * @property string|null $subject
 * @property string $body
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MessageTemplate extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['channel' => 'email', 'is_active' => true];

    protected function casts(): array
    {
        return [
            'channel' => MessageChannel::class,
            'purpose' => MessagePurpose::class,
            'is_active' => 'boolean',
        ];
    }
}
