<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Contacts\DeleteContact;
use App\Actions\Contacts\SaveContact;
use App\Actions\Directory\DirectoryQueries;
use App\Http\Requests\PaginatedRequest;
use App\Http\Requests\SaveContactRequest;
use App\Http\Resources\ContactCollection;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * People at a customer account. Nested routes use scoped bindings: a contact
 * id from another account is 404 even within the organization.
 */
final class ContactController
{
    /**
     * List an account's contacts
     */
    public function index(PaginatedRequest $request, CustomerAccount $customerAccount, DirectoryQueries $queries): ContactCollection
    {
        Gate::authorize('view', $customerAccount);

        return new ContactCollection($queries->contacts($customerAccount, $request->perPage()));
    }

    /**
     * Add a contact
     *
     * `customer:manage`. Marking one primary demotes the previous primary.
     */
    public function store(SaveContactRequest $request, CustomerAccount $customerAccount, SaveContact $save): JsonResponse
    {
        Gate::authorize('update', $customerAccount);

        return (new ContactResource($save->create($customerAccount, $request->validated())))->response()->setStatusCode(201);
    }

    /**
     * Show a contact
     */
    public function show(CustomerAccount $customerAccount, Contact $contact): ContactResource
    {
        Gate::authorize('view', $contact);

        return new ContactResource($contact);
    }

    /**
     * Update a contact
     */
    public function update(SaveContactRequest $request, CustomerAccount $customerAccount, Contact $contact, SaveContact $save): ContactResource
    {
        Gate::authorize('update', $contact);

        return new ContactResource($save->update($contact, $request->validated()));
    }

    /**
     * Delete a contact
     *
     * A contact with consent decisions on record cannot be deleted (409).
     */
    public function destroy(CustomerAccount $customerAccount, Contact $contact, DeleteContact $delete): Response
    {
        Gate::authorize('delete', $contact);
        $delete->handle($contact);

        return response()->noContent();
    }
}
