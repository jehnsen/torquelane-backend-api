<?php

declare(strict_types=1);

namespace App\Domain\Inventory;

use DomainException;

/** A move the branch's policy refuses because it would leave the balance negative. */
final class InsufficientStock extends DomainException
{
    public function __construct(public readonly string $onHand, public readonly string $requested)
    {
        parent::__construct(sprintf('Only %s on hand; %s requested.', $onHand, $requested));
    }
}
