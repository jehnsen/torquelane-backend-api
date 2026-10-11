<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Why a message is sent, which decides the consent it needs. A reminder or a
 * feedback request is about the customer's own vehicle and service
 * (`service_reminders`); a campaign is marketing (`marketing`); a
 * transactional message (a receipt, a confirmation the customer asked for)
 * needs none.
 */
enum MessagePurpose: string
{
    case ServiceReminder = 'service_reminder';
    case Marketing = 'marketing';
    case Feedback = 'feedback';
    case Transactional = 'transactional';

    public function label(): string
    {
        return match ($this) {
            self::ServiceReminder => 'Service reminder',
            self::Marketing => 'Marketing',
            self::Feedback => 'Feedback request',
            self::Transactional => 'Transactional',
        };
    }

    public function consent(): ?ConsentPurpose
    {
        return match ($this) {
            self::ServiceReminder, self::Feedback => ConsentPurpose::ServiceReminders,
            self::Marketing => ConsentPurpose::Marketing,
            self::Transactional => null,
        };
    }
}
