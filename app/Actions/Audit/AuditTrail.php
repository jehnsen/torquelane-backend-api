<?php

declare(strict_types=1);

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\Organization;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use LogicException;

/**
 * Writes one append-only audit row per change, inside the Action's own
 * transaction (R3), so a rolled-back change leaves no audit row and a
 * committed one always has one.
 *
 * The actor, their role and the request id come from the tenant context and
 * the request, never from the caller. The organization, branch and customer
 * account are read off the audited row itself.
 */
final class AuditTrail
{
    /** Never written into a snapshot. */
    private const array REDACTED = ['password', 'remember_token', 'token_hash'];

    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  User|null  $actor  only where there is no tenant context yet (accepting an invitation)
     */
    public function record(Model $entity, string $action, ?array $before, ?array $after, ?User $actor = null): AuditLog
    {
        $context = $this->tenancy->context();
        $entityId = $entity->getKey();
        if (! is_string($entityId)) {
            throw new LogicException('Audited entities have ULID keys.');
        }

        $log = new AuditLog;
        $log->forceFill([
            'occurred_at' => CarbonImmutable::now('UTC'),
            'request_id' => Context::get('request_id'),
            'actor_id' => $actor !== null ? $actor->id : $context?->userId,
            'actor_role' => $actor !== null ? $actor->role->value : $context?->role->value,
            'organization_id' => $entity instanceof Organization ? $entityId : self::raw($entity, 'organization_id'),
            'branch_id' => $entity instanceof Branch ? $entityId : self::raw($entity, 'branch_id'),
            'customer_account_id' => $entity instanceof CustomerAccount ? $entityId : self::raw($entity, 'customer_account_id'),
            'entity_type' => Str::snake(class_basename($entity)),
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before === null ? null : self::redact($before),
            'after' => $after === null ? null : self::redact($after),
        ])->save();

        return $log;
    }

    /** An owner column, if this model has one (strict mode forbids reading absent attributes). */
    private static function raw(Model $entity, string $column): ?string
    {
        $value = $entity->getAttributes()[$column] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A model's serialisable state, as the API would render its attributes.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model): array
    {
        return self::redact($model->attributesToArray());
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private static function redact(array $values): array
    {
        return array_diff_key($values, array_flip(self::REDACTED));
    }
}
