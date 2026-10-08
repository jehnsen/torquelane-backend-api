<?php

declare(strict_types=1);

namespace App\Documents;

use App\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files behind documents, on the private `documents` disk (config/filesystems.php,
 * DOCUMENTS_DISK). Nothing on it is ever publicly reachable: a caller who
 * passes DocumentPolicy::view gets a short-lived URL.
 *
 *  - S3-compatible disks (S3, R2, Supabase Storage, Spaces): the provider's
 *    own temporary URL.
 *  - Anything else (local): a signed /api/v1/document-files/{id} URL that
 *    streams the file. The signature, bound to the document id and an
 *    expiry, is the only credential that route accepts.
 */
final class DocumentStorage
{
    public const int URL_TTL_SECONDS = 60;

    /** The largest file accepted, matching ../web's MAX_DOCUMENT_BYTES (10 MB). */
    public const int MAX_BYTES = 10 * 1024 * 1024;

    public const array MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'];

    public function disk(): Filesystem
    {
        return Storage::disk('documents');
    }

    /** `<organization>/<account>/<document>/<safe file name>` */
    public function store(Document $document, UploadedFile $file): string
    {
        $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'document';
        $extension = $file->guessExtension() ?? 'bin';
        $directory = "{$document->organization_id}/{$document->customer_account_id}/{$document->id}";

        $path = $this->disk()->putFileAs($directory, $file, "{$name}.{$extension}");
        if ($path === false) {
            throw new RuntimeException('The document could not be stored.');
        }

        return $path;
    }

    public function delete(string $path): void
    {
        $this->disk()->delete($path);
    }

    /**
     * @return array{url: string, expires_at: CarbonImmutable}
     */
    public function temporaryUrl(Document $document): array
    {
        $path = $document->storage_path ?? throw new RuntimeException('This document has no file.');
        $expires = CarbonImmutable::now('UTC')->addSeconds(self::URL_TTL_SECONDS);

        $url = config('filesystems.disks.documents.driver') === 's3'
            ? $this->disk()->temporaryUrl($path, $expires)
            : URL::temporarySignedRoute('document-files.show', $expires, ['document' => $document->id]);

        return ['url' => $url, 'expires_at' => $expires];
    }

    public function stream(Document $document): StreamedResponse
    {
        $path = $document->storage_path ?? throw new RuntimeException('This document has no file.');
        $stream = $this->disk()->readStream($path) ?? throw new RuntimeException('The document file is missing.');
        $name = Str::slug(pathinfo($document->name, PATHINFO_FILENAME)) ?: 'document';
        $extension = pathinfo($path, PATHINFO_EXTENSION);

        return new StreamedResponse(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $document->mime_type ?? 'application/octet-stream',
            'Content-Disposition' => sprintf('attachment; filename="%s.%s"', $name, $extension),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
