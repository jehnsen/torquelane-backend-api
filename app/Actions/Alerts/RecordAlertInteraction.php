<?php

declare(strict_types=1);

namespace App\Actions\Alerts;

use App\Actions\Audit\AuditTrail;
use App\Models\AlertInteraction;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Read / dismiss / restore for the caller, in the caller's scope bucket
 * (TenantScope::key()). The bucket comes from the session, never the request:
 * a staff member dismissing an alert decides nothing on a customer's behalf,
 * and vice versa. Ids are not checked against today's alerts: a dismissal of
 * an alert that has since resolved is harmless and simply stops counting.
 */
final class RecordAlertInteraction
{
    public const array ACTIONS = ['read', 'dismiss', 'restore'];

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  list<string>  $alertIds
     */
    public function handle(string $action, array $alertIds): void
    {
        $context = $this->tenancy->require();
        $now = CarbonImmutable::now('UTC');

        DB::transaction(function () use ($context, $action, $alertIds, $now): void {
            foreach (array_values(array_unique($alertIds)) as $alertId) {
                $row = AlertInteraction::query()
                    ->where('user_id', $context->userId)
                    ->where('scope_key', $context->scope->key())
                    ->where('alert_id', $alertId)
                    ->lockForUpdate()
                    ->first()
                    ?? (new AlertInteraction)->forceFill([
                        'user_id' => $context->userId,
                        'scope_key' => $context->scope->key(),
                        'alert_id' => $alertId,
                    ]);
                $before = $row->exists ? AuditTrail::snapshot($row) : null;

                match ($action) {
                    'read' => $row->forceFill(['read_at' => $row->read_at ?? $now]),
                    // As in ../web: dismissing is not reading; a restored alert comes back unread.
                    'dismiss' => $row->forceFill(['dismissed_at' => $row->dismissed_at ?? $now]),
                    default => $row->forceFill(['dismissed_at' => null]),
                };

                if ($row->isDirty()) {
                    $row->save();
                    $this->audit->record($row, $action, $before, AuditTrail::snapshot($row));
                }
            }
        });
    }
}
