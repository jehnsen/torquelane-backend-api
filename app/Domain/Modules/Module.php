<?php

declare(strict_types=1);

namespace App\Domain\Modules;

enum Module: string
{
    case RepairPms = 'repair_pms';
    case Detailing = 'detailing';
    case Equipment = 'equipment';
    case Pos = 'pos';
    case Crm = 'crm';
    case Procurement = 'procurement';
    case Accounting = 'accounting';

    public function label(): string
    {
        return match ($this) {
            self::RepairPms => 'Repair & PMS',
            self::Detailing => 'Detailing',
            self::Equipment => 'Equipment monitoring',
            self::Pos => 'Café POS',
            self::Crm => 'CRM',
            self::Procurement => 'Procurement',
            self::Accounting => 'Accounting',
        };
    }
}
