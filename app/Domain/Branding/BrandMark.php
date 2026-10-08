<?php

declare(strict_types=1);

namespace App\Domain\Branding;

/**
 * One level's own branding as stored: the organization's, a branch's
 * overrides, or a customer account's portal branding. Null means "not set
 * here", which falls through to the level above.
 */
final readonly class BrandMark
{
    /**
     * @param  array<string, string>|null  $themeTokens
     */
    public function __construct(
        public ?string $displayName,
        public ?string $logoUrl,
        public ?string $brandColor,
        public ?string $supportEmail = null,
        public ?array $themeTokens = null,
    ) {}
}
