<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Domain\Approvals\LineApprovalStatus;
use App\Domain\WorkOrders\LineUrgency;
use App\Domain\WorkOrders\PartsSource;
use Illuminate\Validation\Rule;

/**
 * Validation shared by the work-order requests. Lines carry quantities and
 * rates only: any cost or total a client sends is not validated and never
 * read — the server prices every line (Billing::recalc).
 */
final class WorkOrderRules
{
    public const string TIME = '/^([01]\d|2[0-3]):[0-5]\d$/';

    public const array TYPES = ['preventive', 'corrective', 'inspection'];

    public const array PRIORITIES = ['low', 'medium', 'high', 'critical'];

    public const array CATEGORIES = ['engine', 'drivetrain', 'brakes', 'tires', 'electrical', 'safety', 'body', 'other'];

    /**
     * @return array<string, mixed>
     */
    public static function header(string $presence): array
    {
        return [
            'title' => [$presence, 'string', 'max:255'],
            'type' => [$presence, 'string', Rule::in(self::TYPES)],
            'priority' => ['sometimes', 'string', Rule::in(self::PRIORITIES)],
            'notes' => ['sometimes', 'string', 'max:5000'],
            'vendor' => ['sometimes', 'string', 'max:255'],
            'scheduled_for' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'scheduled_time' => ['sometimes', 'nullable', 'string', 'regex:'.self::TIME],
            'odometer_at_intake' => ['sometimes', 'nullable', 'numeric', 'decimal:0,3', 'min:0', 'max:99999999'],
            'task_ids' => ['sometimes', 'array', 'max:50'],
            'task_ids.*' => ['string', 'ulid', 'distinct'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function lines(string $prefix = 'lines'): array
    {
        return [
            "{$prefix}.*.id" => ['sometimes', 'string', 'ulid', 'distinct'],
            "{$prefix}.*.service_task_id" => ['sometimes', 'nullable', 'string', 'ulid'],
            "{$prefix}.*.description" => ["required_without:{$prefix}.*.service_task_id", 'string', 'max:2000'],
            "{$prefix}.*.category" => ['sometimes', 'string', Rule::in(self::CATEGORIES)],
            "{$prefix}.*.quantity" => ['sometimes', 'numeric', 'decimal:0,3', 'min:0', 'max:99999'],
            "{$prefix}.*.unit_part_rate_cents" => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            "{$prefix}.*.labour_hours" => ['sometimes', 'numeric', 'decimal:0,3', 'min:0', 'max:9999'],
            "{$prefix}.*.labour_rate_cents" => ['sometimes', 'integer', 'min:0', 'max:100000000000'],
            "{$prefix}.*.urgency" => ['sometimes', 'string', Rule::enum(LineUrgency::class)],
            "{$prefix}.*.parts_source" => ['sometimes', 'string', Rule::enum(PartsSource::class)],
            "{$prefix}.*.photos" => ['sometimes', 'array', 'max:20'],
            "{$prefix}.*.photos.*" => ['string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function decisions(): array
    {
        return [LineApprovalStatus::Approved->value, LineApprovalStatus::Declined->value, LineApprovalStatus::Deferred->value];
    }
}
