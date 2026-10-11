<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

/**
 * A reminder worth sending, before anyone has asked whether it MAY be sent.
 * `dedupeKey` names the reminder and its subject: the same key within the
 * cooldown is the same reminder again.
 */
final readonly class ReminderCandidate
{
    /**
     * @param  array<string, string>  $values  what the template's placeholders become
     */
    public function __construct(
        public ReminderKind $kind,
        public string $accountId,
        public string $subjectType,
        public string $subjectId,
        public string $dedupeKey,
        public ?string $branchId,
        public array $values,
    ) {}
}
