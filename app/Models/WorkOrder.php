<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Tenancy\TenantContext;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Written only by App\Actions\WorkOrders (one transaction each, order row
 * locked, events + audit in the same transaction).
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property string|null $assigned_branch_id
 * @property string $customer_account_id
 * @property string $vehicle_id
 * @property string $reference
 * @property string $title
 * @property string $type
 * @property WorkOrderStatus $status
 * @property string $priority
 * @property CarbonImmutable $opened_on
 * @property CarbonImmutable|null $scheduled_for
 * @property string|null $scheduled_time
 * @property string|null $bay_id
 * @property string|null $technician_id
 * @property string|null $technician_name
 * @property string $vendor
 * @property BigDecimal|null $odometer_at_intake
 * @property BigDecimal|null $odometer_at_service
 * @property int $labor_cost_cents
 * @property int $parts_cost_cents
 * @property string $findings
 * @property string $notes
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $pending_approval_entered_at
 * @property BigDecimal|null $approval_wait_hours
 * @property CarbonImmutable|null $completed_on
 * @property CarbonImmutable|null $collected_at
 * @property string|null $collected_by
 * @property CarbonImmutable|null $released_at
 * @property string|null $released_by
 * @property string|null $created_by
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Collection<int, WorkOrderLine> $lines
 * @property-read Collection<int, WorkOrderTask> $tasks
 * @property-read Collection<int, WorkOrderPart> $parts
 * @property-read Collection<int, WorkOrderEvent> $events
 * @property-read Collection<int, ApprovalLogEntry> $approvalLog
 * @property-read Vehicle $vehicle
 * @property-read CustomerAccount $customerAccount
 * @property-read Collection<int, InvoiceWorkOrder> $invoiceLinks
 */
final class WorkOrder extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = [
        'reference' => '',
        'status' => 'draft',
        'priority' => 'medium',
        'vendor' => '',
        'labor_cost_cents' => 0,
        'parts_cost_cents' => 0,
        'findings' => '',
        'notes' => '',
    ];

    protected function casts(): array
    {
        return [
            'status' => WorkOrderStatus::class,
            'opened_on' => 'immutable_date',
            'scheduled_for' => 'immutable_date',
            'odometer_at_intake' => DecimalCast::class,
            'odometer_at_service' => DecimalCast::class,
            'labor_cost_cents' => 'integer',
            'parts_cost_cents' => 'integer',
            'pending_approval_entered_at' => 'immutable_datetime',
            'approval_wait_hours' => DecimalCast::class,
            'completed_on' => 'immutable_date',
            'collected_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * Portal: their account's orders. Staff: every order in the organization
     * except those booked into a branch they are not allowed (a portal request
     * not yet taken into a branch is visible to every staff member).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $accountId = $context->customerAccountId();
        if ($accountId !== null) {
            $query->where($this->qualifyColumn('customer_account_id'), $accountId);

            return;
        }
        $branches = $context->branchFilter();
        $query->where(fn (Builder $q) => $q->whereNull($this->qualifyColumn('branch_id'))->orWhereIn($this->qualifyColumn('branch_id'), $branches));
    }

    /**
     * @return HasMany<WorkOrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(WorkOrderLine::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<WorkOrderTask, $this>
     */
    public function tasks(): HasMany
    {
        return $this->hasMany(WorkOrderTask::class)->orderBy('id');
    }

    /**
     * @return HasMany<WorkOrderPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class)->orderBy('position')->orderBy('id');
    }

    /**
     * @return HasMany<WorkOrderEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(WorkOrderEvent::class)->orderBy('at')->orderBy('id');
    }

    /**
     * @return HasMany<ApprovalLogEntry, $this>
     */
    public function approvalLog(): HasMany
    {
        return $this->hasMany(ApprovalLogEntry::class)->orderBy('at')->orderBy('id');
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<CustomerAccount, $this>
     */
    public function customerAccount(): BelongsTo
    {
        return $this->belongsTo(CustomerAccount::class);
    }

    /**
     * The invoices that have carried this order (Phase 7); at most one stands
     * (`released_at` null), the rest were voided.
     *
     * @return HasMany<InvoiceWorkOrder, $this>
     */
    public function invoiceLinks(): HasMany
    {
        return $this->hasMany(InvoiceWorkOrder::class)->orderBy('created_at')->orderBy('id');
    }

    /** The standing invoice link, if any (relation loaded). */
    public function standingInvoiceLink(): ?InvoiceWorkOrder
    {
        return $this->invoiceLinks->first(fn (InvoiceWorkOrder $link): bool => $link->released_at === null);
    }
}
