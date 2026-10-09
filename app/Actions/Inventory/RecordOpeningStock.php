<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditTrail;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Models\StockLocation;
use App\Models\StockMove;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The first count of an item in a location: an `opening` move at a stated
 * cost. Only for an item with no stock history there (anything later is a
 * receipt, or a count's adjustment), so an opening balance cannot be used to
 * rewrite what the ledger already says.
 */
final class RecordOpeningStock
{
    public function __construct(
        private readonly PostStockMove $ledger,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  list<array{item_id: string, quantity: string|int|float, unit_cost_cents: int}>  $lines  validated
     * @return list<StockMove>
     */
    public function handle(StockLocation $location, array $lines, ?string $reason): array
    {
        return DB::transaction(function () use ($location, $lines, $reason): array {
            $ids = array_map(fn (array $line): string => $line['item_id'], $lines);
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['lines' => 'Each item appears once in an opening balance.']);
            }

            $this->ledger->lock(array_map(fn (string $id): array => ['location' => $location, 'item_id' => $id], $ids));
            $now = CarbonImmutable::now();
            $moves = [];
            foreach ($lines as $position => $line) {
                if (StockMove::query()->where('location_id', $location->id)->where('item_id', $line['item_id'])->exists()) {
                    throw ValidationException::withMessages(["lines.{$position}.item_id" => 'This item already has stock history here; take a stock count to correct it.']);
                }
                $moves[] = $this->ledger->handle(
                    $location,
                    $line['item_id'],
                    new MoveRequest(MoveType::Opening, (string) $line['quantity'], $line['unit_cost_cents']),
                    StockSource::Manual,
                    null,
                    $reason,
                    $now,
                );
            }

            $this->audit->record($location, 'opening_stock', null, ['lines' => array_map(fn (StockMove $move): array => AuditTrail::snapshot($move), $moves)]);

            return $moves;
        });
    }
}
