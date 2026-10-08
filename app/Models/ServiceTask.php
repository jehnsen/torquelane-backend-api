<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Casts\MoneyCast;
use App\Domain\Fleet\ServiceTaskFacts;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Brick\Money\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A PMS catalogue task, organization-wide.
 *
 * @property string $id
 * @property string $organization_id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property int $interval_km
 * @property int $interval_months
 * @property Money $estimated_cost_cents
 * @property BigDecimal $estimated_hours
 * @property bool $critical
 * @property bool $is_active
 * @property int $position
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ServiceTask extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    public const array CATEGORIES = ['engine', 'drivetrain', 'brakes', 'tires', 'electrical', 'safety', 'body'];

    protected $attributes = ['critical' => false, 'is_active' => true, 'position' => 0, 'estimated_cost_cents' => 0, 'estimated_hours' => '0'];

    protected function casts(): array
    {
        return [
            'interval_km' => 'integer',
            'interval_months' => 'integer',
            'estimated_cost_cents' => MoneyCast::class,
            'estimated_hours' => DecimalCast::class,
            'critical' => 'boolean',
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function facts(): ServiceTaskFacts
    {
        return new ServiceTaskFacts($this->id, $this->name, $this->interval_km, $this->interval_months, $this->critical);
    }
}
