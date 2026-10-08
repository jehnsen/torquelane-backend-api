<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Documents\DocumentStorage;
use App\Domain\Documents\DocumentKind;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * multipart/form-data: `file` plus metadata. Name a `vehicle_id` (the
 * document is filed under the vehicle's current owner) or a
 * `customer_account_id` (an account-level document).
 */
final class UploadDocumentRequest extends ApiRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.intdiv(DocumentStorage::MAX_BYTES, 1024), 'mimetypes:'.implode(',', DocumentStorage::MIME_TYPES)],
            'kind' => ['required', 'string', Rule::enum(DocumentKind::class)],
            'vehicle_id' => ['required_without:customer_account_id', 'nullable', 'string', 'ulid'],
            'customer_account_id' => ['required_without:vehicle_id', 'nullable', 'string', 'ulid'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'expires_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'reference_number' => ['sometimes', 'nullable', 'string', 'max:255'],
            'issued_on' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'issuing_body' => ['sometimes', 'nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function upload(): UploadedFile
    {
        $file = $this->file('file');

        return $file instanceof UploadedFile ? $file : throw new LogicException('Validated upload is missing.');
    }

    /**
     * @return array{name: string|null, expires_on: string|null, reference_number: string|null, issued_on: string|null, issuing_body: string|null, notes: string|null}
     */
    public function meta(): array
    {
        $text = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->toString() : null;

        return [
            'name' => $text('name'),
            'expires_on' => $text('expires_on'),
            'reference_number' => $text('reference_number'),
            'issued_on' => $text('issued_on'),
            'issuing_body' => $text('issuing_body'),
            'notes' => $text('notes'),
        ];
    }
}
