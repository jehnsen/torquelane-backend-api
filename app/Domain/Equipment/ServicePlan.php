<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

use App\Domain\Maintenance\DueSoonThresholds;
use App\Domain\Maintenance\IntervalEngine;
use App\Domain\Maintenance\IntervalOutcome;
use App\Domain\Maintenance\LastService;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Maintenance\MeterState;
use App\Domain\Maintenance\PlanItem;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A tool's service plan on the Phase 2 maintenance engine: the equipment
 * counterpart of App\Domain\Fleet\IntervalStatus.
 *
 * The tool is an asset with up to two meters, both DERIVED and handed in
 * (never read from `meter_readings`): runtime `hours`, and a count (`cycles`
 * for a detailing tool, `cups` for the espresso machine). Its intervals are
 * one `PlanItem`: an interval per meter and a calendar interval in days. The
 * last service anchors it. `IntervalEngine` then says which limit trips
 * FIRST, and at what reading each meter falls due.
 *
 * The engine needs each meter's average use per day. A derived meter has no
 * readings to average, so the pace is what the tool has actually done since
 * the anchor: used ÷ days since. At that pace a limit's projected date is the
 * anchor plus (days since ÷ how far along it is), so the limit furthest along
 * is the first to trip: the source's "highest ratio binds", to the day. A
 * meter that has not moved never trips by itself.
 */
final class ServicePlan
{
    public const string DAYS = 'days';

    /**
     * @param  array{hours: float, cycles: int}  $lifetime  what the derived meters read now
     * @param  array{hours: float, cycles: int}  $since  what was used since the last service
     * @param  float  $daysSince  days from the anchor to now
     * @return array{governed_by: string|null, limits: list<array{key: string, due_at: float|null, due_on_ms: int|null}>}|null null when the tool has no interval
     */
    public static function evaluate(ToolFacts $tool, array $lifetime, array $since, float $daysSince, int $anchorMs, int $nowMs): ?array
    {
        $readings = [];
        if ($tool->serviceIntervalHours !== null && $tool->serviceIntervalHours > 0) {
            $readings[MeterKind::Hours->value] = [$tool->serviceIntervalHours, $lifetime['hours'], $since['hours']];
        }
        if ($tool->serviceIntervalCycles !== null && $tool->serviceIntervalCycles > 0) {
            $readings[$tool->countKind->value] = [(float) $tool->serviceIntervalCycles, (float) $lifetime['cycles'], (float) $since['cycles']];
        }
        $days = $tool->serviceIntervalDays !== null && $tool->serviceIntervalDays > 0 ? $tool->serviceIntervalDays : null;
        if ($readings === [] && $days === null) {
            return null;
        }

        $today = self::instant($nowMs);
        $intervals = [];
        $meters = [];
        $atService = [];
        $warnAt = [];
        foreach ($readings as $kind => [$interval, $now, $used]) {
            $intervals[$kind] = $interval;
            $meters[$kind] = new MeterState(MeterKind::from($kind), $now, $today, $daysSince > 0 ? $used / $daysSince : 0);
            $atService[$kind] = $now - $used;
            $warnAt[$kind] = $interval * (1 - EquipmentEngine::DUE_SOON_RATIO);
        }

        $outcome = IntervalEngine::evaluate(
            new PlanItem($intervals, null, $days),
            new LastService(self::instant($anchorMs), $atService),
            $meters,
            new DueSoonThresholds($warnAt, 0),
            $today,
        );

        $limits = [];
        foreach ($outcome->meters as $kind => $projection) {
            $limits[] = ['key' => $kind, 'due_at' => (float) $projection->dueAt, 'due_on_ms' => null];
        }
        if ($outcome->calendarDueOn !== null) {
            $limits[] = ['key' => self::DAYS, 'due_at' => null, 'due_on_ms' => $outcome->calendarDueOn->getTimestamp() * 1000 + (int) $outcome->calendarDueOn->format('v')];
        }

        return [
            'governed_by' => $outcome->governedBy === IntervalOutcome::TIME ? self::DAYS : $outcome->governedBy,
            'limits' => $limits,
        ];
    }

    private static function instant(int $ms): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.intdiv($ms, 1000), new DateTimeZone('UTC')))->modify(sprintf('+%d milliseconds', $ms % 1000));
    }
}
