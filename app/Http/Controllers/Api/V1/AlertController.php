<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Alerts\RecordAlertInteraction;
use App\Actions\Fleet\FleetQueries;
use App\Http\Requests\AlertInteractionRequest;
use App\Http\Resources\AlertsResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Alerts are derived on every read from the caller's vehicles and documents,
 * never stored. Read/dismiss state is the caller's own, bucketed per scope.
 */
final class AlertController
{
    /**
     * List alerts
     *
     * Most severe first, then most urgent. PMS alerts only where repair_pms
     * is active. Ids are deterministic (`pms:<vehicle>:<task>`,
     * `doc:<document>`, `licence:<vehicle>`, …). `?include_dismissed=1` adds
     * dismissed ones (flagged).
     */
    public function index(Request $request, FleetQueries $fleet): AlertsResource
    {
        return new AlertsResource($fleet->alerts(), $request->boolean('include_dismissed'));
    }

    /**
     * Mark alerts read
     */
    public function read(AlertInteractionRequest $request, RecordAlertInteraction $record): Response
    {
        $record->handle('read', $request->alertIds());

        return response()->noContent();
    }

    /**
     * Dismiss alerts
     */
    public function dismiss(AlertInteractionRequest $request, RecordAlertInteraction $record): Response
    {
        $record->handle('dismiss', $request->alertIds());

        return response()->noContent();
    }

    /**
     * Restore dismissed alerts
     */
    public function restore(AlertInteractionRequest $request, RecordAlertInteraction $record): Response
    {
        $record->handle('restore', $request->alertIds());

        return response()->noContent();
    }
}
