<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * Splits a whole number of centavos by weights so the parts add up to the
 * whole exactly (largest remainder, ties to the earlier key). Used where one
 * stored total has to be shown on several accounts: the sum never drifts.
 */
final class Apportion
{
    /**
     * @param  array<string, int>  $weights  keyed by whatever the caller splits across; non-negative
     * @return array<string, int> the same keys; sums to $total. All-zero weights give everything to the first key.
     */
    public static function split(int $total, array $weights): array
    {
        if ($total < 0) {
            throw new InvalidArgumentException('Only a non-negative total is split.');
        }
        if ($weights === []) {
            throw new InvalidArgumentException('Nothing to split across.');
        }
        $sum = 0;
        foreach ($weights as $weight) {
            if ($weight < 0) {
                throw new InvalidArgumentException('A weight is never negative.');
            }
            $sum += $weight;
        }

        $keys = array_keys($weights);
        if ($sum === 0) {
            $everything = array_fill_keys($keys, 0);
            $everything[$keys[0]] = $total;

            return $everything;
        }

        $parts = [];
        $remainders = [];
        $given = 0;
        foreach ($weights as $key => $weight) {
            // intdiv on a product that fits: centavo totals × weights stay far inside 64 bits for real invoices.
            $product = $total * $weight;
            $parts[$key] = intdiv($product, $sum);
            $remainders[$key] = $product % $sum;
            $given += $parts[$key];
        }

        $left = $total - $given;
        $order = $keys;
        usort($order, fn (string|int $a, string|int $b): int => $remainders[$b] <=> $remainders[$a] ?: array_search($a, $keys, true) <=> array_search($b, $keys, true));
        foreach (array_slice($order, 0, $left) as $key) {
            $parts[$key]++;
        }

        return $parts;
    }
}
