<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Which branches a staff session works in, from its branch_user pivot rows
 * and the X-Branch-Id header.
 *
 *  - No pivot rows: every branch of the organization is allowed.
 *  - The header names one allowed branch, or `all`. Anything else (another
 *    organization's branch, a branch the user is not pinned to, garbage) is
 *    refused, never ignored: a silently ignored header would show a different
 *    branch's data than the caller asked for.
 *  - No header, or `all`: a user with exactly one allowed branch works in that
 *    branch; anyone else works across all their allowed branches (`null`).
 *
 * Selection is a view filter, never a widening: whatever is selected, reads
 * and writes stay inside the allowed set.
 */
final readonly class BranchSelection
{
    public const string ALL = 'all';

    /**
     * @param  list<string>  $allowedBranchIds
     */
    private function __construct(
        public array $allowedBranchIds,
        public bool $restricted,
        public ?string $selectedBranchId,
        public ?ScopeDenial $denial,
    ) {}

    /**
     * @param  list<string>  $organizationBranchIds  every branch of the session's organization
     * @param  list<string>  $pinnedBranchIds  the user's branch_user rows; empty = unrestricted
     */
    public static function resolve(array $organizationBranchIds, array $pinnedBranchIds, ?string $header): self
    {
        $restricted = $pinnedBranchIds !== [];
        $allowed = $restricted
            ? array_values(array_intersect($organizationBranchIds, $pinnedBranchIds))
            : $organizationBranchIds;

        $header = $header === null ? null : trim($header);

        if ($header !== null && $header !== '' && $header !== self::ALL) {
            return in_array($header, $allowed, true)
                ? new self($allowed, $restricted, $header, null)
                : new self($allowed, $restricted, null, ScopeDenial::BranchNotAllowed);
        }

        return new self($allowed, $restricted, count($allowed) === 1 ? $allowed[0] : null, null);
    }

    /** A portal session has no branch dimension; the header is ignored. */
    public static function none(): self
    {
        return new self([], false, null, null);
    }
}
