<?php

declare(strict_types=1);

namespace Database\Seeders\Demo;

use App\Actions\Detailing\ManageCatalogue;
use App\Actions\Detailing\SaveDetailingSettings;
use App\Actions\Detailing\TakeBooking;
use App\Domain\Detailing\BookingStatus;
use App\Domain\Detailing\SizeClass;
use App\Domain\Shared\Calendar;
use App\Models\Branch;
use App\Models\CustomerAccount;
use App\Models\DetailingPackage;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vehicle;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Detailing at Samahuzai-Biñan (Phase 9): the branch's settings and look, its
 * menu (the source's six packages, each priced per size class, with a bill of
 * materials over the two detailing consumables), its add-ons, and three
 * bookings that have not started. Nothing here moves stock or money, so the
 * figures the earlier phases' tests pin stay as they were.
 *
 * Ids: `detailing:category:<slug>`, `detailing:package:<slug>`,
 * `detailing:addon:<slug>`, `detailing:booking:<key>`.
 */
final class DetailingSeed
{
    /** The Samahuzai look, as the branch's theme tokens: obsidian and gold, Cormorant Garamond over DM Sans. */
    public const array THEME = [
        'page' => '240 5% 4%',
        'surface' => '240 5% 8%',
        'surface-2' => '240 5% 12%',
        'surface-3' => '240 4% 16%',
        'border' => '240 4% 18%',
        'border-strong' => '44 30% 34%',
        'foreground' => '37 39% 94%',
        'muted-foreground' => '240 5% 65%',
        'subtle-foreground' => '240 3% 50%',
        'brand' => '44 54% 54%',
        'brand-foreground' => '240 5% 4%',
        'brand-muted' => '44 40% 14%',
        'ring' => '45 73% 66%',
        'ok' => '150 39% 49%',
        'critical' => '0 70% 60%',
        'font-heading' => 'Cormorant Garamond',
        'font-body' => 'DM Sans',
    ];

    /** slug, name, colour token */
    private const array CATEGORIES = [
        ['exterior', 'Exterior', 'sky'],
        ['full', 'Full detail', 'gold'],
        ['premium', 'Premium', 'violet'],
        ['specialty', 'Specialty', 'amber'],
        ['interior', 'Interior', 'teal'],
    ];

    /**
     * slug, name, category, duration, min, recommended, popular, prices S/M/L/XL (pesos), inclusions, materials [sku => [qty, scales]]
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: int, 6: bool, 7: list<int>, 8: list<string>, 9: array<string, array{0: string, 1: bool}>}>
     */
    private const array PACKAGES = [
        ['essential', 'The Essential', 'exterior', '2–3 hrs', 1, 1, false, [1300, 1500, 1800, 2100],
            ['Exterior Hand Wash', 'Tire Dressing', 'Window Cleaning', 'Interior Vacuum'],
            ['DTL-SHAMPOO-5L' => ['0.050', true], 'DTL-MF-CLOTH' => ['2', false]]],
        ['executive', 'The Executive', 'full', '4–5 hrs', 1, 2, true, [3000, 3500, 4200, 4900],
            ['Full Exterior Detail', 'Clay Bar Treatment', 'Interior Deep Clean', 'Dashboard Polish', 'Leather Conditioning', 'Engine Bay Cleaning'],
            ['DTL-SHAMPOO-5L' => ['0.080', true], 'DTL-MF-CLOTH' => ['4', false]]],
        ['obsidian-elite', 'The Obsidian Elite', 'premium', '6–8 hrs', 2, 2, false, [5200, 6000, 7200, 8400],
            ['Everything in Executive', 'Paint Correction', 'Ceramic Coating Prep', 'Odor Elimination', 'Headlight Restoration', 'VIP Lounge Priority'],
            ['DTL-SHAMPOO-5L' => ['0.100', true], 'DTL-MF-CLOTH' => ['6', false]]],
        ['paint-correction', 'Paint Correction', 'specialty', '5–6 hrs', 1, 2, false, [3900, 4500, 5400, 6300],
            ['Multi-stage paint correction', 'Swirl mark removal', 'Oxidation treatment', 'Final polish & seal'],
            ['DTL-MF-CLOTH' => ['6', false]]],
        ['ceramic-coating', 'Ceramic Coating', 'specialty', '1–2 days', 2, 2, false, [10500, 12000, 14500, 17000],
            ['Surface decontamination', 'Paint correction', 'Professional ceramic coat application', '2-year protection warranty'],
            ['DTL-SHAMPOO-5L' => ['0.100', true], 'DTL-MF-CLOTH' => ['8', false]]],
        ['interior-rescue', 'Interior Rescue', 'interior', '3–4 hrs', 1, 1, false, [2200, 2500, 3000, 3500],
            ['Deep vacuum', 'Shampoo carpets & seats', 'Steam clean vents', 'Stain treatment', 'Deodorize & sanitize'],
            ['DTL-MF-CLOTH' => ['3', false]]],
    ];

    /** slug, name, prices S/M/L/XL (pesos) */
    private const array ADDONS = [
        ['engine-bay', 'Engine Bay Cleaning', [500, 600, 700, 800]],
        ['headlights', 'Headlight Restoration', [800, 800, 900, 900]],
        ['odor', 'Odor Elimination', [600, 700, 850, 1000]],
        ['pet-hair', 'Pet Hair Removal', [400, 500, 650, 800]],
    ];

    /**
     * @param  array<string, string>  $ids  source id → ULID, extended in place
     */
    public static function run(array &$ids): void
    {
        $owner = User::query()->findOrFail($ids['owner@mekanikomore.ph'] ?? throw new RuntimeException('DetailingSeed needs the demo owner.'));
        $context = app(TenantContextResolver::class)->resolve($owner, null)->context ?? throw new RuntimeException('The demo owner has no tenant context.');

        app(TenantManager::class)->actingAs($context, function () use (&$ids): void {
            self::seed($ids);
        });
    }

    /**
     * @param  array<string, string>  $ids
     */
    private static function seed(array &$ids): void
    {
        $branch = Branch::query()->findOrFail($ids['samahuzai-binan'] ?? throw new RuntimeException('DetailingSeed needs the detailing branch.'));
        // The staff app keeps the organization's brand colour; the public page reads the tokens.
        $branch->forceFill(['theme_tokens' => self::THEME])->save();

        app(SaveDetailingSettings::class)->handle($branch, [
            'detailer_pool_size' => 2,
            'default_detailers_per_booking' => 1,
            'closing_minutes' => 1020,
            'public_booking_enabled' => true,
            'inspection_required' => true,
        ]);

        $catalogue = app(ManageCatalogue::class);
        foreach (self::CATEGORIES as [$slug, $name, $color]) {
            $ids["detailing:category:{$slug}"] = $catalogue->saveCategory($branch, null, ['name' => $name, 'slug' => $slug, 'color' => $color])->id;
        }
        $sizes = SizeClass::values();
        foreach (self::PACKAGES as [$slug, $name, $category, $duration, $min, $recommended, $popular, $prices, $inclusions, $materials]) {
            $rows = [];
            foreach ($materials as $sku => [$quantity, $scales]) {
                $rows[] = ['item_id' => $ids["item:{$sku}"] ?? throw new RuntimeException("DetailingSeed needs item {$sku}."), 'quantity' => $quantity, 'scales_with_size' => $scales];
            }
            $ids["detailing:package:{$slug}"] = $catalogue->savePackage($branch, null, [
                'name' => $name,
                'category_id' => $ids["detailing:category:{$category}"],
                'duration_text' => $duration,
                'inclusions' => $inclusions,
                'is_popular' => $popular,
                'min_detailers' => $min,
                'recommended_detailers' => $recommended,
                'prices' => array_map(fn (string $size, int $pesos): array => ['size_class' => $size, 'price_cents' => $pesos * 100], $sizes, $prices),
                'materials' => $rows,
            ])->id;
        }
        foreach (self::ADDONS as [$slug, $name, $prices]) {
            $ids["detailing:addon:{$slug}"] = $catalogue->saveAddon($branch, null, [
                'name' => $name,
                'prices' => array_map(fn (string $size, int $pesos): array => ['size_class' => $size, 'price_cents' => $pesos * 100], $sizes, $prices),
            ])->id;
        }

        self::bookings($branch, $ids);
    }

    /**
     * Three bookings for a fleet client's vehicles, none started: one
     * confirmed for this morning, one pending for this afternoon that rolls
     * into tomorrow, and a two-day coating the day after.
     *
     * @param  array<string, string>  $ids
     */
    private static function bookings(Branch $branch, array &$ids): void
    {
        $account = CustomerAccount::query()->findOrFail($ids['fc-actimed'] ?? throw new RuntimeException('DetailingSeed needs Actimed.'));
        $vehicles = Vehicle::query()->where('customer_account_id', $account->id)->whereNull('archived_at')->orderBy('id')->limit(3)->get()->all();
        if (count($vehicles) < 3) {
            throw new RuntimeException('DetailingSeed needs three Actimed vehicles.');
        }
        $detailers = Technician::query()->where('branch_id', $branch->id)->orderBy('id')->get()
            ->filter(fn (Technician $technician): bool => in_array('detailer', $technician->skill_tags, true))->values();
        $first = $detailers->first() ?? throw new RuntimeException('DetailingSeed needs a detailer.');

        $today = Calendar::parseDate(Calendar::toDate(CarbonImmutable::now()));
        $day = fn (int $offset): string => Calendar::toDate(Calendar::addDays($today, $offset));
        $package = fn (string $slug): DetailingPackage => DetailingPackage::query()->findOrFail($ids["detailing:package:{$slug}"]);
        $take = app(TakeBooking::class);

        $ids['detailing:booking:morning'] = $take->handle($branch, $account, $vehicles[0], $package('essential'), SizeClass::Medium, [
            'date' => $day(0),
            'start_minutes' => 540,
            'detailer_ids' => [$first->id],
            'bay_id' => $ids['bay:detail-1'] ?? null,
            'status' => BookingStatus::Confirmed,
            'addons' => [['addon_id' => $ids['detailing:addon:engine-bay']]],
            'notes' => 'Company car: keep the dashboard matte.',
        ])->id;
        $ids['detailing:booking:afternoon'] = $take->handle($branch, $account, $vehicles[1], $package('executive'), SizeClass::Large, [
            'date' => $day(0),
            'start_minutes' => 780,
        ])->id;
        $ids['detailing:booking:coating'] = $take->handle($branch, $account, $vehicles[2], $package('ceramic-coating'), SizeClass::Large, [
            'date' => $day(2),
            'start_minutes' => 480,
            'detailers_count' => 2,
            'status' => BookingStatus::Confirmed,
        ])->id;
    }
}
