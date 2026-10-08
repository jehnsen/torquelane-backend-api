<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/**
 * Order numbers are issued at draft → pending_approval, never at creation: a
 * draft that never leaves the shop must not burn a number. Until then the
 * reference is '' (the partial unique index excludes it).
 *
 * Numbers come from document_series (DocumentNumbers::issue, R8). `next` is
 * ../web's scan-for-the-highest, kept only so its golden cases replay.
 */
final class WorkOrderReference
{
    public const string DRAFT = '';

    public static function has(string $reference): bool
    {
        return trim($reference) !== '';
    }

    public static function display(string $reference): string
    {
        return self::has($reference) ? $reference : 'Draft — not yet numbered';
    }

    /**
     * @param  list<string>  $existing
     */
    public static function next(array $existing, int $year): string
    {
        $highest = 0;
        foreach ($existing as $reference) {
            if (preg_match('/^WO-(\d{4})-(\d+)$/', $reference, $m) !== 1 || (int) $m[1] !== $year) {
                continue;
            }
            $highest = max($highest, (int) $m[2]);
        }

        return sprintf('WO-%d-%s', $year, str_pad((string) ($highest + 1), 4, '0', STR_PAD_LEFT));
    }
}
