<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\CustomerAccount;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A portal user reaches documents filed under their account only (never a
 * previous owner's papers for a vehicle they now own); anything else is 404.
 */
final class DocumentPolicy extends TenantPolicy
{
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, Document $document): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($document->customer_account_id)));
    }

    public function create(User $user, CustomerAccount $account): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($account->id)),
            $this->capability(Capability::DocumentUpload),
        );
    }

    public function delete(User $user, Document $document): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($document->customer_account_id)),
            $this->capability(Capability::DocumentDelete),
        );
    }
}
