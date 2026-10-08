<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Response;

/**
 * A claimed Idempotency-Key and, once the request finished, its response.
 * Written only by EnforceIdempotency. Infrastructure, not tenant data: it is
 * keyed per user, and pruned 24h after creation.
 *
 * @property string $id
 * @property string $user_id
 * @property string $scope_hash
 * @property string $idempotency_key
 * @property string $request_hash
 * @property int|null $response_status
 * @property array<string, string>|null $response_headers
 * @property string|null $response_body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable $expires_at
 */
final class IdempotencyKey extends Model
{
    use HasUlids;
    use MassPrunable;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'response_headers' => 'array',
            'created_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::query()->where('expires_at', '<=', now());
    }

    public function isPending(): bool
    {
        return $this->completed_at === null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isAbandoned(int $afterSeconds): bool
    {
        return $this->isPending() && $this->created_at->addSeconds($afterSeconds)->isPast();
    }

    public function toReplayResponse(): Response
    {
        return new Response(
            $this->response_body ?? '',
            $this->response_status ?? 200,
            $this->response_headers ?? [],
        );
    }
}
