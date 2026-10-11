<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * A customer's unified timeline. It is BUILT ON READ from the records
 * themselves (jobs, invoices, payments, bookings, messages, notes, tasks,
 * consent decisions, feedback): nothing is copied into a feed that could
 * drift from them. Newest first; two entries at the same instant keep a fixed
 * order (by kind, then id) so a page never reshuffles.
 */
final class Timeline
{
    /**
     * @param  list<TimelineEntry>  ...$sources
     * @return list<TimelineEntry>
     */
    public static function merge(array ...$sources): array
    {
        $entries = array_merge(...$sources);
        usort($entries, function (TimelineEntry $a, TimelineEntry $b): int {
            if ($a->at != $b->at) {
                return $b->at <=> $a->at;
            }

            return [$a->kind, $a->id] <=> [$b->kind, $b->id];
        });

        return $entries;
    }
}
