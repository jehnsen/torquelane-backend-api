<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Equipment\ToolFacts;
use App\Domain\Equipment\UsageEvent;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The recording of the source's equipment engine
 * (tests/Golden/fixtures/detailing/equipment.json), and how its rows become
 * what the port reads. Shared by the suites that replay it.
 */
final class EquipmentRecording
{
    /** @var list<array{name: string, now: string, equipment: list<array<string, mixed>>, ctx: array<string, list<array<string, mixed>>>, expect: array{reports: list<array<string, mixed>>, summary: array<string, mixed>}}>|null */
    private static ?array $scenarios = null;

    /**
     * @return list<array{name: string, now: string, equipment: list<array<string, mixed>>, ctx: array<string, list<array<string, mixed>>>, expect: array{reports: list<array<string, mixed>>, summary: array<string, mixed>}}>
     */
    public static function scenarios(): array
    {
        return self::$scenarios ??= json_decode((string) file_get_contents(__DIR__.'/../Golden/fixtures/detailing/equipment.json'), true, flags: JSON_THROW_ON_ERROR)['scenarios'];
    }

    public static function ms(?string $instant): ?int
    {
        if ($instant === null) {
            return null;
        }
        $at = new DateTimeImmutable($instant, new DateTimeZone('UTC'));

        return $at->getTimestamp() * 1000 + (int) $at->format('v');
    }

    public static function iso(?int $ms): ?string
    {
        return $ms === null ? null : gmdate('Y-m-d\TH:i:s', intdiv($ms, 1000)).sprintf('.%03dZ', $ms % 1000);
    }

    /** @param array<string, mixed> $tool */
    public static function facts(array $tool): ToolFacts
    {
        return new ToolFacts(
            $tool['id'],
            $tool['status'],
            $tool['conditionRating'],
            $tool['isActive'],
            $tool['purchaseCostCents'],
            (float) $tool['baselineHours'],
            $tool['baselineCycles'],
            $tool['serviceIntervalHours'] === null ? null : (float) $tool['serviceIntervalHours'],
            $tool['serviceIntervalCycles'],
            $tool['serviceIntervalDays'],
            self::ms($tool['lastServiceAt']),
            self::ms($tool['purchaseDate']),
            self::ms($tool['createdAt']),
            $tool['expectedLifeHours'] === null ? null : (float) $tool['expectedLifeHours'],
        );
    }

    /**
     * The tool's usage as the source walked it: every completed booking of a
     * package the tool is mapped to, then its own log entries.
     *
     * @param  array<string, list<array<string, mixed>>>  $ctx
     * @return list<UsageEvent>
     */
    public static function usage(string $toolId, array $ctx): array
    {
        $profiles = [];
        foreach ($ctx['serviceEquipment'] as $row) {
            if ($row['equipmentId'] === $toolId) {
                $profiles[$row['serviceId']] ??= (float) $row['hoursPerJob'];
            }
        }
        $events = [];
        foreach ($ctx['bookings'] as $booking) {
            if ($booking['status'] === 'completed' && $booking['completedAt'] !== null && isset($profiles[$booking['serviceId']])) {
                $events[] = UsageEvent::job((int) self::ms($booking['completedAt']), $profiles[$booking['serviceId']]);
            }
        }
        foreach ($ctx['usageLogs'] as $log) {
            if ($log['equipmentId'] === $toolId) {
                $events[] = UsageEvent::logged((int) self::ms($log['usedAt']), (float) $log['hours'], (int) $log['cycles']);
            }
        }

        return $events;
    }
}
