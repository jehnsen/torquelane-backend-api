<?php

declare(strict_types=1);

namespace App\Actions\Detailing;

use App\Actions\Audit\AuditTrail;
use App\Domain\Detailing\ServiceDuration;
use App\Domain\Detailing\SizeClass;
use App\Exceptions\ConflictException;
use App\Models\Branch;
use App\Models\DetailingAddon;
use App\Models\DetailingAddonPrice;
use App\Models\DetailingBooking;
use App\Models\DetailingBookingAddon;
use App\Models\DetailingCategory;
use App\Models\DetailingPackage;
use App\Models\DetailingPackageMaterial;
use App\Models\DetailingPackagePrice;
use App\Models\DetailingRecurringSchedule;
use App\Models\Item;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A branch's detailing menu: categories, packages (with a price per size
 * class and a bill of materials) and add-ons (with a price per size class).
 *
 * The source kept one row per package PER SIZE, the size in its name. Here a
 * package is one row and its sizes are prices, so a size is offered exactly
 * when it has a price. A package or add-on that bookings name is never
 * deleted (they carry its id); it is switched off instead.
 */
final class ManageCatalogue
{
    public function __construct(private readonly AuditTrail $audit) {}

    /**
     * @param  array{name: string, slug?: string|null, color?: string|null, position?: int|null}  $data
     */
    public function saveCategory(Branch $branch, ?DetailingCategory $category, array $data): DetailingCategory
    {
        return DB::transaction(function () use ($branch, $category, $data): DetailingCategory {
            $before = $category === null ? null : AuditTrail::snapshot($category);
            $category ??= (new DetailingCategory)->forceFill(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id]);

            $name = trim($data['name']);
            $slug = Str::slug(trim($data['slug'] ?? '') !== '' ? (string) $data['slug'] : $name);
            if ($slug === '') {
                throw ValidationException::withMessages(['slug' => 'A category needs a slug of letters or digits.']);
            }
            $this->unique(DetailingCategory::class, $branch, 'slug', $slug, $category->exists ? $category->id : null, 'slug', 'Another category of this branch has that slug.');

            $category->forceFill([
                'name' => $name,
                'slug' => $slug,
                'color' => $data['color'] ?? $category->getAttributes()['color'] ?? null,
                'position' => $data['position'] ?? ($category->exists ? $category->position : $this->nextPosition(DetailingCategory::class, $branch)),
            ])->save();
            $this->audit->record($category, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($category));

            return $category;
        });
    }

    public function deleteCategory(DetailingCategory $category): void
    {
        DB::transaction(function () use ($category): void {
            if (DetailingPackage::query()->where('category_id', $category->id)->exists()) {
                throw new ConflictException("{$category->name} still has packages; move them to another category first.");
            }
            $this->audit->record($category, 'deleted', AuditTrail::snapshot($category), null);
            $category->delete();
        });
    }

    /**
     * @param  array{
     *     name: string, description?: string|null, category_id?: string|null, duration_text: string,
     *     inclusions?: list<string>, is_popular?: bool, min_detailers?: int, recommended_detailers?: int,
     *     applies_to?: string, is_active?: bool, position?: int|null,
     *     prices: list<array{size_class: string, price_cents: int, duration_text?: string|null}>,
     *     materials?: list<array{item_id: string, quantity: string, scales_with_size?: bool}>|null,
     * }  $data
     */
    public function savePackage(Branch $branch, ?DetailingPackage $package, array $data): DetailingPackage
    {
        return DB::transaction(function () use ($branch, $package, $data): DetailingPackage {
            $before = $package === null ? null : AuditTrail::snapshot($package);
            $package ??= (new DetailingPackage)->forceFill(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id]);

            $name = trim($data['name']);
            $this->unique(DetailingPackage::class, $branch, 'name', $name, $package->exists ? $package->id : null, 'name', 'Another package of this branch has that name.');
            self::assertDuration('duration_text', $data['duration_text']);
            if (($data['category_id'] ?? null) !== null && ! DetailingCategory::query()->where('branch_id', $branch->id)->whereKey($data['category_id'])->exists()) {
                throw ValidationException::withMessages(['category_id' => 'That category is not on this branch\'s menu.']);
            }
            $min = $data['min_detailers'] ?? ($package->exists ? $package->min_detailers : 1);
            $recommended = max($min, $data['recommended_detailers'] ?? ($package->exists ? $package->recommended_detailers : $min));
            if ($data['prices'] === []) {
                throw ValidationException::withMessages(['prices' => 'Price the package for at least one vehicle size.']);
            }

            $package->forceFill([
                'category_id' => $data['category_id'] ?? null,
                'name' => $name,
                'description' => self::text($data['description'] ?? null),
                'duration_text' => trim($data['duration_text']),
                'inclusions' => array_values(array_filter(array_map(trim(...), $data['inclusions'] ?? ($package->exists ? $package->inclusions : [])), fn (string $line): bool => $line !== '')),
                'is_popular' => $data['is_popular'] ?? ($package->exists && $package->is_popular),
                'min_detailers' => $min,
                'recommended_detailers' => $recommended,
                'applies_to' => $data['applies_to'] ?? ($package->exists ? $package->applies_to : 'all'),
                'is_active' => $data['is_active'] ?? (! $package->exists || $package->is_active),
                'position' => $data['position'] ?? ($package->exists ? $package->position : $this->nextPosition(DetailingPackage::class, $branch)),
            ])->save();

            DetailingPackagePrice::query()->where('package_id', $package->id)->delete();
            $seen = [];
            foreach ($data['prices'] as $index => $row) {
                $size = SizeClass::from($row['size_class']);
                if (isset($seen[$size->value])) {
                    throw ValidationException::withMessages(["prices.{$index}.size_class" => "{$size->label()} is priced twice."]);
                }
                $seen[$size->value] = true;
                $duration = self::text($row['duration_text'] ?? null);
                if ($duration !== null) {
                    self::assertDuration("prices.{$index}.duration_text", $duration);
                }
                (new DetailingPackagePrice)->forceFill([
                    'organization_id' => $package->organization_id,
                    'package_id' => $package->id,
                    'size_class' => $size,
                    'price_cents' => $row['price_cents'],
                    'duration_text' => $duration,
                ])->save();
            }

            if (array_key_exists('materials', $data) && $data['materials'] !== null) {
                $this->writeMaterials($package, $data['materials']);
            }

            $this->audit->record($package, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($package));

            return $package->load('prices', 'materials.item', 'category');
        });
    }

    public function deletePackage(DetailingPackage $package): void
    {
        DB::transaction(function () use ($package): void {
            if (DetailingBooking::query()->where('package_id', $package->id)->exists() || DetailingRecurringSchedule::query()->where('package_id', $package->id)->exists()) {
                throw new ConflictException("{$package->name} has bookings; switch it off instead of deleting it.");
            }
            $this->audit->record($package, 'deleted', AuditTrail::snapshot($package), null);
            $package->delete();
        });
    }

    /**
     * @param  array{name: string, is_active?: bool, position?: int|null, prices: list<array{size_class: string, price_cents: int}>}  $data
     */
    public function saveAddon(Branch $branch, ?DetailingAddon $addon, array $data): DetailingAddon
    {
        return DB::transaction(function () use ($branch, $addon, $data): DetailingAddon {
            $before = $addon === null ? null : AuditTrail::snapshot($addon);
            $addon ??= (new DetailingAddon)->forceFill(['organization_id' => $branch->organization_id, 'branch_id' => $branch->id]);

            $name = trim($data['name']);
            $this->unique(DetailingAddon::class, $branch, 'name', $name, $addon->exists ? $addon->id : null, 'name', 'Another add-on of this branch has that name.');
            $addon->forceFill([
                'name' => $name,
                'is_active' => $data['is_active'] ?? (! $addon->exists || $addon->is_active),
                'position' => $data['position'] ?? ($addon->exists ? $addon->position : $this->nextPosition(DetailingAddon::class, $branch)),
            ])->save();

            DetailingAddonPrice::query()->where('addon_id', $addon->id)->delete();
            $seen = [];
            foreach ($data['prices'] as $index => $row) {
                $size = SizeClass::from($row['size_class']);
                if (isset($seen[$size->value])) {
                    throw ValidationException::withMessages(["prices.{$index}.size_class" => "{$size->label()} is priced twice."]);
                }
                $seen[$size->value] = true;
                (new DetailingAddonPrice)->forceFill([
                    'organization_id' => $addon->organization_id,
                    'addon_id' => $addon->id,
                    'size_class' => $size,
                    'price_cents' => $row['price_cents'],
                ])->save();
            }
            $this->audit->record($addon, $before === null ? 'created' : 'updated', $before, AuditTrail::snapshot($addon));

            return $addon->load('prices');
        });
    }

    public function deleteAddon(DetailingAddon $addon): void
    {
        DB::transaction(function () use ($addon): void {
            if (DetailingBookingAddon::query()->where('addon_id', $addon->id)->exists()) {
                throw new ConflictException("{$addon->name} is on bookings; switch it off instead of deleting it.");
            }
            $this->audit->record($addon, 'deleted', AuditTrail::snapshot($addon), null);
            $addon->delete();
        });
    }

    /**
     * Put a branch's packages, categories or add-ons in the order given (the
     * source's drag-to-reorder). Ids of another branch are ignored.
     *
     * @param  class-string<DetailingPackage|DetailingCategory|DetailingAddon>  $model
     * @param  list<string>  $ids
     */
    public function reorder(Branch $branch, string $model, array $ids): void
    {
        DB::transaction(function () use ($branch, $model, $ids): void {
            foreach (array_values(array_unique($ids)) as $position => $id) {
                $model::query()->where('branch_id', $branch->id)->whereKey($id)->update(['position' => $position + 1]);
            }
        });
    }

    /**
     * @param  list<array{item_id: string, quantity: string, scales_with_size?: bool}>  $rows
     */
    private function writeMaterials(DetailingPackage $package, array $rows): void
    {
        DetailingPackageMaterial::query()->where('package_id', $package->id)->delete();
        $ids = array_values(array_unique(array_map(fn (array $row): string => $row['item_id'], $rows)));
        $stocked = $ids === [] ? [] : Item::query()->whereIn('id', $ids)->where('is_stocked', true)->pluck('id')->all();

        $seen = [];
        foreach ($rows as $index => $row) {
            if (! in_array($row['item_id'], $stocked, true)) {
                throw ValidationException::withMessages(["materials.{$index}.item_id" => 'Pick a stocked item.']);
            }
            if (isset($seen[$row['item_id']])) {
                throw ValidationException::withMessages(["materials.{$index}.item_id" => 'That item is listed twice.']);
            }
            $seen[$row['item_id']] = true;
            $quantity = BigDecimal::of($row['quantity']);
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(["materials.{$index}.quantity" => 'A quantity is above zero.']);
            }
            (new DetailingPackageMaterial)->forceFill([
                'organization_id' => $package->organization_id,
                'package_id' => $package->id,
                'item_id' => $row['item_id'],
                'quantity' => $quantity,
                'scales_with_size' => $row['scales_with_size'] ?? true,
            ])->save();
        }
    }

    /** A duration the planner can read: it names hours, minutes or days. */
    private static function assertDuration(string $field, string $text): void
    {
        if (ServiceDuration::days($text) === 0 && ServiceDuration::minutes($text) <= 0) {
            throw ValidationException::withMessages([$field => 'Say how long it takes, like "45 min", "2–3 hrs" or "1–2 days".']);
        }
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function unique(string $model, Branch $branch, string $column, string $value, ?string $exceptId, string $field, string $message): void
    {
        $taken = $model::query()->where('branch_id', $branch->id)->whereRaw($column === 'slug' ? 'lower(slug) = ?' : 'lower(name) = ?', [mb_strtolower($value)])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    /**
     * After the last one, as the source's sort-order trigger put a new package.
     *
     * @param  class-string<Model>  $model
     */
    private function nextPosition(string $model, Branch $branch): int
    {
        $max = $model::query()->where('branch_id', $branch->id)->max('position');

        return (is_int($max) ? $max : 0) + 1;
    }

    private static function text(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }
}
