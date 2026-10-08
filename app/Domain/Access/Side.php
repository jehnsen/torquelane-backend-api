<?php

declare(strict_types=1);

namespace App\Domain\Access;

/**
 * Which side of the tenancy boundary a user sits on.
 *
 * Staff work for the organization (the frontend's "provider side") and see
 * every customer account beneath it. Portal users belong to exactly one
 * customer account (the frontend's "client side") and never see a sibling.
 */
enum Side: string
{
    case Staff = 'staff';
    case Portal = 'portal';
}
