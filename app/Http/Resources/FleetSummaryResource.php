<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fleet\FleetSummary;
use App\Domain\Fleet\FleetThresholds;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /fleet/summary: the dashboard KPIs (port of `summariseFleet`), the
 * expiring-documents tile (`expiringDocumentSummary`, 60-day window) and the
 * thresholds behind them.
 *
 * @property FleetSummary $resource
 */
final class FleetSummaryResource extends JsonResource
{
    /**
     * @param  array{total: int, byKind: list<array{label: string, count: int}>}  $expiring
     * @param  array{expired: int, expiring: int, ok: int}  $compliance
     */
    public function __construct(FleetSummary $resource, private readonly array $expiring, private readonly array $compliance, private readonly string $evaluatedOn)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $s = $this->resource;

        return [
            'evaluated_on' => $this->evaluatedOn,
            'total' => $s->total,
            'compliant' => $s->compliant,
            'due_soon' => $s->dueSoon,
            'overdue' => $s->overdue,
            'in_service' => $s->inService,
            'down' => $s->down,
            'compliance_rate' => $s->complianceRate,
            'avg_health_score' => $s->avgHealthScore,
            'total_odometer' => $s->totalOdometer,
            'documents' => $this->compliance,
            'expiring_documents' => [
                'window_days' => FleetThresholds::DASHBOARD_EXPIRY_WINDOW_DAYS,
                'total' => $this->expiring['total'],
                'by_kind' => $this->expiring['byKind'],
            ],
            'thresholds' => FleetThresholds::all(),
        ];
    }
}
