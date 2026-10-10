<?php

declare(strict_types=1);

namespace App\Actions\Equipment;

use App\Domain\Alerts\EquipmentAlertFacts;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Equipment\EquipmentEngine;
use App\Domain\Equipment\ServiceEvent;
use App\Domain\Equipment\ToolFacts;
use App\Domain\Equipment\UsageEvent;
use App\Domain\Maintenance\MeterKind;
use App\Models\Branch;
use App\Models\DetailingBooking;
use App\Models\Equipment;
use App\Models\EquipmentMaintenanceLog;
use App\Models\EquipmentPackageProfile;
use App\Models\EquipmentUsageLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * The equipment monitor's reads. Every figure a screen shows is worked out
 * here, from what is on record at this moment: nothing is stored that could
 * drift. A branch's whole floor is evaluated together (its completed jobs are
 * read once and matched to each tool through its package profiles).
 *
 * @phpstan-import-type Report from EquipmentEngine
 */
final class EquipmentQueries
{
    /**
     * The branch's tools, each with its report, and the floor in numbers.
     *
     * @return array{tools: Collection<int, Equipment>, reports: array<string, Report>, summary: array<string, int|null>}
     */
    public function board(Branch $branch): array
    {
        $tools = Equipment::query()->where('branch_id', $branch->id)->with('bay')->orderBy('position')->orderBy('name')->get();
        $reports = $this->reports($branch, $tools);

        $rows = [];
        foreach ($tools as $tool) {
            $report = $reports[$tool->id];
            $rows[] = [
                'tool' => self::facts($tool),
                'service_level' => $report['service']['level'] ?? null,
                'life_level' => $report['life']['level'] ?? null,
                'maintenance_cost_cents' => $report['reliability']['maintenance_cost_cents'],
                'health_score' => $report['health']['score'],
            ];
        }

        return ['tools' => $tools, 'reports' => $reports, 'summary' => EquipmentEngine::summarize($rows)];
    }

    /**
     * One tool's report (the same figures the board shows for it).
     *
     * @return Report
     */
    public function report(Equipment $tool): array
    {
        $branch = Branch::query()->findOrFail($tool->branch_id);

        return $this->reports($branch, new Collection([$tool]))[$tool->id];
    }

    /**
     * What each tool's derived meters read right now: stamped on a service log when it is recorded.
     *
     * @return array{hours: float, cycles: int, jobs: int}
     */
    public function lifetime(Equipment $tool): array
    {
        return $this->report($tool)['lifetime'];
    }

    /**
     * Each package of the branch with the tools mapped to it.
     *
     * @return Collection<int, EquipmentPackageProfile>
     */
    public function profiles(Branch $branch): Collection
    {
        return EquipmentPackageProfile::query()->where('branch_id', $branch->id)->with(['equipment', 'package'])->orderBy('package_id')->orderBy('id')->get();
    }

    /**
     * What the alert rules read: every tool in service of the given branches,
     * with where it sits in its plan and its rated life. A retired or inactive
     * tool raises nothing.
     *
     * @param  list<string>  $branchIds
     * @return list<EquipmentAlertFacts>
     */
    public function alertFacts(array $branchIds): array
    {
        $facts = [];
        foreach (Branch::query()->whereIn('id', $branchIds)->orderBy('id')->get() as $branch) {
            $tools = Equipment::query()->where('branch_id', $branch->id)->where('is_active', true)->where('status', '!=', 'retired')->orderBy('position')->orderBy('name')->get();
            $reports = $this->reports($branch, $tools);
            foreach ($tools as $tool) {
                $report = $reports[$tool->id];
                $facts[] = new EquipmentAlertFacts(
                    $tool->id,
                    $tool->name,
                    $tool->maintenance_task,
                    $report['service']['level'] ?? null,
                    $report['service']['progress'] ?? 0.0,
                    $report['service']['binding'] ?? null,
                    $report['forecast']['days'] ?? null,
                    $report['life']['level'] ?? null,
                    $report['life']['used'] ?? 0.0,
                    $report['life']['life'] ?? 0.0,
                );
            }
        }

        return $facts;
    }

    /**
     * @param  Collection<int, Equipment>  $tools
     * @return array<string, Report>
     */
    private function reports(Branch $branch, Collection $tools): array
    {
        $ids = array_values($tools->map(fn (Equipment $tool): string => $tool->id)->all());
        $now = CarbonImmutable::now()->getTimestampMs();

        /** @var array<string, array<string, float>> $hoursPerJob  tool id → package id → hours */
        $hoursPerJob = [];
        foreach (EquipmentPackageProfile::query()->whereIn('equipment_id', $ids)->get() as $profile) {
            $hoursPerJob[$profile->equipment_id][$profile->package_id] = $profile->hours_per_job->toFloat();
        }

        // Every completed job of the branch, oldest first: the booking sheet is the meter.
        $jobs = $hoursPerJob === [] ? [] : DetailingBooking::query()
            ->where('branch_id', $branch->id)
            ->where('status', BookingStatus::Completed)
            ->whereNotNull('completed_at')
            ->orderBy('completed_at')->orderBy('id')
            ->get(['id', 'package_id', 'completed_at'])
            ->map(fn (DetailingBooking $booking): array => ['package_id' => $booking->package_id, 'at_ms' => $booking->completed_at?->getTimestampMs() ?? 0])
            ->all();

        $usage = [];
        foreach (EquipmentUsageLog::query()->whereIn('equipment_id', $ids)->orderBy('used_at')->orderBy('id')->get() as $log) {
            $usage[$log->equipment_id][] = UsageEvent::logged($log->used_at->getTimestampMs(), $log->hours->toFloat(), $log->cycles);
        }
        $services = [];
        foreach (EquipmentMaintenanceLog::query()->whereIn('equipment_id', $ids)->orderBy('performed_at')->orderBy('id')->get() as $log) {
            $services[$log->equipment_id][] = new ServiceEvent($log->kind, $log->totalCostCents(), $log->downtime_hours->toFloat(), $log->performed_at->getTimestampMs());
        }

        $reports = [];
        foreach ($tools as $tool) {
            $events = [];
            foreach ($jobs as $job) {
                $hours = $hoursPerJob[$tool->id][$job['package_id']] ?? null;
                if ($hours !== null) {
                    $events[] = UsageEvent::job($job['at_ms'], $hours);
                }
            }
            array_push($events, ...($usage[$tool->id] ?? []));
            $reports[$tool->id] = EquipmentEngine::report(self::facts($tool), $events, $services[$tool->id] ?? [], $now);
        }

        return $reports;
    }

    public static function facts(Equipment $tool): ToolFacts
    {
        return new ToolFacts(
            $tool->id,
            $tool->status,
            $tool->condition_rating,
            $tool->is_active,
            $tool->purchase_cost_cents,
            $tool->baseline_hours->toFloat(),
            $tool->baseline_cycles,
            $tool->service_interval_hours?->toFloat(),
            $tool->service_interval_cycles,
            $tool->service_interval_days,
            $tool->last_service_at?->getTimestampMs(),
            // A date with no time: that day's UTC midnight, as the source read it.
            $tool->purchase_date === null ? null : CarbonImmutable::parse($tool->purchase_date->toDateString().'T00:00:00Z')->getTimestampMs(),
            $tool->created_at->getTimestampMs(),
            $tool->expected_life_hours?->toFloat(),
            $tool->meter_type === MeterKind::Cups->value ? MeterKind::Cups : MeterKind::Cycles,
        );
    }
}
