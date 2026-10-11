<?php

declare(strict_types=1);

namespace App\Domain\Crm\Reminders;

use DateTimeImmutable;

/** Whether a message may go, and when; or why it may not. */
final readonly class GateDecision
{
    public const string NO_CONSENT = 'no_consent';

    public const string SUPPRESSED = 'opted_out';

    public const string NO_ADDRESS = 'no_address';

    public const string RECENTLY_SENT = 'recently_sent';

    private function __construct(
        public bool $send,
        public ?string $skipReason,
        public ?DateTimeImmutable $sendAfter,
    ) {}

    public static function send(DateTimeImmutable $after): self
    {
        return new self(true, null, $after);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason, null);
    }
}
