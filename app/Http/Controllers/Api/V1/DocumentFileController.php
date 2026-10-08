<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Documents\DocumentStorage;
use App\Models\Document;
use App\Tenancy\TenantManager;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class DocumentFileController
{
    /**
     * Document file (signed)
     *
     * Streams a document's file. Reachable only through the signed, 60-second
     * URL from GET /documents/{id}/download: the signature (bound to the
     * document id and expiry, checked by the `signed` middleware) is the
     * credential, so there is no session and the lookup runs in the named
     * system context "signed document download".
     *
     * @unauthenticated
     */
    public function __invoke(string $document, TenantManager $tenancy, DocumentStorage $storage): StreamedResponse
    {
        $found = $tenancy->system('signed document download', fn (): Document => Document::query()->findOrFail($document));

        return $storage->stream($found);
    }
}
