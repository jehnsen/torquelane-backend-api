<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Consents\RecordConsent;
use App\Actions\Directory\DirectoryQueries;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\RecordConsentRequest;
use App\Http\Resources\ConsentCollection;
use App\Http\Resources\ConsentResource;
use App\Http\Resources\ConsentStateResource;
use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The append-only consent ledger. There is no update or delete: withdrawing
 * consent is a new `granted: false` decision.
 */
final class ConsentController
{
    /**
     * Consent history
     *
     * Every decision, newest first (cursor-paginated).
     */
    public function index(PaginatedRequest $request, CustomerAccount $customerAccount, DirectoryQueries $queries): ConsentCollection
    {
        Gate::authorize('view', $customerAccount);

        return new ConsentCollection($queries->consentHistory($customerAccount, $request->perPage()));
    }

    /**
     * Current consent
     *
     * The latest decision per purpose, for the account and for each contact.
     */
    public function current(CustomerAccount $customerAccount, DirectoryQueries $queries): ConsentStateResource
    {
        Gate::authorize('view', $customerAccount);

        return new ConsentStateResource($queries->consentCurrent($customerAccount));
    }

    /**
     * Record a consent decision
     *
     * `customer:manage`. Staff record how the customer told them (in person,
     * paper form, email, SMS, phone); a portal user records their own through
     * the `portal` channel. Allowed on a suspended account.
     */
    public function store(RecordConsentRequest $request, CustomerAccount $customerAccount, RecordConsent $record): JsonResponse
    {
        Gate::authorize('update', $customerAccount);

        return (new ConsentResource($record->handle($customerAccount, $request->decision())))->response()->setStatusCode(201);
    }
}
