<?php

declare(strict_types=1);

namespace App\Domain\Ledger;

use InvalidArgumentException;

/** One line of a draft: an amount on one side of one account, in a branch. */
final readonly class PostingLine
{
    public function __construct(
        public AccountRef $account,
        public int $debitCents,
        public int $creditCents,
        public string $branchId,
        public ?string $customerAccountId = null,
        public ?string $stockMoveId = null,
        public string $memo = '',
    ) {
        if ($debitCents < 0 || $creditCents < 0) {
            throw new InvalidArgumentException('A journal line is never negative; put the amount on the other side.');
        }
        if ($debitCents > 0 && $creditCents > 0) {
            throw new InvalidArgumentException('A journal line is a debit or a credit, not both.');
        }
    }

    public static function debit(RuleKey|AccountRef $account, int $cents, string $branchId, ?string $customerAccountId = null, ?string $stockMoveId = null, string $memo = ''): self
    {
        return new self($account instanceof RuleKey ? AccountRef::rule($account) : $account, $cents, 0, $branchId, $customerAccountId, $stockMoveId, $memo);
    }

    public static function credit(RuleKey|AccountRef $account, int $cents, string $branchId, ?string $customerAccountId = null, ?string $stockMoveId = null, string $memo = ''): self
    {
        return new self($account instanceof RuleKey ? AccountRef::rule($account) : $account, 0, $cents, $branchId, $customerAccountId, $stockMoveId, $memo);
    }

    /** The same amount on the other side. */
    public function mirrored(): self
    {
        return new self($this->account, $this->creditCents, $this->debitCents, $this->branchId, $this->customerAccountId, null, $this->memo);
    }
}
