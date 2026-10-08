<?php

declare(strict_types=1);

namespace App\Domain\Alerts;

/**
 * What alert derivation reads from a work order. Work orders arrive in a later
 * phase; until then callers pass none, but the rules are ported (and
 * golden-tested) now so the alert set is complete from day one.
 */
final readonly class WorkOrderAlertFacts
{
    public function __construct(
        public string $id,
        public string $reference,
        public string $title,
        public string $status,
        /** Y-m-d */
        public string $scheduledFor,
        public string $vehicleId,
        /** ISO-8601 instant the order entered pending_approval, or null. */
        public ?string $pendingApprovalEnteredAt,
        public int $pendingLineCount,
    ) {}
}
