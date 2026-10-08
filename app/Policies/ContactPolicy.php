<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Auth\Access\Response;

final class ContactPolicy extends TenantPolicy
{
    public function view(User $user, Contact $contact): Response
    {
        return $this->first($this->visible($this->context()->canReachAccount($contact->customer_account_id)));
    }

    public function update(User $user, Contact $contact): Response
    {
        return $this->first(
            $this->visible($this->context()->canReachAccount($contact->customer_account_id)),
            $this->capability(Capability::CustomerManage),
        );
    }

    public function delete(User $user, Contact $contact): Response
    {
        return $this->update($user, $contact);
    }
}
