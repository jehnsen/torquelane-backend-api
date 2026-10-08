<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fleet\Compliance;
use App\Models\Document;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Metadata only. The file is reached through GET …/download (a short-lived
 * URL after the policy check); its path on the disk is never rendered.
 *
 * @property Document $resource
 */
final class DocumentResource extends JsonResource
{
    public function __construct(Document $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $document = $this->resource;
        $expires = $document->expires_on?->toDateString();
        $staff = app(TenantManager::class)->context()?->isStaff() ?? false;

        return [
            'id' => $document->id,
            'customer_account_id' => $document->customer_account_id,
            'vehicle_id' => $document->vehicle_id,
            'work_order_id' => $document->work_order_id,
            'kind' => $document->kind->value,
            'kind_label' => $document->kind->label(),
            'name' => $document->name,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'has_file' => $document->hasFile(),
            'expires_on' => $expires,
            // expired | expiring | ok
            'expiry_status' => Compliance::documentStatus($expires, CarbonImmutable::now('Asia/Manila')),
            'reference_number' => $document->reference_number,
            'issued_on' => $document->issued_on?->toDateString(),
            'issuing_body' => $document->issuing_body,
            'notes' => $document->notes,
            'uploaded_by' => $staff ? $document->uploaded_by : null,
            'uploaded_by_name' => $document->uploaded_by_name,
            'uploaded_on' => $document->uploaded_on->toDateString(),
            'created_at' => $document->created_at->toIso8601ZuluString(),
        ];
    }
}
