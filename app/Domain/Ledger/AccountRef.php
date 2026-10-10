<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/**
 * An account a posting names: by role (a RuleKey, resolved through the
 * organization's posting rules when the entry is written) or by id (a
 * reversal repeats exactly the accounts the original used).
 */
final readonly class AccountRef
{
    private function __construct(public ?RuleKey $rule, public ?string $accountId)
    {
        if (($rule === null) === ($accountId === null)) {
            throw new InvalidArgumentException('An account is named by a rule or by an id, not both.');
        }
    }

    public static function rule(RuleKey $rule): self
    {
        return new self($rule, null);
    }

    public static function id(string $accountId): self
    {
        return new self(null, $accountId);
    }
}
