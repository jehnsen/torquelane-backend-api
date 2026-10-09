<?php

declare(strict_types=1);

namespace App\Domain\Receivables;

/**
 * A statement of account over [from, to] (business dates): the balance
 * brought forward from everything before `from`, then each movement in the
 * range with the running balance, and the closing balance. A positive
 * balance is owed by the customer; a negative one is credit in their favour.
 */
final class Statement
{
    /**
     * @param  list<StatementEntry>  $entries  any order
     * @return array{opening_balance_cents: int, entries: list<array{entry: StatementEntry, balance_cents: int}>, total_charges_cents: int, total_credits_cents: int, closing_balance_cents: int}
     */
    public static function build(array $entries, string $from, string $to): array
    {
        usort($entries, fn (StatementEntry $a, StatementEntry $b): int => [$a->date, $a->sortKey] <=> [$b->date, $b->sortKey]);

        $opening = 0;
        $rows = [];
        $charges = 0;
        $credits = 0;
        $balance = 0;
        foreach ($entries as $entry) {
            if ($entry->date < $from) {
                $opening += $entry->netCents();
                $balance = $opening;

                continue;
            }
            if ($entry->date > $to) {
                continue;
            }
            $balance += $entry->netCents();
            $charges += $entry->chargeCents;
            $credits += $entry->creditCents;
            $rows[] = ['entry' => $entry, 'balance_cents' => $balance];
        }

        return [
            'opening_balance_cents' => $opening,
            'entries' => $rows,
            'total_charges_cents' => $charges,
            'total_credits_cents' => $credits,
            'closing_balance_cents' => $opening + $charges - $credits,
        ];
    }
}
