<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\ManageDocuments;
use App\Actions\Fleet\FleetQueries;
use App\Actions\WorkOrders\WorkOrderQueries;
use App\Documents\DocumentStorage;
use App\Domain\Documents\DocumentKind;
use App\Exceptions\ConflictException;
use App\Http\Requests\ListDocumentsRequest;
use App\Http\Requests\UploadDocumentRequest;
use App\Http\Resources\DocumentCollection;
use App\Http\Resources\DocumentResource;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\Vehicle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Documents. Staff see every document; a portal user the ones filed under
 * their account (never a previous owner's papers for a vehicle they now own).
 */
final class DocumentController
{
    /**
     * List documents
     *
     * Newest upload first, or `sort=expiry` (soonest first, undated last).
     * Filter by `vehicle_id`, `customer_account_id`, `kind`,
     * `expiring_within` days, expiry `status` (expired, expiring within 30
     * days, ok) and `q` (name, notes, uploader, plate).
     */
    public function index(ListDocumentsRequest $request, FleetQueries $fleet): DocumentCollection
    {
        Gate::authorize('viewAny', Document::class);

        return new DocumentCollection($fleet->documentPage($request->filters(), $request->perPage()));
    }

    /**
     * Document totals
     *
     * The documents screen's tiles under the list's filters: how many, their
     * total size in bytes, and how many expire within the 45-day warning
     * window (already expired included).
     */
    public function summary(ListDocumentsRequest $request, FleetQueries $fleet): JsonResponse
    {
        Gate::authorize('viewAny', Document::class);

        return new JsonResponse(['data' => $fleet->documentSummary($request->filters())]);
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
    public function store(UploadDocumentRequest $request, FleetQueries $fleet, ManageDocuments $documents, WorkOrderQueries $orders): JsonResponse
    {
        if ($request->filled('work_order_id')) {
            return $this->attach($request, $orders, $documents);
        }

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
     * A document attached to a work order is filed under the order's stamped
     * account and its vehicle (the order's, even if the vehicle has since
     * changed hands), never the vehicle's current owner.
     */
    private function attach(UploadDocumentRequest $request, WorkOrderQueries $orders, ManageDocuments $documents): JsonResponse
    {
        $order = $orders->orders()->findOrFail($request->string('work_order_id')->lower()->toString());
        Gate::authorize('view', $order);
        if ($request->filled('vehicle_id') && $request->string('vehicle_id')->lower()->toString() !== $order->vehicle_id) {
            throw ValidationException::withMessages(['vehicle_id' => 'A work order\'s document belongs to the order\'s vehicle.']);
        }
        $account = CustomerAccount::query()->findOrFail($order->customer_account_id);
        Gate::authorize('create', [Document::class, $account]);

        $document = $documents->upload($account, Vehicle::query()->findOrFail($order->vehicle_id), DocumentKind::from($request->string('kind')->toString()), $request->upload(), $request->meta(), $order);

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
