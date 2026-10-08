<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Alerts\Alert;
use App\Domain\Alerts\AlertView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GET /alerts: derived alerts with the caller's read/dismiss state (port of
 * `viewAlerts`). `data` is the visible set (dismissed ones only with
 * ?include_dismissed); counts match the frontend's AlertView.
 *
 * @property AlertView $resource
 */
final class AlertsResource extends JsonResource
{
    public function __construct(AlertView $resource, private readonly bool $includeDismissed)
    {
        parent::__construct($resource);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function toArray(Request $request): array
    {
        $view = $this->resource;
        $visible = array_flip(array_map(fn (Alert $a): string => $a->id, $view->visible));
        $unread = array_flip(array_map(fn (Alert $a): string => $a->id, $view->unread));

        $render = fn (Alert $alert): array => [
            'id' => $alert->id,
            'kind' => $alert->kind,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'body' => $alert->body,
            'vehicle_id' => $alert->vehicleId,
            'href' => $alert->href,
            'days_remaining' => $alert->daysRemaining,
            'read' => ! isset($unread[$alert->id]) && isset($visible[$alert->id]),
            'dismissed' => ! isset($visible[$alert->id]),
        ];

        return array_map($render, $this->includeDismissed ? $view->all : $view->visible);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        return ['meta' => [
            'total' => count($this->resource->all),
            'unread_count' => $this->resource->unreadCount,
            'dismissed_count' => $this->resource->dismissedCount,
        ]];
    }
}
