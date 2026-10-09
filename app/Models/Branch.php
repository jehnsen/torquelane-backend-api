<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Domain\Branding\BrandMark;
use App\Domain\Inventory\NegativeStockPolicy;
use App\Domain\Tenancy\TenantContext;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property string $slug
 * @property string|null $address
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $tin
 * @property string|null $branch_code
 * @property bool $is_vat_registered
 * @property bool $prices_include_vat
 * @property NegativeStockPolicy $negative_stock_policy
 * @property string $timezone
 * @property string|null $brand_name
 * @property string|null $logo_url
 * @property string|null $brand_color
 * @property array<string, string>|null $theme_tokens
 * @property string $status
 * @property string|null $registered_name
 * @property string|null $business_style
 * @property string|null $invoice_header
 * @property string|null $invoice_footer
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Branch extends Model
{
    use BelongsToOrganization;

    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'is_vat_registered' => true,
        'prices_include_vat' => true,
        'timezone' => 'Asia/Manila',
        'status' => 'active',
        'negative_stock_policy' => 'allow_and_flag',
    ];

    protected function casts(): array
    {
        return [
            'is_vat_registered' => 'boolean',
            'prices_include_vat' => 'boolean',
            'theme_tokens' => JsonObject::class,
            'negative_stock_policy' => NegativeStockPolicy::class,
        ];
    }

    /**
     * Staff see the branches they are allowed; portal users see none.
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, TenantContext $context): void
    {
        $query->whereIn($this->qualifyColumn('id'), $context->isStaff() ? $context->allowedBranchIds : []);
    }

    /**
     * @return HasMany<BranchModule, $this>
     */
    public function modules(): HasMany
    {
        return $this->hasMany(BranchModule::class);
    }

    public function brandMark(): BrandMark
    {
        return new BrandMark($this->brand_name, $this->logo_url, $this->brand_color, null, $this->theme_tokens);
    }
}
