<?php

declare(strict_types=1);

namespace App\Domain\Crm;

/**
 * Net Promoter Score over 0–10 answers: promoters (9, 10) less detractors
 * (0–6), as a whole percentage of everyone who answered.
 */
final class Nps
{
    public static function band(int $score): string
    {
        return match (true) {
            $score >= 9 => 'promoter',
            $score >= 7 => 'passive',
            default => 'detractor',
        };
    }

    /**
     * @param  list<int>  $scores
     * @return array{responses: int, promoters: int, passives: int, detractors: int, nps: int|null}
     */
    public static function summarize(array $scores): array
    {
        $counts = ['promoter' => 0, 'passive' => 0, 'detractor' => 0];
        foreach ($scores as $score) {
            $counts[self::band($score)]++;
        }
        $total = count($scores);

        return [
            'responses' => $total,
            'promoters' => $counts['promoter'],
            'passives' => $counts['passive'],
            'detractors' => $counts['detractor'],
            'nps' => $total === 0 ? null : (int) round(($counts['promoter'] - $counts['detractor']) * 100 / $total),
        ];
    }
}
