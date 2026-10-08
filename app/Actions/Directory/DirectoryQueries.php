<?php

declare(strict_types=1);

namespace App\Actions\Directory;

use App\Domain\Crm\ConsentDecision;
use App\Domain\Crm\ConsentLedger;
use App\Models\Bay;
use App\Models\Branch;
use App\Models\Consent;
use App\Models\Contact;
use App\Models\CustomerAccount;
use App\Models\Invitation;
use App\Models\Organization;
use App\Models\Technician;
use App\Models\User;
use App\Models\Vendor;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * The read side of the Phase 1 endpoints. Every query starts from the
 * organization scope (global) and adds the per-path restriction: portal
 * ownership (`visibleTo`) for account data, allowed/selected branches for
 * branch-owned data. Nothing here takes an organization or account id from
 * the caller.
 */
final class DirectoryQueries
{
    public function __construct(private readonly TenantManager $tenancy) {}

    /**
     * @return LengthAwarePaginator<int, Branch>
     */
    public function branches(int $perPage): LengthAwarePaginator
    {
        return Branch::query()->visibleTo($this->tenancy->require())->orderBy('name')->paginate($perPage);
    }

    /**
     * @param  array{status?: string, account_type?: string, q?: string}  $filters
     * @return LengthAwarePaginator<int, CustomerAccount>
     */
    public function customerAccounts(array $filters, int $perPage): LengthAwarePaginator
    {
        $query = CustomerAccount::query()->visibleTo($this->tenancy->require());

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['account_type'])) {
            $query->where('account_type', $filters['account_type']);
        }
        if (isset($filters['q']) && $filters['q'] !== '') {
            $query->whereLike('display_name', '%'.addcslashes($filters['q'], '%_\\').'%');
        }

        return $query->orderBy('display_name')->orderBy('id')->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Contact>
     */
    public function contacts(CustomerAccount $account, int $perPage): LengthAwarePaginator
    {
        return Contact::query()
            ->visibleTo($this->tenancy->require())
            ->where('customer_account_id', $account->id)
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * The ledger, newest first.
     *
     * @return CursorPaginator<int, Consent>
     */
    public function consentHistory(CustomerAccount $account, int $perPage): CursorPaginator
    {
        return Consent::query()
            ->visibleTo($this->tenancy->require())
            ->where('customer_account_id', $account->id)
            ->orderByDesc('id')
            ->cursorPaginate($perPage);
    }

    /**
     * Current consent per purpose, for the account itself and for each
     * contact that has decisions of its own.
     */
    public function consentCurrent(CustomerAccount $account): ConsentState
    {
        $rows = Consent::query()
            ->visibleTo($this->tenancy->require())
            ->where('customer_account_id', $account->id)
            ->get();

        $byId = $rows->keyBy('id')->all();
        $subjects = [];
        foreach ($rows as $row) {
            $subjects[$row->contact_id ?? ''][] = $row->toDecision();
        }

        $resolve = function (array $decisions) use ($byId): array {
            /** @var list<ConsentDecision> $decisions */
            return array_map(
                fn (?ConsentDecision $decision): ?Consent => $decision === null ? null : $byId[$decision->id],
                ConsentLedger::current($decisions),
            );
        };

        $contacts = [];
        foreach ($subjects as $contactId => $decisions) {
            if ($contactId !== '') {
                $contacts[(string) $contactId] = $resolve($decisions);
            }
        }

        return new ConsentState($resolve($subjects[''] ?? []), $contacts);
    }

    /**
     * @return LengthAwarePaginator<int, User>
     */
    public function users(int $perPage): LengthAwarePaginator
    {
        return User::query()
            ->visibleTo($this->tenancy->require())
            ->with('branches:id')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * Pending invitations. Portal-side callers (none hold access:manage today)
     * would see only their own account's.
     *
     * @return LengthAwarePaginator<int, Invitation>
     */
    public function invitations(int $perPage): LengthAwarePaginator
    {
        $context = $this->tenancy->require();
        $query = Invitation::query()->pending();
        if ($context->isPortal()) {
            $query->where('customer_account_id', $context->customerAccountId());
        }

        return $query->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Bay>
     */
    public function bays(int $perPage): LengthAwarePaginator
    {
        $context = $this->tenancy->require();

        return Bay::query()
            ->visibleTo($context)
            ->whereIn('branch_id', $context->branchFilter())
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * @return LengthAwarePaginator<int, Technician>
     */
    public function technicians(int $perPage): LengthAwarePaginator
    {
        $context = $this->tenancy->require();

        return Technician::query()
            ->visibleTo($context)
            ->whereIn('branch_id', $context->branchFilter())
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * The organization's vendor list, by name.
     *
     * @return LengthAwarePaginator<int, Vendor>
     */
    public function vendors(int $perPage): LengthAwarePaginator
    {
        return Vendor::query()->orderBy('name')->orderBy('id')->paginate($perPage);
    }

    /** The session's own organization; never one named by the caller. */
    public function organization(): Organization
    {
        return Organization::query()->findOrFail($this->tenancy->require()->organizationId());
    }

    public function user(User $user): User
    {
        return $user->load('branches:id');
    }
}
