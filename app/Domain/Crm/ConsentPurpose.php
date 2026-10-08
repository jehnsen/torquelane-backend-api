<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * What a customer agreed to (Data Privacy Act, RA 10173). `service_records`
 * is the floor: without it the shop cannot keep a job history at all, so an
 * account cannot be created without it granted.
 */
enum ConsentPurpose: string
{
    case ServiceRecords = 'service_records';
    case ServiceReminders = 'service_reminders';
    case Marketing = 'marketing';
    case VehicleHistorySharing = 'vehicle_history_sharing';
}
