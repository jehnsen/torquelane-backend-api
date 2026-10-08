<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Current consent = the latest decision per purpose. The ledger is never
 * edited: withdrawing consent appends a `granted: false` row, so the history
 * of who agreed to what, when, and how, survives.
 *
 * "Latest" is by `captured_at` (when the customer decided, which may be
 * backdated from a paper form), then by id (ULID, insertion order) for two
 * decisions captured at the same instant. Never by row order.
 */
final class ConsentLedger
{
    /**
     * The latest decision for every purpose, in ConsentPurpose order; null
     * where nothing was ever recorded. Pass one subject's decisions (an
     * account's own, or one contact's).
     *
     * @param  list<ConsentDecision>  $decisions
     * @return array<string, ConsentDecision|null> keyed by purpose value
     */
    public static function current(array $decisions): array
    {
        $current = [];
        foreach (ConsentPurpose::cases() as $purpose) {
            $current[$purpose->value] = null;
        }

        foreach ($decisions as $decision) {
            $held = $current[$decision->purpose->value];
            if ($held === null || self::isLater($decision, $held)) {
                $current[$decision->purpose->value] = $decision;
            }
        }

        return $current;
    }

    /**
     * @param  list<ConsentDecision>  $decisions
     */
    public static function isGranted(array $decisions, ConsentPurpose $purpose): bool
    {
        $current = self::current($decisions)[$purpose->value];

        return $current !== null && $current->granted;
    }

    /**
     * Whether a new account's opening decisions are acceptable: service_records
     * must be among them, granted.
     *
     * @param  list<ConsentDecision>  $opening
     */
    public static function satisfiesAccountOpening(array $opening): bool
    {
        return self::isGranted($opening, ConsentPurpose::ServiceRecords);
    }

    private static function isLater(ConsentDecision $candidate, ConsentDecision $held): bool
    {
        if ($candidate->capturedAt != $held->capturedAt) {
            return $candidate->capturedAt > $held->capturedAt;
        }

        return strcmp($candidate->id, $held->id) > 0;
    }
}
