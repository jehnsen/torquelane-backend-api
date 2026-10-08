<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\JsonObject;
use App\Domain\Branding\BrandMark;
use Carbon\CarbonImmutable;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The tenant root (the frontend's "provider"). Not tenant-scoped itself: it is
 * always loaded by the session's own organization_id (TenantContext), never
 * listed or looked up by anything a caller supplies.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $legal_name
 * @property string|null $tin
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $address
 * @property string|null $support_email
 * @property string|null $logo_url
 * @property string|null $brand_color
 * @property array<string, string>|null $theme_tokens
 * @property string $status
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory, HasUlids;

    protected $attributes = ['status' => 'active'];

    protected function casts(): array
    {
        return [
            'theme_tokens' => JsonObject::class,
        ];
    }

    /**
     * @return HasMany<Branch, $this>
     */
    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function brandMark(): BrandMark
    {
        return new BrandMark($this->name, $this->logo_url, $this->brand_color, $this->support_email, $this->theme_tokens);
    }
}
