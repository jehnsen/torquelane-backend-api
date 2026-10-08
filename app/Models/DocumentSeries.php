<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Numbering\DocumentType;
use App\Tenancy\BelongsToOrganization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Written only by App\Actions\Numbering\DocumentNumbers.
 *
 * @property string $id
 * @property string $organization_id
 * @property string|null $branch_id
 * @property DocumentType $doc_type
 * @property string $period_key
 * @property string $prefix
 * @property int $next_number
 * @property int $padding
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class DocumentSeries extends Model
{
    use BelongsToOrganization;
    use HasUlids;

    protected $table = 'document_series';

    protected function casts(): array
    {
        return [
            'doc_type' => DocumentType::class,
            'next_number' => 'integer',
            'padding' => 'integer',
        ];
    }
}
