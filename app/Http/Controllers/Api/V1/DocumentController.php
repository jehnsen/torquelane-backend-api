<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\ManageDocuments;
use App\Actions\Fleet\FleetQueries;
use App\Documents\DocumentStorage;
use App\Domain\Documents\DocumentKind;
use App\Exceptions\ConflictException;
use App\Http\Requests\ListDocumentsRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Http\Resources\DocumentCollection;
use App\Http\Resources\DocumentResource;
use App\Models\Document;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Documents. Staff see every document; a portal user the ones filed under
 * their account (never a previous owner's papers for a vehicle they now own).
 */
final class DocumentController
{
    /**
     * List documents
     */
    public function index(ListDocumentsRequest $request, FleetQueries $fleet): DocumentCollection
    {
        Gate::authorize('viewAny', Document::class);

        return new DocumentCollection($fleet->documentPage($request->filters(), $request->perPage()));
    }

    /**
     * Upload a document
     *
     * `document:upload`, multipart. With `vehicle_id` the document is filed
     * under the vehicle's current owner; otherwise under `customer_account_id`.
     * Only renewal kinds (registration, CTPL, comprehensive insurance,
     * emission test, LTFRB franchise, warranty) take `expires_on`. Up to
     * 10 MB of PDF, JPEG, PNG, WebP or HEIC.
     */
    public function store(UploadDocumentRequest $request, FleetQueries $fleet, ManageDocuments $documents): JsonResponse
    {
        $vehicle = null;
        if ($request->filled('vehicle_id')) {
            $vehicle = $fleet->vehicle($request->string('vehicle_id')->lower()->toString());
            Gate::authorize('view', $vehicle);
        }
        $account = $fleet->account($vehicle instanceof Vehicle ? $vehicle->customer_account_id : $request->string('customer_account_id')->lower()->toString());
        Gate::authorize('create', [Document::class, $account]);

        $document = $documents->upload($account, $vehicle, DocumentKind::from($request->string('kind')->toString()), $request->upload(), $request->meta());

        return (new DocumentResource($document))->response()->setStatusCode(201);
    }

    /**
     * Show a document
     */
    public function show(Document $document): DocumentResource
    {
        Gate::authorize('view', $document);

        return new DocumentResource($document);
    }

    /**
     * Delete a document
     *
     * `document:delete`. The file is removed after the row's deletion commits.
     */
    public function destroy(Document $document, ManageDocuments $documents): Response
    {
        Gate::authorize('delete', $document);
        $documents->delete($document);

        return response()->noContent();
    }

    /**
     * Download link
     *
     * A short-lived (60 s) URL to the file, issued after the policy check.
     * 409 for a record with no file (imported paper records).
     */
    public function download(Document $document, DocumentStorage $storage): JsonResponse
    {
        Gate::authorize('view', $document);
        if (! $document->hasFile()) {
            throw new ConflictException('This document has no file on record.');
        }

        $link = $storage->temporaryUrl($document);

        return new JsonResponse(['data' => ['url' => $link['url'], 'expires_at' => $link['expires_at']->toIso8601ZuluString()]]);
    }
}
