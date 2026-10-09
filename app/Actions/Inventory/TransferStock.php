<?php

declare(strict_types=1);

namespace App\Actions\Inventory;

use App\Actions\Audit\AuditTrail;
use App\Actions\Numbering\DocumentNumbers;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Domain\Numbering\DocumentType;
use App\Domain\Shared\Calendar;
use App\Exceptions\ConflictException;
use App\Models\StockLocation;
use App\Models\StockTransfer;
use App\Models\StockTransferLine;
use App\Models\User;
use App\Tenancy\TenantManager;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An inter-branch transfer is ONE document (numbered from the
 * `stock_transfer` series) that makes both moves in one transaction: goods
 * leave the source location at its average cost and arrive at the
 * destination at that same cost, so value is carried across, not created.
 * Both balances are locked up front in one fixed order, so two transfers
 * going opposite ways cannot deadlock.
 *
 * A transfer is immutable (R7). Undoing one is `reverse`: a new transfer, the
 * other way, that names the original (and can be made only once).
 */
final class TransferStock
{
    public function __construct(
        private readonly PostStockMove $ledger,
        private readonly DocumentNumbers $numbers,
        private readonly AuditTrail $audit,
        private readonly TenantManager $tenancy,
    ) {}

    /**
     * @param  list<array{item_id: string, quantity: string|int|float}>  $lines  validated
     */
    public function handle(StockLocation $from, StockLocation $to, array $lines, string $notes = '', ?StockTransfer $reverses = null): StockTransfer
    {
        return DB::transaction(function () use ($from, $to, $lines, $notes, $reverses): StockTransfer {
            if ($from->id === $to->id) {
                throw ValidationException::withMessages(['to_location_id' => 'A transfer goes between two different locations.']);
            }
            if (! $to->is_active) {
                throw ValidationException::withMessages(['to_location_id' => "{$to->name} is not active."]);
            }
            $ids = array_map(fn (array $line): string => $line['item_id'], $lines);
            if (count($ids) !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['lines' => 'Each item appears once on a transfer.']);
            }

            $now = CarbonImmutable::now();
            $actor = User::query()->findOrFail($this->tenancy->require()->userId);

            $this->ledger->lock([
                ...array_map(fn (string $id): array => ['location' => $from, 'item_id' => $id], $ids),
                ...array_map(fn (string $id): array => ['location' => $to, 'item_id' => $id], $ids),
            ]);

            $transfer = new StockTransfer;
            $transfer->forceFill([
                'reference' => $this->numbers->issue($from->organization_id, null, DocumentType::StockTransfer, $now)->formatted,
                'from_branch_id' => $from->branch_id,
                'from_location_id' => $from->id,
                'to_branch_id' => $to->branch_id,
                'to_location_id' => $to->id,
                'reverses_transfer_id' => $reverses?->id,
                'notes' => $notes,
                'transferred_on' => Calendar::toDate($now),
                'created_by' => $actor->id,
                'created_by_name' => $actor->name,
            ])->save();

            foreach ($lines as $position => $input) {
                $quantity = BigDecimal::of((string) $input['quantity']);
                if (! $quantity->isPositive()) {
                    throw ValidationException::withMessages(["lines.{$position}.quantity" => 'Transfer more than nothing.']);
                }
                $out = $this->ledger->handle($from, $input['item_id'], new MoveRequest(MoveType::TransferOut, $quantity->negated()), StockSource::StockTransfer, $transfer->id, "{$transfer->reference} to {$to->name}", $now);
                $this->ledger->handle($to, $input['item_id'], new MoveRequest(MoveType::TransferIn, $quantity, $out->unit_cost_cents), StockSource::StockTransfer, $transfer->id, "{$transfer->reference} from {$from->name}", $now);

                $line = new StockTransferLine;
                $line->forceFill([
                    'stock_transfer_id' => $transfer->id,
                    'position' => $position,
                    'item_id' => $input['item_id'],
                    'quantity' => $quantity,
                    'unit_cost_cents' => $out->unit_cost_cents,
                ])->save();
            }

            $this->audit->record($transfer, $reverses === null ? 'created' : 'reversed', null, AuditTrail::snapshot($transfer) + [
                'lines' => array_values($transfer->lines()->get()->map(fn (StockTransferLine $l): array => AuditTrail::snapshot($l))->all()),
            ]);

            return $transfer->load('lines');
        });
    }

    /** The transfer the other way, once. */
    public function reverse(StockTransfer $transfer): StockTransfer
    {
        return DB::transaction(function () use ($transfer): StockTransfer {
            $locked = StockTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->reverses_transfer_id !== null) {
                throw new ConflictException("{$locked->reference} is itself a reversal; make a new transfer instead.");
            }
            if (StockTransfer::query()->where('reverses_transfer_id', $locked->id)->exists()) {
                throw new ConflictException("{$locked->reference} has already been reversed.");
            }

            $lines = array_values($locked->lines()->get()->map(fn (StockTransferLine $l): array => ['item_id' => $l->item_id, 'quantity' => (string) $l->quantity])->all());

            return $this->handle(
                StockLocation::query()->findOrFail($locked->to_location_id),
                StockLocation::query()->findOrFail($locked->from_location_id),
                $lines,
                "Reverses {$locked->reference}",
                $locked,
            );
        });
    }
}
