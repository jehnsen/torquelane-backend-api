<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Parts\PartsQueries;
use App\Domain\Inventory\Decimals;
use App\Domain\Inventory\ReorderAdvice;
use App\Domain\Inventory\ReorderPlanner;
use App\Domain\Inventory\ShopOrderStatus;
use App\Domain\Inventory\StockAlerts;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockSource;
use App\Domain\Inventory\StoredOrderStatus;
use App\Domain\Shared\BusinessCalendar;
use App\Domain\Shared\Calendar;
use App\Models\CustomerAccount;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\ItemBranchSetting;
use App\Models\ShopPurchaseOrder;
use App\Models\ShopPurchaseOrderLine;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\StockTransfer;
use App\Models\WorkOrder;
use App\Models\WorkOrderLine;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\LengthAwarePaginator as Page;

/**
 * The read side of the shop's inventory. Staff only: every query starts from
 * the organization scope and the branches the caller may see (the selected
 * branch, else all they are allowed), never from an id the caller names
 * without being checked against those.
 */
final class InventoryQueries
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PartsQueries $parts,
        private readonly ShopOrderJournal $orders,
    ) {}

    /**
     * The branches a read covers: the one asked for (which must be allowed;
     * anything else looks like a missing record), else the selected branch,
     * else every branch the caller may see.
     *
     * @return list<string>
     */
    public function branches(?string $requested = null): array
    {
        $context = $this->tenancy->require();
        if ($requested !== null) {
            if (! $context->branchAllowed($requested)) {
                throw new ModelNotFoundException;
            }

            return [$requested];
        }

        return $context->branchFilter();
    }

    // ----------------------------------------------------------------- items

    /**
     * @param  array{q?: string, item_type?: string, category?: string, is_stocked?: bool, include_inactive?: bool}  $filters
     * @return LengthAwarePaginator<int, Item>
     */
    public function items(array $filters, int $perPage): LengthAwarePaginator
    {
        $context = $this->tenancy->require();
        $query = $this->itemQuery();

        if (isset($filters['q']) && $filters['q'] !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn (Builder $any) => $any->whereLike('sku', $like)->orWhereLike('name', $like)->orWhereLike('barcode', $like));
        }
        foreach (['item_type', 'category'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['is_stocked'])) {
            $query->where('is_stocked', $filters['is_stocked']);
        }
        if (! ($filters['include_inactive'] ?? false)) {
            $query->where('is_active', true);
        }

        return $query->visibleTo($context)->orderByRaw('lower(sku)')->orderBy('id')->paginate($perPage);
    }

    /**
     * @return Builder<Item>
     */
    public function itemQuery(): Builder
    {
        $context = $this->tenancy->require();

        return Item::query()->with([
            'branchSettings' => fn ($settings) => $settings->visibleTo($context),
            'balances' => fn ($balances) => $balances->visibleTo($context),
            'preferredVendor',
        ]);
    }

    public function freshItem(Item $item): Item
    {
        return $this->itemQuery()->findOrFail($item->id);
    }

    // -------------------------------------------------------------- on hand

    /**
     * @param  array{branch_id?: string, location_id?: string, q?: string, item_type?: string, hide_zero?: bool, low?: bool}  $filters
     * @return Builder<StockBalance>
     */
    private function balanceQuery(array $filters): Builder
    {
        $context = $this->tenancy->require();
        $query = StockBalance::query()
            ->visibleTo($context)
            ->whereIn('stock_balances.branch_id', $this->branches($filters['branch_id'] ?? null))
            ->join('items', 'items.id', '=', 'stock_balances.item_id')
            ->leftJoin('item_branch_settings as ibs', fn (JoinClause $join) => $join
                ->on('ibs.item_id', '=', 'stock_balances.item_id')
                ->on('ibs.branch_id', '=', 'stock_balances.branch_id'));

        if (isset($filters['location_id'])) {
            $query->where('stock_balances.location_id', $filters['location_id']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($any) => $any->whereLike('items.sku', $like)->orWhereLike('items.name', $like)->orWhereLike('items.barcode', $like));
        }
        if (isset($filters['item_type'])) {
            $query->where('items.item_type', $filters['item_type']);
        }
        if ($filters['hide_zero'] ?? false) {
            $query->where('stock_balances.on_hand', '<>', 0);
        }
        if ($filters['low'] ?? false) {
            $query->whereNotNull('ibs.reorder_point')->whereColumn('stock_balances.on_hand', '<=', 'ibs.reorder_point');
        }

        return $query;
    }

    /**
     * Balances with their item, location and branch settings (reorder point,
     * bin), by SKU.
     *
     * @param  array{branch_id?: string, location_id?: string, q?: string, item_type?: string, hide_zero?: bool, low?: bool}  $filters
     * @return LengthAwarePaginator<int, StockBalance>
     */
    public function onHand(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->balanceQuery($filters)
            ->select('stock_balances.*', 'ibs.reorder_point as reorder_point', 'ibs.reorder_qty as reorder_qty', 'ibs.bin as bin')
            ->with(['item', 'location'])
            ->orderByRaw('lower(items.sku)')
            ->orderBy('stock_balances.id')
            ->paginate($perPage);
    }

    /**
     * What the filtered balances add up to: the value is summed exactly and
     * rounded once (R6).
     *
     * @param  array{branch_id?: string, location_id?: string, q?: string, item_type?: string, hide_zero?: bool, low?: bool}  $filters
     * @return array{lines: int, value_cents: int, low: int, negative: int}
     */
    public function onHandSummary(array $filters): array
    {
        $row = $this->balanceQuery($filters)->toBase()->selectRaw(
            'count(*) as lines,'
            .' coalesce(sum(stock_balances.on_hand * stock_balances.avg_cost_cents), 0) as exact_value,'
            .' count(*) filter (where ibs.reorder_point is not null and stock_balances.on_hand <= ibs.reorder_point) as low,'
            .' count(*) filter (where stock_balances.on_hand < 0) as negative'
        )->first();

        return [
            'lines' => Decimals::int($row->lines ?? null),
            'value_cents' => StockLedger::roundCents(Decimals::of($row->exact_value ?? null)),
            'low' => Decimals::int($row->low ?? null),
            'negative' => Decimals::int($row->negative ?? null),
        ];
    }

    // ---------------------------------------------------------------- moves

    /**
     * The ledger, newest first (cursor-paged: it only grows).
     *
     * @param  array{branch_id?: string, location_id?: string, item_id?: string, move_type?: string, source_type?: string, from?: string, to?: string}  $filters
     * @return CursorPaginator<int, StockMove>
     */
    public function moves(array $filters, int $perPage): CursorPaginator
    {
        $query = StockMove::query()
            ->visibleTo($this->tenancy->require())
            ->whereIn('branch_id', $this->branches($filters['branch_id'] ?? null))
            ->with('item');

        foreach (['location_id', 'item_id', 'move_type', 'source_type'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }
        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', BusinessCalendar::startOfDayUtc($filters['from']));
        }
        if (isset($filters['to'])) {
            $query->where('occurred_at', '<', BusinessCalendar::startOfDayUtc(Calendar::addDays(Calendar::parseDate($filters['to']), 1)->format('Y-m-d')));
        }

        $page = $query->orderByDesc('occurred_at')->orderByDesc('id')->cursorPaginate($perPage);
        $this->labelSources($page->items());

        return $page;
    }

    /**
     * Stamp each move with the number of the document behind it (a receipt, a
     * transfer, a count, or the work order a line belongs to).
     *
     * @param  array<int, StockMove>  $moves
     */
    private function labelSources(array $moves): void
    {
        $byType = [];
        foreach ($moves as $move) {
            if ($move->source_id !== null) {
                $byType[$move->source_type->value][] = $move->source_id;
            }
        }

        $references = [];
        foreach ([
            StockSource::GoodsReceipt->value => GoodsReceipt::class,
            StockSource::StockTransfer->value => StockTransfer::class,
            StockSource::StockCount->value => StockCount::class,
        ] as $type => $model) {
            foreach ($model::query()->whereIn('id', $byType[$type] ?? [])->get(['id', 'reference']) as $document) {
                $references[$type.':'.$document->id] = ['reference' => $document->reference, 'id' => $document->id];
            }
        }
        $lines = WorkOrderLine::query()->whereIn('id', $byType[StockSource::WorkOrderLine->value] ?? [])->get(['id', 'work_order_id']);
        $orders = WorkOrder::query()->whereIn('id', $lines->pluck('work_order_id')->all())->get(['id', 'reference'])->keyBy('id');
        foreach ($lines as $line) {
            $order = $orders->get($line->work_order_id);
            if ($order instanceof WorkOrder) {
                $references[StockSource::WorkOrderLine->value.':'.$line->id] = ['reference' => $order->reference, 'id' => $order->id];
            }
        }

        foreach ($moves as $move) {
            $found = $references[$move->source_type->value.':'.$move->source_id] ?? null;
            $move->setAttribute('source_reference', $found['reference'] ?? null);
            $move->setAttribute('source_document_id', $found['id'] ?? null);
        }
    }

    // --------------------------------------------------------------- alerts

    /**
     * Low-stock alerts, derived on read from each branch's reorder points and
     * what its store holds (an item with a reorder point and no stock at all
     * counts as zero on hand): worst first. Never stored.
     *
     * @param  array{branch_id?: string}  $filters
     * @return list<array{id: string, severity: string, item: Item, location: StockLocation, on_hand: string, reorder_point: string, message: string}>
     */
    public function alerts(array $filters): array
    {
        $context = $this->tenancy->require();
        $branchIds = $this->branches($filters['branch_id'] ?? null);

        $settings = ItemBranchSetting::query()->visibleTo($context)->whereIn('branch_id', $branchIds)->whereNotNull('reorder_point')
            ->whereHas('item', fn ($item) => $item->where('is_active', true)->where('is_stocked', true))
            ->with('item')->get();
        $stores = StockLocation::query()->visibleTo($context)->whereIn('branch_id', $branchIds)->where('kind', 'store')->get()->keyBy('branch_id');
        $balances = StockBalance::query()->visibleTo($context)->whereIn('location_id', $stores->pluck('id')->all())->get()->keyBy(fn (StockBalance $b): string => $b->location_id.':'.$b->item_id);

        $positions = [];
        $byKey = [];
        foreach ($settings as $setting) {
            $store = $stores->get($setting->branch_id);
            if (! $store instanceof StockLocation || $setting->reorder_point === null) {
                continue;
            }
            $balance = $balances->get($store->id.':'.$setting->item_id);
            $positions[] = [
                'item_id' => $setting->item_id,
                'location_id' => $store->id,
                'on_hand' => (string) ($balance instanceof StockBalance ? $balance->on_hand : BigDecimal::zero()),
                'reorder_point' => (string) $setting->reorder_point,
            ];
            $byKey['stock:'.$setting->item_id.':'.$store->id] = ['item' => $setting->item, 'location' => $store];
        }

        $stripped = fn (string $n): string => (string) BigDecimal::of($n)->strippedOfTrailingZeros();
        $alerts = [];
        foreach (StockAlerts::derive($positions) as $alert) {
            ['item' => $item, 'location' => $location] = $byKey[$alert['id']];
            $alerts[] = [
                'id' => $alert['id'],
                'severity' => $alert['severity'],
                'item' => $item,
                'location' => $location,
                'on_hand' => $alert['on_hand'],
                'reorder_point' => $alert['reorder_point'],
                'message' => $alert['severity'] === StockAlerts::CRITICAL
                    ? sprintf('%s is out of stock at %s (reorder point %s).', $item->name, $location->name, $stripped($alert['reorder_point']))
                    : sprintf('%s is low at %s: %s %s on hand against a reorder point of %s.', $item->name, $location->name, $stripped($alert['on_hand']), $item->uom, $stripped($alert['reorder_point'])),
            ];
        }

        return $alerts;
    }

    // -------------------------------------------------------------- reorder

    /**
     * The Reorder view: for every active stocked item at every branch in
     * scope, what is on hand, what is on order, the branch's reorder point
     * and quantity, and the fleet forecast's shortfall for the SKU (Phase 4's
     * forecast, per active customer account, matched to the item by SKU) —
     * and from those what to buy. Only rows that need something unless `all`.
     *
     * @param  array{branch_id?: string, horizon_weeks?: int, all?: bool, customer_account_id?: string}  $filters
     * @return list<array{item: Item, branch_id: string, on_hand: BigDecimal, on_order: BigDecimal, forecast_shortfall: BigDecimal, reorder_point: BigDecimal|null, reorder_qty: BigDecimal|null, avg_cost_cents: int|null, advice: ReorderAdvice}>
     */
    public function reorder(array $filters): array
    {
        $context = $this->tenancy->require();
        $branchIds = $this->branches($filters['branch_id'] ?? null);
        $horizon = $filters['horizon_weeks'] ?? 6;

        $items = Item::query()->where('is_active', true)->where('is_stocked', true)->with('preferredVendor')->orderByRaw('lower(sku)')->orderBy('id')->get();
        $balances = [];
        foreach (StockBalance::query()->visibleTo($context)->whereIn('branch_id', $branchIds)->get() as $held) {
            $balances[$held->item_id.':'.$held->branch_id] = $held;
        }
        $settings = [];
        foreach (ItemBranchSetting::query()->visibleTo($context)->whereIn('branch_id', $branchIds)->get() as $configured) {
            $settings[$configured->item_id.':'.$configured->branch_id] = $configured;
        }
        $onOrder = $this->onOrder($branchIds);
        $demand = $this->forecastShortfall($filters['customer_account_id'] ?? null, $horizon);

        $rows = [];
        foreach ($items as $item) {
            foreach ($branchIds as $branchId) {
                $balance = $balances[$item->id.':'.$branchId] ?? null;
                $setting = $settings[$item->id.':'.$branchId] ?? null;
                $onHand = $balance instanceof StockBalance ? $balance->on_hand : BigDecimal::zero();
                $incoming = $onOrder[$item->id.':'.$branchId] ?? BigDecimal::zero();
                // The forecast is the fleet's, not any branch's: it is counted once, against the branch asked for or the first in scope.
                $shortfall = $branchId === $branchIds[0] ? ($demand[mb_strtolower($item->sku)] ?? BigDecimal::zero()) : BigDecimal::zero();

                $advice = ReorderPlanner::advise(
                    (string) $onHand,
                    (string) $incoming,
                    (string) $shortfall,
                    $setting instanceof ItemBranchSetting && $setting->reorder_point !== null ? (string) $setting->reorder_point : null,
                    $setting instanceof ItemBranchSetting && $setting->reorder_qty !== null ? (string) $setting->reorder_qty : null,
                    (string) $item->purchase_uom_factor,
                );
                if ($advice->reason === ReorderAdvice::OK && ! ($filters['all'] ?? false)) {
                    continue;
                }

                $rows[] = [
                    'item' => $item,
                    'branch_id' => $branchId,
                    'on_hand' => $onHand,
                    'on_order' => $incoming,
                    'forecast_shortfall' => $shortfall,
                    'reorder_point' => $setting instanceof ItemBranchSetting ? $setting->reorder_point : null,
                    'reorder_qty' => $setting instanceof ItemBranchSetting ? $setting->reorder_qty : null,
                    'avg_cost_cents' => $balance instanceof StockBalance ? $balance->avg_cost_cents : null,
                    'advice' => $advice,
                ];
            }
        }

        return $rows;
    }

    /**
     * Stock units still to arrive on issued shop orders, per "item:branch".
     *
     * @param  list<string>  $branchIds
     * @return array<string, BigDecimal>
     */
    private function onOrder(array $branchIds): array
    {
        $orders = ShopPurchaseOrder::query()->visibleTo($this->tenancy->require())->whereIn('branch_id', $branchIds)->where('status', StoredOrderStatus::Issued->value)->with('lines.item')->get();
        $lineIds = [];
        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $lineIds[] = $line->id;
            }
        }
        $received = $this->orders->receivedByLine($lineIds);

        $incoming = [];
        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                if ($line->item === null) {
                    continue;
                }
                $outstanding = BigDecimal::max($line->quantity->minus($received[$line->id] ?? BigDecimal::zero()), BigDecimal::zero());
                $key = $line->item_id.':'.$order->branch_id;
                $incoming[$key] = ($incoming[$key] ?? BigDecimal::zero())->plus($outstanding->multipliedBy($line->item->purchase_uom_factor));
            }
        }

        return $incoming;
    }

    /**
     * The fleet forecast's shortfall per SKU (lower-cased), summed over the
     * active customer accounts in scope.
     *
     * @return array<string, BigDecimal>
     */
    private function forecastShortfall(?string $accountId, int $horizonWeeks): array
    {
        $accounts = CustomerAccount::query()->visibleTo($this->tenancy->require())->where('status', 'active')
            ->when($accountId !== null, fn ($q) => $q->whereKey($accountId))->orderBy('id')->get();

        $demand = [];
        foreach ($accounts as $account) {
            foreach ($this->parts->forecast($account, $horizonWeeks) as $row) {
                if ($row->shortfall > 0) {
                    $key = mb_strtolower($row->part->sku);
                    $demand[$key] = ($demand[$key] ?? BigDecimal::zero())->plus($row->shortfall);
                }
            }
        }

        return $demand;
    }

    // ------------------------------------------------------------ documents

    /**
     * @return LengthAwarePaginator<int, StockLocation>
     */
    public function locations(int $perPage): LengthAwarePaginator
    {
        return StockLocation::query()->visibleTo($this->tenancy->require())->whereIn('branch_id', $this->branches())->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    /**
     * Shop purchase orders with their derived status. A stored decision
     * (draft, cancelled) filters in the database; "issued", "partially
     * received", "received" and "open" follow from receipts, so those are
     * judged in memory over the issued orders, then paged.
     *
     * @param  array{branch_id?: string, vendor_id?: string, status?: string, q?: string}  $filters
     * @return LengthAwarePaginator<int, ShopOrderView>
     */
    public function shopOrders(array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $query = ShopPurchaseOrder::query()
            ->visibleTo($this->tenancy->require())
            ->whereIn('branch_id', $this->branches($filters['branch_id'] ?? null))
            ->with('lines');
        if (isset($filters['vendor_id'])) {
            $query->where('vendor_id', $filters['vendor_id']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $like = '%'.addcslashes($filters['q'], '%_\\').'%';
            $query->where(fn ($any) => $any->whereLike('reference', $like)->orWhereLike('vendor_name', $like));
        }

        $status = $filters['status'] ?? null;
        $derived = in_array($status, ['issued', 'partially_received', 'received', 'open'], true);
        if (in_array($status, ['draft', 'cancelled'], true)) {
            $query->where('status', $status);
        } elseif ($derived) {
            $query->where('status', StoredOrderStatus::Issued->value);
        }
        $query->orderByDesc('created_at')->orderByDesc('id');

        if (! $derived) {
            return $query->paginate($perPage, page: $page)->through(fn (ShopPurchaseOrder $order): ShopOrderView => $this->view($order));
        }

        $wanted = $status === 'open' ? [ShopOrderStatus::Issued, ShopOrderStatus::PartiallyReceived] : [ShopOrderStatus::from((string) $status)];
        $views = array_values($query->get()->map(fn (ShopPurchaseOrder $order): ShopOrderView => $this->view($order))->all());
        $matching = array_values(array_filter($views, fn (ShopOrderView $v): bool => in_array($v->order->derivedStatus($v->received), $wanted, true)));

        return new Page(array_slice($matching, ($page - 1) * $perPage, $perPage), count($matching), $perPage, $page);
    }

    public function view(ShopPurchaseOrder $order): ShopOrderView
    {
        $order->loadMissing('lines');

        return new ShopOrderView($order, $this->orders->receivedByLine(array_values($order->lines->map(fn (ShopPurchaseOrderLine $l): string => $l->id)->all())));
    }

    /**
     * @param  array{branch_id?: string, shop_purchase_order_id?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, GoodsReceipt>
     */
    public function goodsReceipts(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = GoodsReceipt::query()->visibleTo($this->tenancy->require())->whereIn('branch_id', $this->branches($filters['branch_id'] ?? null))->with(['lines.item', 'order']);
        foreach (['shop_purchase_order_id', 'status'] as $column) {
            if (isset($filters[$column])) {
                $query->where($column, $filters[$column]);
            }
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * @param  array{branch_id?: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, StockCount>
     */
    public function counts(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = StockCount::query()->visibleTo($this->tenancy->require())->whereIn('branch_id', $this->branches($filters['branch_id'] ?? null))->with('lines.item');
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->paginate($perPage);
    }

    /**
     * Transfers touching the branches in scope (either end).
     *
     * @param  array{branch_id?: string}  $filters
     * @return LengthAwarePaginator<int, StockTransfer>
     */
    public function transfers(array $filters, int $perPage): LengthAwarePaginator
    {
        $branches = $this->branches($filters['branch_id'] ?? null);

        return StockTransfer::query()
            ->visibleTo($this->tenancy->require())
            ->where(fn ($either) => $either->whereIn('from_branch_id', $branches)->orWhereIn('to_branch_id', $branches))
            ->with('lines.item')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate($perPage);
    }
}
