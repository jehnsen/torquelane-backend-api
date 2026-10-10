<?php

declare(strict_types=1);

use App\Actions\Detailing\ChangeBookingStatus;
use App\Actions\Detailing\ReassignDetailers;
use App\Actions\Detailing\TakeBooking;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\SizeClass;
use App\Exceptions\ConflictException;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingSlot;
use App\Models\DetailingBookingStatusLog;
use App\Models\DetailingPackage;
use App\Models\DetailingPackagePrice;
use App\Models\DetailingSetting;
use App\Models\Technician;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Tests\Support\World;

/*
 * Characterization of the PORT against the source: the recording of the
 * source's own SQL (tests/Golden/fixtures/detailing/engine.json, see
 * capture/README.md there) replayed step by step through TakeBooking,
 * ReassignDetailers and ChangeBookingStatus on real Postgres, and compared
 * after every step: what was admitted or refused and in which words, the
 * heads reserved, the detailers kept, every (date, slot) with its released
 * flag, the stamps and the status log.
 *
 * Two things the port adds are set aside so the source's behaviour shows:
 * the check-in inspection is switched off for the branch, and the log row the
 * port writes when a booking is made (the source wrote none) is counted
 * separately.
 */

/**
 * @return array{detailers: array<string, string>, scenarios: list<array{name: string, pool: int, closing_minutes: int, steps: list<array<string, mixed>>}>}
 */
function recordedEngine(): array
{
    static $fixture = null;

    return $fixture ??= json_decode((string) file_get_contents(__DIR__.'/../../Golden/fixtures/detailing/engine.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** One scenario of the recording, replayed against the database. */
final class EngineReplay
{
    /** @var array<string, string> detailer key → technician id */
    public array $technicians = [];

    /** @var array<string, string> booking key → booking id */
    public array $bookings = [];

    public function __construct(private readonly Branch $branch, private readonly CustomerAccount $account, int $pool, int $closing)
    {
        $settings = new DetailingSetting;
        $settings->forceFill([
            'organization_id' => $branch->organization_id,
            'branch_id' => $branch->id,
            'detailer_pool_size' => $pool,
            'default_detailers_per_booking' => 1,
            'closing_minutes' => $closing,
            'inspection_required' => false,
        ])->save();

        foreach (recordedEngine()['detailers'] as $key => $name) {
            $technician = new Technician;
            $technician->forceFill(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id, 'name' => $name, 'skill_tags' => ['detailer']])->save();
            $this->technicians[$key] = $technician->id;
        }
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function book(array $step): array
    {
        $package = new DetailingPackage;
        $package->forceFill([
            'organization_id' => $this->branch->organization_id,
            'branch_id' => $this->branch->id,
            'name' => 'Characterization '.$step['key'],
            'duration_text' => $step['duration'],
            'min_detailers' => $step['min'] ?? 1,
            'recommended_detailers' => $step['min'] ?? 1,
        ])->save();
        $price = new DetailingPackagePrice;
        $price->forceFill(['organization_id' => $package->organization_id, 'package_id' => $package->id, 'size_class' => SizeClass::Medium, 'price_cents' => 100_000])->save();

        try {
            $booking = app(TakeBooking::class)->handle($this->branch, $this->account, null, $package, SizeClass::Medium, [
                'date' => $step['date'],
                'start_minutes' => $step['start_minutes'],
                'detailers_count' => isset($step['requested']) && $step['requested'] > 0 ? $step['requested'] : null,
                'detailer_ids' => array_map(fn (string $key): string => $this->technicians[$key], $step['named'] ?? []),
                'status' => BookingStatus::fromSource($step['status'] ?? 'pending') ?? BookingStatus::Pending,
            ]);
        } catch (ConflictException|ValidationException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        $this->bookings[$step['key']] = $booking->id;

        // Where the port put the job is where the source's own planner put it.
        $days = [];
        foreach (DetailingBookingSlot::query()->where('booking_id', $booking->id)->orderBy('date')->orderBy('slot')->get() as $slot) {
            $days[$slot->date->toDateString()][] = $slot->slot;
        }
        expect(array_map(fn (string $date, array $slots): array => ['date' => $date, 'slots' => $slots], array_keys($days), array_values($days)))
            ->toBe($step['source_plan']);

        return ['ok' => true, 'status' => $booking->status->sourceValue(), 'heads' => $booking->detailers_count, 'named' => $this->named($booking)];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function status(array $step): array
    {
        $to = BookingStatus::fromSource($step['to']);
        if ($to === null) {
            return ['ok' => false, 'error' => 'Invalid status.'];
        }
        if (! isset($this->bookings[$step['key']])) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        test()->travelTo(CarbonImmutable::parse($step['at']));
        $booking = app(ChangeBookingStatus::class)->handle(DetailingBooking::query()->findOrFail($this->bookings[$step['key']]), $to, $step['reason'] ?? null);
        $iso = fn (?CarbonImmutable $at): ?string => $at?->utc()->format('Y-m-d\TH:i:s.v\Z');

        return [
            'ok' => true,
            'status' => $booking->status->sourceValue(),
            'started_at' => $iso($booking->started_at),
            'completed_at' => $iso($booking->completed_at),
            'cancellation_reason' => $booking->cancellation_reason,
        ];
    }

    /**
     * @param  array<string, mixed>  $step
     * @return array<string, mixed>
     */
    public function detailers(array $step): array
    {
        $named = $step['named'] ?? [];
        if ($named !== [] && ! isset($this->bookings[$step['key']])) {
            return ['ok' => false, 'error' => 'Booking not found.'];
        }
        $booking = DetailingBooking::query()->findOrFail($this->bookings[$step['key']]);
        // The source was handed the package's minimum with the call.
        DetailingPackage::query()->whereKey($booking->package_id)->update(['min_detailers' => $step['min'] ?? 1, 'recommended_detailers' => $step['min'] ?? 1]);

        try {
            $booking = app(ReassignDetailers::class)->handle($booking, array_map(fn (string $key): string => $this->technicians[$key], $named));
        } catch (ConflictException|ValidationException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'heads' => $booking->detailers_count, 'named' => $this->named($booking)];
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $keys = array_flip($this->bookings);
        $bookings = [];
        $slots = [];
        $held = [];
        foreach (DetailingBooking::query()->whereIn('id', array_values($this->bookings))->with('detailers')->get() as $booking) {
            $bookings[] = ['key' => $keys[$booking->id], 'named' => $this->named($booking), 'heads' => $booking->detailers_count, 'status' => $booking->status->sourceValue()];
            foreach (DetailingBookingSlot::query()->where('booking_id', $booking->id)->get() as $slot) {
                $date = $slot->date->toDateString();
                $slots[] = ['key' => $keys[$booking->id], 'date' => $date, 'slot' => $slot->slot, 'released' => $slot->released];
                if (! $slot->released && $booking->status->holdsCapacity()) {
                    $at = $date.'|'.str_pad((string) $slot->slot, 5, '0', STR_PAD_LEFT);
                    $held[$at] = ($held[$at] ?? 0) + $booking->detailers_count;
                }
            }
        }

        $logs = [];
        $made = [];
        foreach (DetailingBookingStatusLog::query()->whereIn('booking_id', array_values($this->bookings))->orderBy('id')->get() as $log) {
            if ($log->from_status === null) {
                $made[] = $log->booking_id;

                continue;
            }
            $logs[] = [
                'key' => $keys[$log->booking_id],
                'from' => BookingStatus::from($log->from_status)->sourceValue(),
                'to' => BookingStatus::from($log->to_status)->sourceValue(),
                'notes' => $log->notes,
            ];
        }
        // The port's own addition: exactly one "made" row per booking.
        sort($made);
        $all = array_values($this->bookings);
        sort($all);
        expect($made)->toBe($all);

        return engineSnapshot([
            'bookings' => $bookings,
            'slots' => $slots,
            'logs' => $logs,
            'held' => array_map(
                fn (string $at, int $heads): array => ['date' => explode('|', $at)[0], 'slot' => (int) explode('|', $at)[1], 'heads' => $heads],
                array_keys($held),
                array_values($held),
            ),
        ]);
    }

    /**
     * @return list<string>
     */
    private function named(DetailingBooking $booking): array
    {
        $keys = array_flip($this->technicians);

        return array_map(fn (string $id): string => $keys[$id], $booking->load('detailers')->detailerIds());
    }
}

/**
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function engineSnapshot(array $snapshot): array
{
    $sorted = function (array $rows, array $by): array {
        $rows = array_map(function (array $row): array {
            ksort($row);

            return $row;
        }, $rows);
        usort($rows, function (array $a, array $b) use ($by): int {
            foreach ($by as $field) {
                if ($a[$field] !== $b[$field]) {
                    return $a[$field] <=> $b[$field];
                }
            }

            return 0;
        });

        return $rows;
    };

    return [
        'bookings' => $sorted($snapshot['bookings'], ['key']),
        'slots' => $sorted($snapshot['slots'], ['key', 'date', 'slot']),
        'logs' => array_map(function (array $row): array {
            ksort($row);

            return $row;
        }, $snapshot['logs']),
        'held' => $sorted($snapshot['held'], ['date', 'slot']),
    ];
}

it('does what the source does', function (array $scenario) {
    $world = World::build();
    $owner = $world->user('owner@mekanikomore.ph');
    // A branch of its own, so the demo branch's bookings and detailers stay out of the count.
    $branchId = asSystem(function () use ($world): string {
        $branch = new Branch;
        $branch->forceFill([
            'organization_id' => $world->id('prov-mekanikomore'),
            'name' => 'Characterization',
            'slug' => 'characterization',
            'timezone' => 'Asia/Manila',
            'is_vat_registered' => true,
            'prices_include_vat' => false,
            'status' => 'active',
        ])->save();

        return $branch->id;
    });
    $context = app(TenantContextResolver::class)->resolve($owner, null)->context;

    app(TenantManager::class)->actingAs($context, function () use ($world, $scenario, $branchId): void {
        $replay = new EngineReplay(
            Branch::query()->findOrFail($branchId),
            CustomerAccount::query()->findOrFail($world->id('walk-in')),
            $scenario['pool'],
            $scenario['closing_minutes'],
        );

        foreach ($scenario['steps'] as $index => $step) {
            $actual = match ($step['op']) {
                'book' => $replay->book($step),
                'status' => $replay->status($step),
                'detailers' => $replay->detailers($step),
                default => $replay->snapshot(),
            };
            $expected = $step['op'] === 'snapshot' ? engineSnapshot($step['expect']) : $step['expect'];
            ksort($actual);
            ksort($expected);

            expect($actual)->toBe($expected, sprintf('step %d (%s %s)', $index + 1, $step['op'], $step['key'] ?? ''));
        }
    });
})->with(fn (): array => array_column(
    array_map(fn (array $scenario): array => ['name' => $scenario['name'], 'data' => [$scenario]], recordedEngine()['scenarios']),
    'data',
    'name',
));
