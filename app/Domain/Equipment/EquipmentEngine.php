<?php

declare(strict_types=1);

namespace App\Domain\Equipment;

use App\Domain\Billing\Billing;
use App\Domain\Maintenance\MeterKind;
use App\Domain\Shared\JsMath;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;

/**
 * The equipment monitor: a port of the source's `equipmentUtils.js`, held to
 * a recording of it (tests/Golden/fixtures/detailing/equipment.json).
 *
 * **Nothing here reads a stored meter.** A tool's runtime is derived every
 * time: its baseline, plus the hours its package profiles put on it for each
 * COMPLETED job, plus its usage log. Back-date a booking, delete a log entry
 * or re-open a job and every figure re-settles by itself.
 *
 *  - Preventive maintenance has three limits (runtime hours, a count of job
 *    cycles or cups, calendar days), each a ratio of what was used since the
 *    last service over its interval. The furthest along sets the band: under
 *    80% is `ok`, from 80% `due_soon`, from 100% `overdue`. A tool with no
 *    interval is not tracked.
 *  - Which limit trips FIRST (`binding`), and the reading or date each falls
 *    due at, come from the Phase 2 maintenance engine through `ServicePlan`.
 *    At the tool's pace since its last service that is the limit furthest
 *    along, as in the source.
 *  - "Since the last service" counts usage strictly after `last_service_at`
 *    (never serviced = from the beginning). Days run from the last service,
 *    else the purchase date, else the day the tool was registered.
 *  - The forecast projects the binding trigger from the burn rate of the last
 *    30 days; an idle tool has none; an overdue one is due today.
 *  - Life: runtime over rated life. Under 70% healthy, under 90% aging, under
 *    100% replace soon, then end of life.
 *  - Reliability: MTBF = runtime ÷ repairs; availability = runtime ÷ (runtime
 *    + downtime); total cost of ownership = purchase + maintenance; cost per
 *    hour = that ÷ runtime.
 *  - Health: 100 less the penalties (maintenance debt 35, overdue surcharge
 *    15, life consumed 25, condition up to 25, status up to 35), rounded as
 *    JavaScript rounds and clamped to 0–100. 80 good, 60 fair, 40 poor, else
 *    critical.
 *
 * Money is whole centavos (R6); hours, ratios and days are floats, computed
 * in the source's order so the results are the source's to the last bit.
 *
 * @phpstan-type Report array{
 *     lifetime: array{hours: float, cycles: int, jobs: int},
 *     service: array{level: string, progress: float, binding: string, triggers: list<array{key: string, unit: string, used: float, interval: float, ratio: float, remaining: float, due_at: float|null, due_on_ms: int|null}>, hours_since: float, cycles_since: int, days_since: float}|null,
 *     life: array{life: float, used: float, remaining: float, ratio: float, level: string}|null,
 *     forecast: array{days: float, at_ms: int, rate: float|null}|null,
 *     reliability: array{services: int, repairs: int, downtime_hours: float, maintenance_cost_cents: int, total_cost_cents: int, mtbf_hours: float|null, availability_pct: float, cost_per_hour_cents: int|null, last_serviced_at_ms: int|null},
 *     utilization: array{window_days: int, hours: float, jobs: int, capacity_hours: int, pct: float},
 *     health: array{score: int, band: string},
 * }
 */
final class EquipmentEngine
{
    public const int DAY_MS = 86_400_000;

    /** A service is flagged due soon once it is this far into its interval. */
    public const float DUE_SOON_RATIO = 0.8;

    /** The rolling window behind the forecast's burn rate and the utilisation. */
    public const int FORECAST_WINDOW_DAYS = 30;

    /** Open shop hours a day: the utilisation's divisor. */
    public const int OPEN_HOURS_PER_DAY = 10;

    private const array CONDITION_PENALTY = ['excellent' => 0, 'good' => 5, 'fair' => 15, 'poor' => 25];

    private const array STATUS_PENALTY = ['available' => 0, 'in_use' => 0, 'maintenance' => 10, 'repair' => 20, 'retired' => 35];

    /**
     * Everything a screen shows for one tool.
     *
     * @param  list<UsageEvent>  $usage  the tool's own: jobs first, then log entries
     * @param  list<ServiceEvent>  $services  the tool's own
     * @return Report
     */
    public static function report(ToolFacts $tool, array $usage, array $services, int $nowMs): array
    {
        $lifetime = self::lifetime($tool, $usage);
        $service = self::serviceStatus($tool, $usage, $nowMs);
        $life = self::life($tool, $lifetime['hours']);

        return [
            'lifetime' => $lifetime,
            'service' => $service,
            'life' => $life,
            'forecast' => self::forecast($usage, $service, $nowMs),
            'reliability' => self::reliability($tool, $services, $lifetime['hours']),
            'utilization' => self::utilization($usage, $nowMs),
            'health' => self::health($tool, $service['progress'] ?? null, $life['ratio'] ?? null),
        ];
    }

    /**
     * Usage after `$sinceMs` (null = the whole life on record). `jobs` counts
     * completed bookings; `cycles` adds the logged cycles on top.
     *
     * @param  list<UsageEvent>  $usage
     * @return array{hours: float, jobs: int, cycles: int}
     */
    public static function usage(array $usage, ?int $sinceMs): array
    {
        $hours = 0.0;
        $jobs = 0;
        $logged = 0;
        foreach ($usage as $event) {
            if ($sinceMs !== null && $event->atMs <= $sinceMs) {
                continue;
            }
            $hours += $event->hours;
            if ($event->isJob) {
                $jobs++;
            } else {
                $logged += $event->cycles;
            }
        }

        return ['hours' => $hours, 'jobs' => $jobs, 'cycles' => $jobs + $logged];
    }

    /**
     * What the tool's hour meter would say today.
     *
     * @param  list<UsageEvent>  $usage
     * @return array{hours: float, cycles: int, jobs: int}
     */
    public static function lifetime(ToolFacts $tool, array $usage): array
    {
        $used = self::usage($usage, null);

        return ['hours' => $tool->baselineHours + $used['hours'], 'cycles' => $tool->baselineCycles + $used['cycles'], 'jobs' => $used['jobs']];
    }

    /**
     * Where the tool sits in its service interval; null when no interval is set.
     *
     * @param  list<UsageEvent>  $usage
     * @return array{level: string, progress: float, binding: string, triggers: list<array{key: string, unit: string, used: float, interval: float, ratio: float, remaining: float, due_at: float|null, due_on_ms: int|null}>, hours_since: float, cycles_since: int, days_since: float}|null
     */
    public static function serviceStatus(ToolFacts $tool, array $usage, int $nowMs): ?array
    {
        $used = self::usage($usage, $tool->lastServiceAtMs);
        $anchor = $tool->lastServiceAtMs ?? $tool->purchaseDateMs ?? $tool->createdAtMs ?? $nowMs;
        // fdiv: an exact quotient must stay a float, as it is in the source.
        $daysSince = max(0.0, fdiv($nowMs - $anchor, self::DAY_MS));

        $triggers = [];
        if ($tool->serviceIntervalHours !== null && $tool->serviceIntervalHours > 0) {
            $triggers[] = self::trigger(MeterKind::Hours->value, 'h', $used['hours'], $tool->serviceIntervalHours);
        }
        if ($tool->serviceIntervalCycles !== null && $tool->serviceIntervalCycles > 0) {
            $triggers[] = self::trigger($tool->countKind->value, $tool->countKind === MeterKind::Cups ? 'cups' : 'jobs', (float) $used['cycles'], (float) $tool->serviceIntervalCycles);
        }
        if ($tool->serviceIntervalDays !== null && $tool->serviceIntervalDays > 0) {
            $triggers[] = self::trigger(ServicePlan::DAYS, 'days', $daysSince, (float) $tool->serviceIntervalDays);
        }
        if ($triggers === []) {
            return null;
        }

        // The furthest along sets the band: any limit reached is overdue, whichever trips first.
        $furthest = $triggers[0];
        foreach ($triggers as $trigger) {
            if ($trigger['ratio'] > $furthest['ratio']) {
                $furthest = $trigger;
            }
        }
        $progress = $furthest['ratio'];

        // Which limit trips first is the maintenance engine's call. Where it
        // names none (nothing has moved and no calendar limit is set), the
        // furthest along stands, the first of equals.
        $plan = ServicePlan::evaluate($tool, self::lifetime($tool, $usage), $used, $daysSince, $anchor, $nowMs);
        $due = [];
        foreach ($plan['limits'] ?? [] as $limit) {
            $due[$limit['key']] = $limit;
        }
        foreach ($triggers as $index => $trigger) {
            $triggers[$index]['due_at'] = $due[$trigger['key']]['due_at'] ?? null;
            $triggers[$index]['due_on_ms'] = $due[$trigger['key']]['due_on_ms'] ?? null;
        }

        return [
            'level' => $progress >= 1 ? 'overdue' : ($progress >= self::DUE_SOON_RATIO ? 'due_soon' : 'ok'),
            'progress' => $progress,
            'binding' => $plan['governed_by'] ?? $furthest['key'],
            'triggers' => $triggers,
            'hours_since' => $used['hours'],
            'cycles_since' => $used['cycles'],
            'days_since' => $daysSince,
        ];
    }

    /**
     * When the next service falls due, from the recent burn rate; null for an
     * untracked tool and for one that has not run in the window.
     *
     * @param  list<UsageEvent>  $usage
     * @param  array{level: string, binding: string, triggers: list<array{key: string, remaining: float}>}|null  $service
     * @return array{days: float, at_ms: int, rate: float|null}|null
     */
    public static function forecast(array $usage, ?array $service, int $nowMs): ?array
    {
        if ($service === null) {
            return null;
        }
        if ($service['level'] === 'overdue') {
            return ['days' => 0.0, 'at_ms' => $nowMs, 'rate' => null];
        }
        $remaining = 0.0;
        foreach ($service['triggers'] as $trigger) {
            if ($trigger['key'] === $service['binding']) {
                $remaining = $trigger['remaining'];
            }
        }
        if ($service['binding'] === ServicePlan::DAYS) {
            return ['days' => $remaining, 'at_ms' => (int) ($nowMs + $remaining * self::DAY_MS), 'rate' => 1.0];
        }

        $recent = self::usage($usage, $nowMs - self::FORECAST_WINDOW_DAYS * self::DAY_MS);
        $rate = ($service['binding'] === MeterKind::Hours->value ? $recent['hours'] : (float) $recent['cycles']) / self::FORECAST_WINDOW_DAYS;
        if ($rate <= 0) {
            return null;
        }
        $days = $remaining / $rate;

        return ['days' => $days, 'at_ms' => (int) ($nowMs + $days * self::DAY_MS), 'rate' => $rate];
    }

    /**
     * How much of the tool's rated life is spent; null when no life is rated.
     *
     * @return array{life: float, used: float, remaining: float, ratio: float, level: string}|null
     */
    public static function life(ToolFacts $tool, float $lifetimeHours): ?array
    {
        $life = $tool->expectedLifeHours;
        if ($life === null || $life <= 0) {
            return null;
        }
        $ratio = $lifetimeHours / $life;

        return [
            'life' => $life,
            'used' => $lifetimeHours,
            'remaining' => $life - $lifetimeHours,
            'ratio' => $ratio,
            'level' => match (true) {
                $ratio >= 1 => 'end_of_life',
                $ratio >= 0.9 => 'replace_soon',
                $ratio >= 0.7 => 'aging',
                default => 'healthy',
            },
        ];
    }

    /**
     * @param  list<ServiceEvent>  $services
     * @return array{services: int, repairs: int, downtime_hours: float, maintenance_cost_cents: int, total_cost_cents: int, mtbf_hours: float|null, availability_pct: float, cost_per_hour_cents: int|null, last_serviced_at_ms: int|null}
     */
    public static function reliability(ToolFacts $tool, array $services, float $runtimeHours): array
    {
        $repairs = 0;
        $downtime = 0.0;
        $cost = 0;
        $latest = null;
        foreach ($services as $event) {
            $repairs += $event->kind === ServiceEvent::REPAIR ? 1 : 0;
            $downtime += $event->downtimeHours;
            $cost += $event->costCents;
            if ($latest === null || $event->performedAtMs > $latest) {
                $latest = $event->performedAtMs;
            }
        }
        $total = $tool->purchaseCostCents + $cost;

        return [
            'services' => count($services),
            'repairs' => $repairs,
            'downtime_hours' => $downtime,
            'maintenance_cost_cents' => $cost,
            'total_cost_cents' => $total,
            'mtbf_hours' => $repairs > 0 ? $runtimeHours / $repairs : null,
            'availability_pct' => $runtimeHours + $downtime > 0 ? ($runtimeHours / ($runtimeHours + $downtime)) * 100 : 100.0,
            // Money divided by a measure: exact, rounded once to a centavo.
            'cost_per_hour_cents' => $runtimeHours > 0
                ? Billing::roundCents(BigRational::of($total)->dividedBy(BigDecimal::of(sprintf('%.6F', $runtimeHours))))
                : null,
            'last_serviced_at_ms' => $latest,
        ];
    }

    /**
     * The share of open shop hours the tool ran in the window.
     *
     * @param  list<UsageEvent>  $usage
     * @return array{window_days: int, hours: float, jobs: int, capacity_hours: int, pct: float}
     */
    public static function utilization(array $usage, int $nowMs): array
    {
        $recent = self::usage($usage, $nowMs - self::FORECAST_WINDOW_DAYS * self::DAY_MS);
        $capacity = self::FORECAST_WINDOW_DAYS * self::OPEN_HOURS_PER_DAY;

        return [
            'window_days' => self::FORECAST_WINDOW_DAYS,
            'hours' => $recent['hours'],
            'jobs' => $recent['jobs'],
            'capacity_hours' => $capacity,
            'pct' => ($recent['hours'] / $capacity) * 100,
        ];
    }

    /**
     * One 0–100 number to sort the floor by.
     *
     * @return array{score: int, band: string}
     */
    public static function health(ToolFacts $tool, ?float $serviceProgress, ?float $lifeRatio): array
    {
        $pm = $serviceProgress ?? 0.0;
        $penalty = 35 * self::clamp($pm, 0.0, 1.0)
            + 15 * self::clamp($pm - 1, 0.0, 1.0)
            + 25 * ($lifeRatio === null ? 0.0 : self::clamp($lifeRatio, 0.0, 1.0))
            + (self::CONDITION_PENALTY[$tool->conditionRating] ?? 5)
            + (self::STATUS_PENALTY[$tool->status] ?? 0);

        $score = (int) self::clamp((float) JsMath::round(100 - $penalty), 0.0, 100.0);

        return [
            'score' => $score,
            'band' => match (true) {
                $score >= 80 => 'good',
                $score >= 60 => 'fair',
                $score >= 40 => 'poor',
                default => 'critical',
            },
        ];
    }

    /**
     * The floor in numbers, for the board's tiles.
     *
     * @param  list<array{tool: ToolFacts, service_level: string|null, life_level: string|null, maintenance_cost_cents: int, health_score: int}>  $rows
     * @return array{total: int, active: int, overdue: int, due_soon: int, down: int, replace_watch: int, untracked: int, asset_value_cents: int, maintenance_spend_cents: int, avg_health: int|null}
     */
    public static function summarize(array $rows): array
    {
        $summary = ['total' => count($rows), 'active' => 0, 'overdue' => 0, 'due_soon' => 0, 'down' => 0, 'replace_watch' => 0, 'untracked' => 0, 'asset_value_cents' => 0, 'maintenance_spend_cents' => 0, 'avg_health' => null];
        $scored = 0;
        $health = 0;
        foreach ($rows as $row) {
            $live = $row['tool']->status !== 'retired';
            $summary['active'] += $live ? 1 : 0;
            $summary['overdue'] += $row['service_level'] === 'overdue' ? 1 : 0;
            $summary['due_soon'] += $row['service_level'] === 'due_soon' ? 1 : 0;
            $summary['down'] += in_array($row['tool']->status, ['repair', 'maintenance'], true) ? 1 : 0;
            $summary['replace_watch'] += in_array($row['life_level'], ['replace_soon', 'end_of_life'], true) ? 1 : 0;
            $summary['untracked'] += $row['service_level'] === null ? 1 : 0;
            $summary['asset_value_cents'] += $row['tool']->purchaseCostCents;
            $summary['maintenance_spend_cents'] += $row['maintenance_cost_cents'];
            if ($live && $row['tool']->isActive) {
                $scored++;
                $health += $row['health_score'];
            }
        }
        $summary['avg_health'] = $scored > 0 ? (int) JsMath::round($health / $scored) : null;

        return $summary;
    }

    /**
     * @return array{key: string, unit: string, used: float, interval: float, ratio: float, remaining: float, due_at: float|null, due_on_ms: int|null}
     */
    private static function trigger(string $key, string $unit, float $used, float $interval): array
    {
        return ['key' => $key, 'unit' => $unit, 'used' => $used, 'interval' => $interval, 'ratio' => $used / $interval, 'remaining' => $interval - $used, 'due_at' => null, 'due_on_ms' => null];
    }

    private static function clamp(float $value, float $low, float $high): float
    {
        return min($high, max($low, $value));
    }
}
