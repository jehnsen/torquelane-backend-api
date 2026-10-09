<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\DecimalCast;
use App\Domain\Inventory\TaxClass;
use App\Domain\Invoicing\InvoiceLineDraft;
use App\Domain\Invoicing\InvoiceLineKind;
use App\Tenancy\BelongsToOrganization;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One billed line: quantity × unit price less a discount, rounded once
 * (CHECKed). Changes only while its invoice is a draft (trigger).
 *
 * @property string $id
 * @property string $organization_id
 * @property string $invoice_id
 * @property int $position
 * @property InvoiceLineKind $kind
 * @property string $description
 * @property string|null $work_order_id
 * @property string|null $work_order_line_id
 * @property string|null $item_id
 * @property string|null $service_task_id
 * @property BigDecimal $quantity
 * @property int $unit_price_cents
 * @property int $discount_cents
 * @property TaxClass $tax_class
 * @property int $line_total_cents
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class InvoiceLine extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'kind' => InvoiceLineKind::class,
            'quantity' => DecimalCast::class,
            'unit_price_cents' => 'integer',
            'discount_cents' => 'integer',
            'tax_class' => TaxClass::class,
            'line_total_cents' => 'integer',
        ];
    }

    public function draft(): InvoiceLineDraft
    {
        return new InvoiceLineDraft(
            $this->kind,
            $this->description,
            (string) $this->quantity,
            $this->unit_price_cents,
            $this->discount_cents,
            $this->tax_class,
            $this->work_order_id,
            $this->work_order_line_id,
            $this->item_id,
            $this->service_task_id,
        );
    }
}
