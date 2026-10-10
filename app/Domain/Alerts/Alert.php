<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/** Port of ../web's `Alert`. Derived on read, never stored. */
final readonly class Alert
{
    public function __construct(
        /** Deterministic: read/dismiss state is keyed on it. */
        public string $id,
        /** pms_overdue | pms_due_soon | work_order_overdue | approval_sla_breach | document_expiry | driver_licence_expiry | equipment_service_overdue | equipment_service_due_soon | equipment_end_of_life | equipment_replace_soon */
        public string $kind,
        /** critical | warning | info */
        public string $severity,
        public string $title,
        public string $body,
        public ?string $vehicleId,
        public string $href,
        /** Days until (negative: past) the thing this alert is about. */
        public int $daysRemaining,
    ) {}
}
