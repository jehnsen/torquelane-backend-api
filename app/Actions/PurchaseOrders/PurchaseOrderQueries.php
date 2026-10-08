<?php

declare(strict_types=1);

namespace App\Actions\PurchaseOrders;

use App\Domain\PurchaseOrders\ExportOrder;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * The read side of purchase orders: portal sessions see their own
 * account's, staff every account's. Newest first, as ../web lists them.
 */
final class PurchaseOrderQueries
{
    /** Exports stop here; filter to narrow. */
    public const int EXPORT_LIMIT = 5000;

    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @param  array{customer_account_id?: string, status?: string}  $filters
     * @return Builder<PurchaseOrder>
     */
    public function orders(array $filters): Builder
    {
        $query = PurchaseOrder::query()->visibleTo($this->tenancy->require());
        foreach (['customer_account_id', 'status'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query->orderByDesc('created_on')->orderByDesc('id');
    }

    /**
     * @param  array{customer_account_id?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, PurchaseOrder>
     */
    public function page(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->orders($filters)->with(PurchaseOrderJournal::RELATIONS)->paginate($perPage);
    }

    public function view(PurchaseOrder $order): PurchaseOrder
    {
        return $order->load(PurchaseOrderJournal::RELATIONS);
    }

    /**
     * @param  array{customer_account_id?: string, status?: string}  $filters
     * @return list<ExportOrder>
     */
    public function export(array $filters): array
    {
        return array_values($this->orders($filters)->with('lines')->limit(self::EXPORT_LIMIT)->get()->map(self::exportOrder(...))->all());
    }

    public static function exportOrder(PurchaseOrder $order): ExportOrder
    {
        return new ExportOrder(
            $order->reference,
            $order->vendor,
            $order->status,
            $order->created_on->toDateString(),
            $order->created_by_name,
            array_values($order->lines->map(fn (PurchaseOrderLine $line): array => [
                'description' => $line->description,
                'quantity' => $line->quantity,
                'unit_cost_cents' => $line->unit_cost_cents,
            ])->all()),
        );
    }
}
