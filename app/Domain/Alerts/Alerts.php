<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

use App\Domain\Documents\DocumentFacts;
use App\Domain\Fleet\FleetThresholds;
use App\Domain\Fleet\VehicleHealth;
use App\Domain\Shared\BusinessHours;
use App\Domain\Shared\Calendar;
use App\Domain\Shared\JsMath;
use App\Domain\Shared\WebFormat;
use DateTimeImmutable;

/**
 * Port of ../web/lib/alerts.ts. Alerts are DERIVED on every read, never
 * stored, so one can never outlive the condition behind it. Ids are
 * deterministic (`pms:<vehicle>:<task>`, `wo:<order>`, `approval-sla:<order>`,
 * `doc:<document>`, `licence:<vehicle>`) so read/dismiss state sticks.
 * Titles and bodies are verbatim (golden-tested against alerts.json).
 *
 * Equipment joins the same list (Phase 10, not in ../web): a tool due or
 * overdue for service (`equipment:<tool>:service`) and one near or past its
 * rated life (`equipment:<tool>:life`).
 */
final class Alerts
{
    private const array SEVERITY_RANK = ['critical' => 0, 'warning' => 1, 'info' => 2];

    private const array LIMIT_LABEL = ['hours' => 'runtime hours', 'cycles' => 'job cycles', 'cups' => 'cups served', 'days' => 'calendar days'];

    /**
     * @param  list<VehicleHealth>  $health
     * @param  list<WorkOrderAlertFacts>  $workOrders
     * @param  list<DocumentFacts>  $documents
     * @param  list<EquipmentAlertFacts>  $equipment
     * @return list<Alert>
     */
    public static function build(array $health, array $workOrders, array $documents, int $slaHours, DateTimeImmutable $today, array $equipment = []): array
    {
        $alerts = [];

        // 1. Breached and closing service intervals.
        foreach ($health as $entry) {
            foreach ($entry->items as $item) {
                if ($item->status === 'ok') {
                    continue;
                }
                $overdue = $item->status === 'overdue';
                $distance = $item->kmRemaining <= 0
                    ? WebFormat::km(abs($item->kmRemaining)).' past the limit'
                    : WebFormat::km($item->kmRemaining).' remaining';

                $alerts[] = new Alert(
                    "pms:{$entry->vehicle->id}:{$item->task->id}",
                    $overdue ? 'pms_overdue' : 'pms_due_soon',
                    $overdue ? 'critical' : 'warning',
                    "{$item->task->name} — {$entry->vehicle->plateNumber}",
                    sprintf('%s · %s · %s · governed by %s.', $overdue ? 'Overdue' : 'Due soon', $distance, WebFormat::dayDelta($item->daysRemaining), $item->governedBy),
                    $entry->vehicle->id,
                    "/vehicles/{$entry->vehicle->id}",
                    $item->daysRemaining,
                );
            }
        }

        // 2. Booked work that slipped past its slot without closing.
        foreach ($workOrders as $order) {
            if ($order->status !== 'scheduled' && $order->status !== 'in_progress') {
                continue;
            }
            $days = Calendar::differenceInCalendarDays(Calendar::parseDate($order->scheduledFor), $today);
            if ($days >= 0) {
                continue;
            }

            $alerts[] = new Alert(
                "wo:{$order->id}",
                'work_order_overdue',
                'warning',
                "{$order->reference} is past its slot",
                sprintf(
                    '%s was booked for %s and is still %s.',
                    $order->title,
                    self::replaceFirst(' overdue', ' ago', WebFormat::dayDelta($days)),
                    self::replaceFirst('_', ' ', $order->status),
                ),
                $order->vehicleId,
                "/work-orders/{$order->id}",
                $days,
            );
        }

        // 3. Lines waiting past the approval SLA, in business hours.
        foreach ($workOrders as $order) {
            if ($order->status !== 'pending_approval' || $order->pendingApprovalEnteredAt === null) {
                continue;
            }
            $waited = BusinessHours::between(new DateTimeImmutable($order->pendingApprovalEnteredAt), $today);
            if ($waited <= $slaHours) {
                continue;
            }
            $overBy = JsMath::round(($waited - $slaHours) * 10) / 10;

            $alerts[] = new Alert(
                "approval-sla:{$order->id}",
                'approval_sla_breach',
                'warning',
                "{$order->reference} is waiting on approval",
                sprintf(
                    '%d %s sat in pending_approval for %sh — %sh past the %dh SLA. Escalate to the next approval band.',
                    $order->pendingLineCount,
                    $order->pendingLineCount === 1 ? 'line has' : 'lines have',
                    JsMath::toString($waited),
                    JsMath::toString($overBy),
                    $slaHours,
                ),
                $order->vehicleId,
                "/work-orders/{$order->id}",
                -1,
            );
        }

        // 4. Compliance documents and warranties approaching renewal.
        foreach ($documents as $document) {
            if ($document->expiresOn === null || $document->expiresOn === '') {
                continue;
            }
            $days = Calendar::differenceInCalendarDays(Calendar::parseDate($document->expiresOn), $today);
            if ($days > FleetThresholds::DOCUMENT_EXPIRY_WARNING_DAYS) {
                continue;
            }

            $alerts[] = new Alert(
                "doc:{$document->id}",
                'document_expiry',
                $days < 0 ? 'critical' : 'warning',
                $days < 0 ? "{$document->name} has expired" : "{$document->name} expires soon",
                $document->kind->label().' '.WebFormat::dayDelta($days).'.',
                $document->vehicleId,
                $document->vehicleId !== null ? "/vehicles/{$document->vehicleId}" : '/documents',
                $days,
            );
        }

        // 5. Driver licences approaching renewal.
        foreach ($health as $entry) {
            $vehicle = $entry->vehicle;
            if ($vehicle->driverLicenceExpiry === null || $vehicle->driverLicenceExpiry === '') {
                continue;
            }
            $days = Calendar::differenceInCalendarDays(Calendar::parseDate($vehicle->driverLicenceExpiry), $today);
            if ($days > FleetThresholds::DOCUMENT_EXPIRY_WARNING_DAYS) {
                continue;
            }

            $alerts[] = new Alert(
                "licence:{$vehicle->id}",
                'driver_licence_expiry',
                $days < 0 ? 'critical' : 'warning',
                $days < 0 ? "{$vehicle->assignedTo}'s licence has expired" : "{$vehicle->assignedTo}'s licence expires soon",
                "Driver of {$vehicle->plateNumber} ".WebFormat::dayDelta($days).'.',
                $vehicle->id,
                "/vehicles/{$vehicle->id}",
                $days,
            );
        }

        // 6. Tools due for service, and tools near the end of their rated life.
        foreach ($equipment as $tool) {
            if ($tool->serviceLevel === 'overdue' || $tool->serviceLevel === 'due_soon') {
                $overdue = $tool->serviceLevel === 'overdue';
                $days = $overdue || $tool->forecastDays === null ? 0 : (int) ceil($tool->forecastDays);
                $pace = $overdue || $tool->forecastDays === null
                    ? ''
                    : sprintf(' · about %d %s left at the recent pace', $days, $days === 1 ? 'day' : 'days');

                $alerts[] = new Alert(
                    "equipment:{$tool->id}:service",
                    $overdue ? 'equipment_service_overdue' : 'equipment_service_due_soon',
                    $overdue ? 'critical' : 'warning',
                    ($tool->maintenanceTask ?? 'Service')." — {$tool->name}",
                    sprintf(
                        '%s · %d%% through its service interval (%s)%s.',
                        $overdue ? 'Overdue' : 'Due soon',
                        (int) JsMath::round($tool->serviceProgress * 100),
                        self::LIMIT_LABEL[$tool->binding ?? ''] ?? 'service interval',
                        $pace,
                    ),
                    null,
                    "/shop/equipment/{$tool->id}",
                    $days,
                );
            }

            if ($tool->lifeLevel === 'end_of_life' || $tool->lifeLevel === 'replace_soon') {
                $ended = $tool->lifeLevel === 'end_of_life';

                $alerts[] = new Alert(
                    "equipment:{$tool->id}:life",
                    $ended ? 'equipment_end_of_life' : 'equipment_replace_soon',
                    $ended ? 'critical' : 'warning',
                    $ended ? "{$tool->name} has reached its rated life" : "{$tool->name} is close to its rated life",
                    sprintf(
                        '%s of %s rated hours used (%d%%).',
                        number_format(JsMath::round($tool->lifeUsedHours)),
                        number_format(JsMath::round($tool->lifeRatedHours)),
                        $tool->lifeRatedHours > 0 ? (int) JsMath::round($tool->lifeUsedHours / $tool->lifeRatedHours * 100) : 0,
                    ),
                    null,
                    "/shop/equipment/{$tool->id}",
                    0,
                );
            }
        }

        // Stable: equal keys keep derivation order, as Array.prototype.sort does.
        usort($alerts, function (Alert $a, Alert $b): int {
            $bySeverity = self::SEVERITY_RANK[$a->severity] - self::SEVERITY_RANK[$b->severity];

            return $bySeverity !== 0 ? $bySeverity : $a->daysRemaining - $b->daysRemaining;
        });

        return $alerts;
    }

    /**
     * @param  list<Alert>  $alerts
     * @param  list<string>  $readIds
     * @param  list<string>  $dismissedIds
     */
    public static function view(array $alerts, array $readIds, array $dismissedIds): AlertView
    {
        $dismissed = array_flip($dismissedIds);
        $read = array_flip($readIds);

        $visible = array_values(array_filter($alerts, fn (Alert $alert): bool => ! isset($dismissed[$alert->id])));
        $unread = array_values(array_filter($visible, fn (Alert $alert): bool => ! isset($read[$alert->id])));

        return new AlertView(
            $alerts,
            $visible,
            $unread,
            count($unread),
            count(array_filter($alerts, fn (Alert $alert): bool => isset($dismissed[$alert->id]))),
        );
    }

    private static function replaceFirst(string $search, string $replace, string $subject): string
    {
        $position = strpos($subject, $search);

        return $position === false ? $subject : substr_replace($subject, $replace, $position, strlen($search));
    }
}
