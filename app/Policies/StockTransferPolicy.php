<?php

declare(strict_types=1);

namespace App\Policies;

use App\Domain\Access\Capability;
use App\Models\StockTransfer;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A transfer is seen by staff of either branch it moved goods between, and
 * made (or reversed) by someone who may work in the branch the goods LEAVE:
 * the source of the transfer, or for a reversal the original's destination.
 */
final class StockTransferPolicy extends InventoryPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->reads();
    }

    public function view(User $user, StockTransfer $transfer): Response
    {
        $context = $this->context();

        return $this->first(
            $this->staffOnly(),
            $this->visible($context->branchAllowed($transfer->from_branch_id) || $context->branchAllowed($transfer->to_branch_id)),
            $this->capability(Capability::InventoryView),
        );
    }

    /** Take goods out of a location of `$fromBranchId`. */
    public function create(User $user, string $fromBranchId): Response
    {
        return $this->managesIn($fromBranchId);
    }

    public function reverse(User $user, StockTransfer $transfer): Response
    {
        return $this->managesIn($transfer->to_branch_id);
    }
}
