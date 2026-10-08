<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

/**
 * Why a session resolved to no tenant context. Logged on every denial and
 * returned as `error.details.reason`, so a fail-closed refusal is diagnosable
 * rather than looking like data loss.
 *
 * Ported from ../web/lib/tenancy.ts `ScopeDenial`, renamed into the API's
 * vocabulary (provider → organization, fleet client → customer account). The
 * golden test maps the old names onto these one for one. The last four are
 * new in the API.
 */
enum ScopeDenial: string
{
    case NoSession = 'no_session';                         // no_session
    case NoOrganization = 'no_organization';               // no_provider
    case UnknownOrganization = 'unknown_organization';     // unknown_provider
    case UnknownAccount = 'unknown_account';               // unknown_client
    case OrganizationMismatch = 'organization_mismatch';   // provider_mismatch
    case AccountSuspended = 'account_suspended';           // client_suspended
    case RoleSideMismatch = 'role_side_mismatch';          // role_side_mismatch
    // ------------------------------------------------------------- API only
    case UnknownRole = 'unknown_role';
    case OrganizationSuspended = 'organization_suspended';
    case UserDisabled = 'user_disabled';
    case BranchNotAllowed = 'branch_not_allowed';

    /** The frontend's name for a ported reason; null for the API-only ones. */
    public function webName(): ?string
    {
        return match ($this) {
            self::NoSession => 'no_session',
            self::NoOrganization => 'no_provider',
            self::UnknownOrganization => 'unknown_provider',
            self::UnknownAccount => 'unknown_client',
            self::OrganizationMismatch => 'provider_mismatch',
            self::AccountSuspended => 'client_suspended',
            self::RoleSideMismatch => 'role_side_mismatch',
            default => null,
        };
    }

    public function message(): string
    {
        return match ($this) {
            self::NoSession => 'Sign in to continue.',
            self::NoOrganization, self::UnknownOrganization => 'Your account is not attached to an organization.',
            self::UnknownAccount => 'Your account is not attached to a customer account.',
            self::OrganizationMismatch => 'Your customer account belongs to a different organization.',
            self::AccountSuspended => 'This customer account is suspended.',
            self::RoleSideMismatch => 'Your role does not match the side of the organization your account is on.',
            self::UnknownRole => 'Your account has an unrecognised role.',
            self::OrganizationSuspended => 'This organization is suspended.',
            self::UserDisabled => 'This account is disabled.',
            self::BranchNotAllowed => 'You do not have access to that branch.',
        };
    }
}
