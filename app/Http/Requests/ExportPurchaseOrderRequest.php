<?php

declare(strict_types=1);

namespace App\Http\Requests;

final class ExportPurchaseOrderRequest extends ApiRequest
{
    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return ['format' => ['sometimes', 'string', 'in:csv,xlsx']];
    }

    /** Export format; xlsx by default, as ../web exported. */
    public function exportFormat(): string
    {
        return $this->string('format', 'xlsx')->toString();
    }
}
