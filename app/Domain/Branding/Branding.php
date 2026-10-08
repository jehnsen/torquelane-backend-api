<?php

declare(strict_types=1);

namespace App\Domain\Branding;

use App\Domain\Tenancy\TenantScope;

/**
 * Resolved branding for a session. Port of ../web/lib/tenancy.ts
 * `providerBranding`, plus a branch level the frontend did not have.
 *
 *  - Staff see the organization's own mark (this is the shop's instance; its
 *    staff should know whose software they are in), overlaid field by field
 *    by the selected branch's overrides when exactly one branch is selected.
 *    A branch can run under its own brand (a detailing studio) without the
 *    organization changing.
 *  - Portal users see their account's name, logo and colour where set,
 *    falling back field by field to the organization's: a logo with no colour
 *    gets the organization's colour, never a broken half-theme.
 *  - Support email always stays the organization's. A customer is not its own
 *    help desk, and neither is a branch.
 */
final readonly class Branding
{
    /**
     * @param  array<string, string>|null  $themeTokens
     */
    public function __construct(
        public string $displayName,
        public ?string $logoUrl,
        public ?string $brandColor,
        public ?string $supportEmail,
        public ?array $themeTokens = null,
    ) {}

    public static function resolve(
        ?TenantScope $scope,
        ?BrandMark $organization,
        ?BrandMark $account,
        ?BrandMark $branch,
        self $fallback,
    ): self {
        if ($scope === null || $organization === null) {
            return $fallback;
        }

        $base = new self(
            $organization->displayName ?? $fallback->displayName,
            $organization->logoUrl,
            $organization->brandColor,
            $organization->supportEmail,
            $organization->themeTokens,
        );

        if ($scope->isStaff()) {
            return $branch === null ? $base : new self(
                $branch->displayName ?? $base->displayName,
                $branch->logoUrl ?? $base->logoUrl,
                $branch->brandColor ?? $base->brandColor,
                $base->supportEmail,
                $branch->themeTokens ?? $base->themeTokens,
            );
        }

        if ($account === null) {
            return $base;
        }

        return new self(
            $account->displayName ?? $base->displayName,
            $account->logoUrl ?? $base->logoUrl,
            $account->brandColor ?? $base->brandColor,
            $base->supportEmail,
            $base->themeTokens,
        );
    }
}
