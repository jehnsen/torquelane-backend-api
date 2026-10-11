<?php

declare(strict_types=1);

namespace App\Domain\Crm;

use App\Domain\Shared\Calendar;
use DateTimeImmutable;

/**
 * A segment's definition: every condition given must hold (AND); a list
 * condition holds when the customer matches ANY of its values. An empty
 * filter matches every customer.
 *
 *  - spend: total paid, at least / at most;
 *  - last visit: within N days, or longer ago than N days (a customer never
 *    served is "longer ago than" anything, and "within" nothing);
 *  - vehicle make, vehicle size class;
 *  - membership tier (`none` = no active membership);
 *  - branch served at; account type; tag.
 */
final readonly class SegmentFilter
{
    public const string NO_TIER = 'none';

    /**
     * @param  list<string>  $makes  lower-cased
     * @param  list<string>  $sizeClasses
     * @param  list<string>  $tierIds
     * @param  list<string>  $branchIds
     * @param  list<string>  $tags
     */
    public function __construct(
        public ?int $minSpendCents = null,
        public ?int $maxSpendCents = null,
        public ?int $visitedWithinDays = null,
        public ?int $notVisitedForDays = null,
        public array $makes = [],
        public array $sizeClasses = [],
        public array $tierIds = [],
        public array $branchIds = [],
        public ?string $accountType = null,
        public array $tags = [],
    ) {}

    /**
     * @param  array<array-key, mixed>  $raw  as stored (`crm_segments.filters`) or validated from a request
     */
    public static function fromArray(array $raw): self
    {
        $int = fn (string $key): ?int => isset($raw[$key]) && is_numeric($raw[$key]) ? (int) $raw[$key] : null;
        $list = function (string $key) use ($raw): array {
            $values = [];
            foreach (is_array($raw[$key] ?? null) ? $raw[$key] : [] as $value) {
                if (is_string($value) && trim($value) !== '') {
                    $values[] = trim($value);
                }
            }

            return array_values(array_unique($values));
        };
        $type = $raw['account_type'] ?? null;

        return new self(
            $int('min_spend_cents'),
            $int('max_spend_cents'),
            $int('visited_within_days'),
            $int('not_visited_for_days'),
            array_map(mb_strtolower(...), $list('makes')),
            $list('size_classes'),
            $list('tier_ids'),
            $list('branch_ids'),
            is_string($type) && $type !== '' ? $type : null,
            array_map(mb_strtolower(...), $list('tags')),
        );
    }

    /**
     * @return array<string, mixed> only the conditions that are set
     */
    public function toArray(): array
    {
        return array_filter([
            'min_spend_cents' => $this->minSpendCents,
            'max_spend_cents' => $this->maxSpendCents,
            'visited_within_days' => $this->visitedWithinDays,
            'not_visited_for_days' => $this->notVisitedForDays,
            'makes' => $this->makes,
            'size_classes' => $this->sizeClasses,
            'tier_ids' => $this->tierIds,
            'branch_ids' => $this->branchIds,
            'account_type' => $this->accountType,
            'tags' => $this->tags,
        ], fn (mixed $value): bool => $value !== null && $value !== []);
    }

    public function matches(AccountProfile $account, DateTimeImmutable $today): bool
    {
        if ($this->minSpendCents !== null && $account->spendCents < $this->minSpendCents) {
            return false;
        }
        if ($this->maxSpendCents !== null && $account->spendCents > $this->maxSpendCents) {
            return false;
        }
        $daysSince = $account->lastVisitOn === null ? null : Calendar::differenceInCalendarDays($today, Calendar::parseDate($account->lastVisitOn));
        if ($this->visitedWithinDays !== null && ($daysSince === null || $daysSince > $this->visitedWithinDays)) {
            return false;
        }
        if ($this->notVisitedForDays !== null && $daysSince !== null && $daysSince < $this->notVisitedForDays) {
            return false;
        }
        if ($this->makes !== [] && array_intersect($this->makes, $account->makes) === []) {
            return false;
        }
        if ($this->sizeClasses !== [] && array_intersect($this->sizeClasses, $account->sizeClasses) === []) {
            return false;
        }
        if ($this->tierIds !== [] && ! in_array($account->tierId ?? self::NO_TIER, $this->tierIds, true)) {
            return false;
        }
        if ($this->branchIds !== [] && array_intersect($this->branchIds, $account->branchIds) === []) {
            return false;
        }
        if ($this->accountType !== null && $account->accountType !== $this->accountType) {
            return false;
        }
        if ($this->tags !== [] && array_intersect($this->tags, array_map(mb_strtolower(...), $account->tags)) === []) {
            return false;
        }

        return true;
    }
}
