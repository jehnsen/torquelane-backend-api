<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\SizeClass;
use App\Models\Branch;
use App\Models\DetailingBlockedSlot;
use App\Models\DetailingSetting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A branch's detailing settings (the source's `update_settings`), and the
 * slots it has closed to bookings (the source's `toggleBlockedSlot`).
 *
 * As in the source the pool can never drop below what existing bookings
 * already hold in one slot, and a booking never takes more than the pool by
 * default.
 */
final class SaveDetailingSettings
{
    public function __construct(
        private readonly AuditTrail $audit,
        private readonly DetailingJournal $journal,
    ) {}

    /**
     * @param  array{
     *     detailer_pool_size?: int, default_detailers_per_booking?: int, closing_minutes?: int,
     *     public_booking_enabled?: bool, public_booking_notice?: string|null, inspection_required?: bool,
     *     size_multipliers?: array<string, string>,
     * }  $data
     */
    public function handle(Branch $branch, array $data): DetailingSetting
    {
        return DB::transaction(function () use ($branch, $data): DetailingSetting {
            // One writer at a time per branch; the row may not exist yet.
            DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ["detailing-settings:{$branch->id}"]);
            $settings = DetailingSetting::query()->where('branch_id', $branch->id)->lockForUpdate()->first();
            $before = $settings === null ? null : AuditTrail::snapshot($settings);
            $settings ??= DetailingSetting::defaults($branch->organization_id, $branch->id);

            $pool = $data['detailer_pool_size'] ?? $settings->detailer_pool_size;
            $default = $data['default_detailers_per_booking'] ?? $settings->default_detailers_per_booking;
            if ($default > $pool) {
                throw ValidationException::withMessages(['default_detailers_per_booking' => 'Default detailers per booking cannot exceed pool size.']);
            }
            $peak = $this->peakHeads($branch);
            if ($pool < $peak) {
                throw ValidationException::withMessages(['detailer_pool_size' => "Pool size cannot drop below {$peak} — existing bookings already use that many detailers in one hour."]);
            }

            $multipliers = $settings->size_multipliers;
            foreach ($data['size_multipliers'] ?? [] as $size => $factor) {
                $parsed = BigDecimal::of($factor);
                if (SizeClass::tryFrom($size) === null || ! $parsed->isPositive() || $parsed->isGreaterThan(10)) {
                    throw ValidationException::withMessages(["size_multipliers.{$size}" => 'A size factor is above 0 and at most 10.']);
                }
                $multipliers[$size] = (string) $parsed->toScale(3, RoundingMode::HalfUp);
            }

            $settings->forceFill([
                'detailer_pool_size' => $pool,
                'default_detailers_per_booking' => $default,
                'closing_minutes' => $data['closing_minutes'] ?? $settings->closing_minutes,
                'public_booking_enabled' => $data['public_booking_enabled'] ?? $settings->public_booking_enabled,
                'public_booking_notice' => array_key_exists('public_booking_notice', $data)
                    ? (trim($data['public_booking_notice'] ?? '') === '' ? null : trim((string) $data['public_booking_notice']))
                    : $settings->public_booking_notice,
                'inspection_required' => $data['inspection_required'] ?? $settings->inspection_required,
                'size_multipliers' => $multipliers + DetailingSetting::DEFAULT_SIZE_MULTIPLIERS,
            ])->save();
            $this->audit->record($settings, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($settings));

            return $settings;
        });
    }

    /**
     * Close slots of a day to new bookings. Slots already closed keep their label.
     *
     * @param  list<int>  $slots  slot starts, minutes since midnight
     * @return list<DetailingBlockedSlot>
     */
    public function block(Branch $branch, string $date, array $slots, ?string $label): array
    {
        return DB::transaction(function () use ($branch, $date, $slots, $label): array {
            $created = [];
            foreach (array_values(array_unique($slots)) as $slot) {
                $row = DetailingBlockedSlot::query()->where('branch_id', $branch->id)->whereDate('date', $date)->where('slot', $slot)->first();
                if ($row === null) {
                    $row = new DetailingBlockedSlot;
                    $row->forceFill([
                        'organization_id' => $branch->organization_id,
                        'branch_id' => $branch->id,
                        'date' => $date,
                        'slot' => $slot,
                        'label' => trim($label ?? '') === '' ? 'Unavailable' : trim((string) $label),
                        'created_by_name' => $this->journal->actorName(),
                    ])->save();
                    $this->audit->record($row, 'blocked', null, AuditTrail::snapshot($row));
                }
                $created[] = $row;
            }

            return $created;
        });
    }

    public function unblock(DetailingBlockedSlot $slot): void
    {
        DB::transaction(function () use ($slot): void {
            $this->audit->record($slot, 'unblocked', AuditTrail::snapshot($slot), null);
            $slot->delete();
        });
    }

    /** The most heads held in any one (date, slot) of the branch, today onward and before. */
    public function peakHeads(Branch $branch): int
    {
        $peak = DB::table('detailing_booking_slots as s')
            ->join('detailing_bookings as b', 'b.id', '=', 's.booking_id')
            ->where('s.branch_id', $branch->id)
            ->where('s.released', false)
            ->whereNotIn('b.status', ['cancelled', 'no_show'])
            ->groupBy('s.date', 's.slot')
            ->selectRaw('sum(b.detailers_count) as heads')
            ->orderByDesc('heads')
            ->value('heads');

        return is_numeric($peak) ? (int) $peak : 0;
    }
}
