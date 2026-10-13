<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * The provider's approved third-party vendors (subcontractors), organization
 * wide. Work orders keep a vendor's name as text (the historical label), so
 * removing a vendor never rewrites a past job. A vendor that bills the shop
 * (Phase 12) also carries what a bill, a 2307 and the purchases list print:
 * registered name, TIN, address, VAT registration, terms, and the tax the
 * shop usually withholds from it.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $name
 * @property bool $is_active
 * @property string|null $registered_name
 * @property string|null $tin
 * @property string|null $address
 * @property bool $is_vat_registered
 * @property int $payment_terms_days
 * @property string|null $default_atc_code
 * @property BigDecimal|null $default_withholding_rate_pct
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Vendor extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $attributes = ['is_active' => true, 'is_vat_registered' => true, 'payment_terms_days' => 0];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_vat_registered' => 'boolean',
            'payment_terms_days' => 'integer',
            'default_withholding_rate_pct' => DecimalCast::class,
        ];
    }
}
