<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Audit\AuditTrail;
use App\Domain\Ledger\ChartOfAccounts;
use App\Domain\Ledger\RuleKey;
use App\Models\Account;
use App\Models\PostingRule;
use App\Tenancy\TenantManager;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Where each event or category posts. The chart and a rule for every key are
 * installed the first time an organization posts (or backfills): idempotent
 * and race-safe (`insert … on conflict do nothing`), so two first postings
 * never fight. Organization admins re-point a rule afterwards; that applies
 * to postings from then on.
 */
final class PostingRules
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly AuditTrail $audit,
    ) {}

    /** The account a rule names, installing the chart first if the organization has none. */
    public function accountId(string $organizationId, RuleKey $key): string
    {
        return $this->map($organizationId)[$key->value] ?? throw new LogicException("No posting rule for {$key->value}.");
    }

    /**
     * @return array<string, string> rule key → account id
     */
    public function map(string $organizationId): array
    {
        // Read each time: a cached map could point at accounts a rolled-back transaction created.
        return $this->install($organizationId);
    }

    /**
     * Re-point rules. Each account must exist, be active, and be of the type the rule needs.
     *
     * @param  list<array{key: string, account_id: string}>  $changes
     * @return array<string, string> the rule map afterwards
     */
    public function update(array $changes): array
    {
        $organizationId = $this->tenancy->require()->organizationId();

        return DB::transaction(function () use ($changes, $organizationId): array {
            $this->map($organizationId);
            $rules = PostingRule::query()->get()->keyBy(fn (PostingRule $rule): string => $rule->rule_key->value);

            foreach ($changes as $index => $change) {
                $key = RuleKey::tryFrom($change['key']);
                $rule = $key === null ? null : $rules->get($key->value);
                if ($key === null || $rule === null) {
                    throw ValidationException::withMessages(["rules.{$index}.key" => 'Unknown posting rule.']);
                }
                $account = Account::query()->find($change['account_id']);
                if ($account === null) {
                    throw ValidationException::withMessages(["rules.{$index}.account_id" => 'Unknown account.']);
                }
                if (! $account->is_active) {
                    throw ValidationException::withMessages(["rules.{$index}.account_id" => "{$account->name} is inactive; it takes no new postings."]);
                }
                if ($account->type !== $key->accountType()) {
                    throw ValidationException::withMessages(["rules.{$index}.account_id" => sprintf('"%s" posts to %s accounts; %s is %s.', $key->label(), $key->accountType()->value, $account->name, $account->type->value)]);
                }
                if ($rule->account_id === $account->id) {
                    continue;
                }
                $before = ['rule_key' => $key->value, 'account_id' => $rule->account_id];
                $rule->forceFill(['account_id' => $account->id])->save();
                $this->audit->record($rule, 'rule_changed', $before, ['rule_key' => $key->value, 'account_id' => $account->id]);
            }

            return $this->map($organizationId);
        });
    }

    /**
     * @return array<string, string>
     */
    private function install(string $organizationId): array
    {
        $now = CarbonImmutable::now('UTC');

        if (! Account::query()->where('organization_id', $organizationId)->exists()) {
            $rows = [];
            foreach (ChartOfAccounts::defaults() as $position => $account) {
                $rows[] = [
                    'id' => strtolower((string) Str::ulid()),
                    'organization_id' => $organizationId,
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'type' => $account['type']->value,
                    'normal_side' => $account['side']->value,
                    'is_active' => true,
                    'is_system' => true,
                    'position' => ($position + 1) * 10,
                    'description' => $account['description'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            Account::query()->insertOrIgnore($rows);
        }

        $ids = Account::query()->where('organization_id', $organizationId)->pluck('id', 'code');
        /** @var array<string, string> $existing */
        $existing = PostingRule::query()->where('organization_id', $organizationId)->toBase()->pluck('account_id', 'rule_key')->all();
        $rules = [];
        foreach (RuleKey::cases() as $key) {
            if (isset($existing[$key->value])) {
                continue;
            }
            $accountId = $ids->get($key->defaultCode());
            if (! is_string($accountId)) {
                throw new LogicException("The chart has no account {$key->defaultCode()} for {$key->value}.");
            }
            $rules[] = [
                'id' => strtolower((string) Str::ulid()),
                'organization_id' => $organizationId,
                'rule_key' => $key->value,
                'account_id' => $accountId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        if ($rules !== []) {
            PostingRule::query()->insertOrIgnore($rules);
        }

        /** @var array<string, string> $map */
        $map = PostingRule::query()->where('organization_id', $organizationId)->toBase()->pluck('account_id', 'rule_key')->all();

        return $map;
    }
}
