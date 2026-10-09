<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use Brick\Math\BigDecimal;

/**
 * Low-stock alerts, derived on read and never stored. An alert's id is its
 * identity (`stock:<item>:<location>`), like every other alert's.
 */
final class StockAlerts
{
    public const string CRITICAL = 'critical';

    public const string WARNING = 'warning';

    /**
     * @param  list<array{item_id: string, location_id: string, on_hand: string, reorder_point: string|null}>  $positions
     * @return list<array{id: string, item_id: string, location_id: string, severity: string, on_hand: string, reorder_point: string}>
     */
    public static function derive(array $positions): array
    {
        $alerts = [];
        foreach ($positions as $position) {
            if ($position['reorder_point'] === null) {
                continue;
            }
            $onHand = BigDecimal::of($position['on_hand']);
            if ($onHand->isGreaterThan($position['reorder_point'])) {
                continue;
            }
            $alerts[] = [
                'id' => 'stock:'.$position['item_id'].':'.$position['location_id'],
                'item_id' => $position['item_id'],
                'location_id' => $position['location_id'],
                'severity' => $onHand->isPositive() ? self::WARNING : self::CRITICAL,
                'on_hand' => (string) $onHand,
                'reorder_point' => (string) BigDecimal::of($position['reorder_point']),
            ];
        }

        usort($alerts, fn (array $a, array $b): int => [$a['severity'] === self::CRITICAL ? 0 : 1, $a['id']] <=> [$b['severity'] === self::CRITICAL ? 0 : 1, $b['id']]);

        return $alerts;
    }
}
