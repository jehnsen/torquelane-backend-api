<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Ledger\LedgerPostings;
use App\Domain\Inventory\InsufficientStock;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockLedger;
use App\Domain\Inventory\StockSource;
use App\Exceptions\ConflictException;
use App\Models\Branch;
use App\Models\Item;
use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\StockMove;
use App\Models\User;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * THE stock ledger service: the only writer of `stock_balances`, and the only
 * thing that appends `stock_moves` (R7). In the caller's transaction it
 *
 *  1. switches on `torquelane.stock_ledger` for that transaction (a trigger
 *     refuses balance writes without it);
 *  2. makes sure the (location, item) balance row exists and LOCKS it, so
 *     concurrent moves on one balance serialise;
 *  3. asks the pure StockLedger what the move does under the branch's
 *     negative-stock policy;
 *  4. appends the move and updates the balance together.
 *
 * A caller moving several balances locks them first, in one fixed order
 * (`lock()`), so two documents touching the same balances cannot deadlock.
 */
final class PostStockMove
{
    private ?User $actor = null;

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly LedgerPostings $postings,
    ) {}

    /**
     * Lock the balances of the given (location, item) pairs, creating the rows
     * that do not exist yet, in (location, item) order.
     *
     * @param  list<array{location: StockLocation, item_id: string}>  $pairs
     */
    public function lock(array $pairs): void
    {
        $this->assertTransaction();
        usort($pairs, fn (array $a, array $b): int => [$a['location']->id, $a['item_id']] <=> [$b['location']->id, $b['item_id']]);
        foreach ($pairs as $pair) {
            $this->lockBalance($pair['location'], $pair['item_id']);
        }
    }

    public function handle(
        StockLocation $location,
        string $itemId,
        MoveRequest $move,
        StockSource $source,
        ?string $sourceId,
        ?string $reason = null,
        ?CarbonImmutable $at = null,
    ): StockMove {
        $this->assertTransaction();

        $item = Item::query()->findOrFail($itemId);
        if (! $item->is_stocked) {
            throw ValidationException::withMessages(['item_id' => "{$item->name} is not a stocked item."]);
        }

        $balance = $this->lockBalance($location, $itemId);
        $policy = Branch::query()->findOrFail($location->branch_id)->negative_stock_policy;

        try {
            $outcome = StockLedger::applyMove($balance->state(), $move, $policy);
        } catch (InsufficientStock $e) {
            throw new ConflictException(
                sprintf('Not enough %s in %s: %s', $item->name, $location->name, $e->getMessage()),
                ['reason' => 'insufficient_stock', 'item_id' => $item->id, 'location_id' => $location->id, 'on_hand' => $e->onHand, 'requested' => $e->requested],
            );
        }

        $actor = $this->actor();
        $row = new StockMove;
        $row->forceFill([
            'organization_id' => $location->organization_id,
            'branch_id' => $location->branch_id,
            'location_id' => $location->id,
            'item_id' => $itemId,
            'quantity' => $move->quantity,
            'unit_cost_cents' => $outcome->unitCostCents,
            'move_type' => $move->type,
            'source_type' => $source,
            'source_id' => $sourceId,
            'occurred_at' => $at ?? CarbonImmutable::now(),
            'actor_id' => $actor?->id,
            'actor_name' => $actor->name ?? 'System',
            'reason' => $reason,
            'negative_flag' => $outcome->negative,
        ])->save();

        // What the Inventory account moves by: the change in the balance's book value.
        $row->bookDeltaCents = StockLedger::valuation($outcome->after) - StockLedger::valuation($balance->state());
        $balance->forceFill(['on_hand' => $outcome->after->onHand, 'avg_cost_cents' => $outcome->after->avgCostCents])->save();

        // Both halves of a transfer are one entry, posted by the transfer once it has made them both.
        if ($move->type !== MoveType::TransferOut && $move->type !== MoveType::TransferIn) {
            $this->postings->stockMove($row, $row->bookDeltaCents, $item->item_type);
        }

        return $row;
    }

    private function lockBalance(StockLocation $location, string $itemId): StockBalance
    {
        // Scoped to this transaction: the balance trigger sees it, nothing after commit does.
        DB::select("select set_config('torquelane.stock_ledger', 'on', true)");

        $now = CarbonImmutable::now('UTC');
        StockBalance::query()->insertOrIgnore([
            'id' => strtolower((string) Str::ulid()),
            'organization_id' => $location->organization_id,
            'branch_id' => $location->branch_id,
            'location_id' => $location->id,
            'item_id' => $itemId,
            'on_hand' => '0',
            'avg_cost_cents' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return StockBalance::query()->where('location_id', $location->id)->where('item_id', $itemId)->lockForUpdate()->firstOrFail();
    }

    private function actor(): ?User
    {
        $userId = $this->tenancy->context()?->userId;
        if ($userId === null) {
            return null;
        }
        if ($this->actor === null || $this->actor->id !== $userId) {
            $this->actor = User::query()->findOrFail($userId);
        }

        return $this->actor;
    }

    private function assertTransaction(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Stock moves are posted inside the document\'s transaction.');
        }
    }
}
