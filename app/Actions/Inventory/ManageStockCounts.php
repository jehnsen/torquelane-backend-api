<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditTrail;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Inventory\CountStatus;
use App\Domain\Inventory\CountVariance;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Domain\Numbering\DocumentType;
use App\Domain\Shared\Calendar;
use App\Exceptions\InvalidTransitionException;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockLocation;
use App\Models\User;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A stock count: draw the sheet, enter what was counted, post it.
 *
 * open     the sheet for one location, numbered from the `stock_count`
 *          series. It lists the items the books hold there (and any named),
 *          each with what the books say now.
 * enter    counted quantities (and an optional reason per line), while open.
 * post     refreshes each counted line's expected quantity to what the books
 *          hold NOW (stock may have moved while counting), and turns every
 *          variance into an `adjustment` move that carries its reason: the
 *          line's, else the count's. A variance with no reason is refused.
 *          Uncounted lines are left alone.
 * cancel   an open sheet is dropped; nothing moves.
 */
final class ManageStockCounts
{
    public function __construct(
        private readonly PostStockMove $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly AuditTrail $audit,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * @param  array{item_ids?: list<string>, reason?: string|null, notes?: string|null}  $data  validated
     */
    public function open(StockLocation $location, array $data): StockCount
    {
        return DB::transaction(function () use ($location, $data): StockCount {
            $now = CarbonImmutable::now();
            $actor = $this->actor();

            $balances = StockBalance::query()->where('location_id', $location->id)->get()->keyBy('item_id');
            $itemIds = array_values(array_unique([...array_keys($balances->all()), ...($data['item_ids'] ?? [])]));
            $items = Item::query()->whereIn('id', $itemIds)->where('is_stocked', true)->get()->keyBy('id');
            foreach ($data['item_ids'] ?? [] as $position => $id) {
                if (! $items->has($id)) {
                    throw ValidationException::withMessages(["item_ids.{$position}" => 'That is not a stocked item.']);
                }
            }
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['item_ids' => 'There is nothing to count here yet: name the items, or record an opening balance first.']);
            }

            $count = new StockCount;
            $count->forceFill([
                'branch_id' => $location->branch_id,
                'location_id' => $location->id,
                'reference' => $this->numbers->issue($location->organization_id, null, DocumentType::StockCount, $now)->formatted,
                'reason' => $data['reason'] ?? null,
                'notes' => $data['notes'] ?? '',
                'created_on' => Calendar::toDate($now),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            foreach ($items->sortBy(fn (Item $item): string => mb_strtolower($item->sku)) as $item) {
                $line = new StockCountLine;
                $line->forceFill([
                    'stock_count_id' => $count->id,
                    'item_id' => $item->id,
                    'expected_quantity' => $balances->get($item->id)->on_hand ?? BigDecimal::zero(),
                ])->save();
            }

            $this->audit->record($count, 'created', null, AuditTrail::snapshot($count));

            return $count->load('lines');
        });
    }

    /**
     * @param  list<array{item_id: string, counted_quantity: string|int|float|null, reason?: string|null}>  $lines  validated
     */
    public function enter(StockCount $count, array $lines): StockCount
    {
        return DB::transaction(function () use ($count, $lines): StockCount {
            $locked = $this->lockOpen($count);
            $before = AuditTrail::snapshot($locked) + ['lines' => $this->snapshots($locked)];

            foreach ($lines as $position => $input) {
                $line = StockCountLine::query()->where('stock_count_id', $locked->id)->where('item_id', $input['item_id'])->first();
                if (! $line instanceof StockCountLine) {
                    // An item found on the shelf that was not on the sheet.
                    $item = Item::query()->find($input['item_id']);
                    if (! $item instanceof Item || ! $item->is_stocked) {
                        throw ValidationException::withMessages(["lines.{$position}.item_id" => 'That is not a stocked item.']);
                    }
                    $balance = StockBalance::query()->where('location_id', $locked->location_id)->where('item_id', $item->id)->first();
                    $line = new StockCountLine;
                    $line->forceFill(['stock_count_id' => $locked->id, 'item_id' => $item->id, 'expected_quantity' => $balance->on_hand ?? BigDecimal::zero()]);
                }
                $line->forceFill([
                    'counted_quantity' => $input['counted_quantity'] === null ? null : BigDecimal::of((string) $input['counted_quantity']),
                    'reason' => array_key_exists('reason', $input) ? $input['reason'] : $line->reason,
                ])->save();
            }

            $this->audit->record($locked, 'counted', $before, AuditTrail::snapshot($locked) + ['lines' => $this->snapshots($locked)]);

            return $locked->load('lines');
        });
    }

    public function post(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $locked = $this->lockOpen($count);
            $before = AuditTrail::snapshot($locked) + ['lines' => $this->snapshots($locked)];
            $location = StockLocation::query()->findOrFail($locked->location_id);
            $lines = $locked->lines()->whereNotNull('counted_quantity')->with('item')->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'Count at least one item before posting.']);
            }

            $this->ledger->lock(array_values($lines->map(fn (StockCountLine $l): array => ['location' => $location, 'item_id' => $l->item_id])->all()));
            $balances = StockBalance::query()->where('location_id', $location->id)->whereIn('item_id', $lines->pluck('item_id')->all())->get()->keyBy('item_id');
            $now = CarbonImmutable::now();

            foreach ($lines as $line) {
                $counted = $line->counted_quantity ?? BigDecimal::zero();
                $book = $balances->get($line->item_id)->on_hand ?? BigDecimal::zero();
                $variance = CountVariance::between((string) $book, (string) $counted);
                $reason = $this->reasonFor($line, $locked);
                if (! $variance->isZero() && $reason === null) {
                    throw ValidationException::withMessages(['lines' => "Give a reason for the variance on {$line->item->name}: on the line, or for the whole count."]);
                }

                $unitCost = $balances->get($line->item_id)->avg_cost_cents ?? 0;
                if (! $variance->isZero()) {
                    $move = $this->ledger->handle(
                        $location,
                        $line->item_id,
                        new MoveRequest(MoveType::Adjustment, $variance),
                        StockSource::StockCount,
                        $locked->id,
                        "{$locked->reference}: {$reason}",
                        $now,
                    );
                    $unitCost = $move->unit_cost_cents;
                }
                $line->forceFill(['expected_quantity' => $book, 'variance_quantity' => $variance, 'unit_cost_cents' => $unitCost])->save();
            }

            $locked->forceFill(['status' => CountStatus::Posted, 'posted_at' => $now, 'posted_by_name' => $this->actor()->name])->save();
            $this->audit->record($locked, 'posted', $before, AuditTrail::snapshot($locked) + ['lines' => $this->snapshots($locked)]);

            return $locked->load('lines');
        });
    }

    public function cancel(StockCount $count): StockCount
    {
        return DB::transaction(function () use ($count): StockCount {
            $locked = $this->lockOpen($count);
            $before = AuditTrail::snapshot($locked);
            $locked->forceFill(['status' => CountStatus::Cancelled, 'cancelled_at' => CarbonImmutable::now(), 'cancelled_by_name' => $this->actor()->name])->save();
            $this->audit->record($locked, 'cancelled', $before, AuditTrail::snapshot($locked));

            return $locked->load('lines');
        });
    }

    private function lockOpen(StockCount $count): StockCount
    {
        $locked = StockCount::query()->lockForUpdate()->findOrFail($count->id);
        if ($locked->status !== CountStatus::Open) {
            throw new InvalidTransitionException("Stock count {$locked->reference} is {$locked->status->value}; only an open count can change.");
        }

        return $locked;
    }

    private function reasonFor(StockCountLine $line, StockCount $count): ?string
    {
        foreach ([$line->reason, $count->reason] as $reason) {
            if (is_string($reason) && trim($reason) !== '') {
                return trim($reason);
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function snapshots(StockCount $count): array
    {
        return array_values($count->lines()->get()->map(fn (StockCountLine $line): array => AuditTrail::snapshot($line))->all());
    }

    private function actor(): User
    {
        return User::query()->findOrFail($this->tenancy->require()->userId);
    }
}
