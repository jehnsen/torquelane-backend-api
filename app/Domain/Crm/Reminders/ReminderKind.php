<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

/** What a service reminder is about. Listed in the order they win when one account has several. */
enum ReminderKind: string
{
    case PmsDue = 'pms_due';
    case DocumentExpiry = 'document_expiry';
    case DetailingRepeat = 'detailing_repeat';
    case LapsedCustomer = 'lapsed_customer';

    public function label(): string
    {
        return match ($this) {
            self::PmsDue => 'Maintenance due',
            self::DocumentExpiry => 'Document expiring',
            self::DetailingRepeat => 'Detailing due again',
            self::LapsedCustomer => 'Lapsed customer',
        };
    }

    /** Whether the rule takes a lead time in days. */
    public function hasLeadDays(): bool
    {
        return $this === self::DocumentExpiry;
    }
}
