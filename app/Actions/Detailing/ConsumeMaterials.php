<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Inventory\PostStockMove;
use App\Actions\Inventory\StockLocations;
use App\Domain\Detailing\Materials;
use App\Domain\Inventory\MoveRequest;
use App\Domain\Inventory\MoveType;
use App\Domain\Inventory\StockSource;
use App\Models\DetailingBooking;
use App\Models\DetailingPackageMaterial;
use Carbon\CarbonImmutable;

/**
 * Take a finished job's package materials off the shelf: one `consumption`
 * move per line of the package's bill of materials, scaled by the vehicle's
 * size class, through the stock ledger (which books the cost of consumables).
 *
 * Once per booking. A job re-opened and completed again does not use its
 * materials twice; what was taken stays taken.
 */
final class ConsumeMaterials
{
    public function __construct(
        private readonly DetailingJournal $journal,
        private readonly PostStockMove $ledger,
        private readonly StockLocations $locations,
    ) {}

    /** Runs inside the status change's transaction, with the booking row locked. */
    public function handle(DetailingBooking $booking, CarbonImmutable $at): void
    {
        DetailingJournal::inTransaction();
        if ($booking->materials_consumed_at !== null) {
            return;
        }

        $materials = DetailingPackageMaterial::query()->where('package_id', $booking->package_id)->orderBy('item_id')->get();
        $settings = $this->journal->settings($booking->organization_id, $booking->branch_id);
        $cost = 0;

        if ($materials->isNotEmpty()) {
            $location = $this->locations->storeOf($booking->branch_id);
            $this->ledger->lock(array_values($materials->map(fn (DetailingPackageMaterial $line): array => ['location' => $location, 'item_id' => $line->item_id])->all()));

            foreach ($materials as $line) {
                $quantity = Materials::quantityFor((string) $line->quantity, $line->scales_with_size, $settings->multiplierFor($booking->size_class));
                $move = $this->ledger->handle(
                    $location,
                    $line->item_id,
                    new MoveRequest(MoveType::Consumption, '-'.$quantity),
                    StockSource::DetailingBooking,
                    $booking->id,
                    "{$booking->reference}: {$booking->package_name} ({$booking->size_class->label()})",
                    $at,
                );
                // What left the Inventory account: the book value of what was used.
                $cost -= $move->bookDeltaCents;
            }
        }

        $booking->forceFill(['materials_consumed_at' => $at, 'materials_cost_cents' => $cost])->save();
    }
}
