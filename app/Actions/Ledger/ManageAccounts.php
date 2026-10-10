<?php

declare(strict_types=1);

namespace App\Actions\Ledger;

use App\Actions\Audit\AuditTrail;
use App\Database\Cell;
use App\Domain\Ledger\AccountType;
use App\Domain\Ledger\Side;
use App\Exceptions\ConflictException;
use App\Models\Account;
use App\Models\AccountExportMapping;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\PostingRule;
use App\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The chart is the organization's to shape (R3: one transaction, locked, audited).
 * Add accounts, rename them, deactivate those unused. Once an account has been
 * posted to, its code, type and side are fixed (a trigger holds that too); a
 * posting rule's account cannot be deactivated from under it.
 */
final class ManageAccounts
{
    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly PostingRules $rules,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * @param  array{code: string, name: string, type: string, normal_side?: string|null, description?: string|null}  $data  validated
     */
    public function create(array $data): Account
    {
        return DB::transaction(function () use ($data): Account {
            // The chart exists before anything is added to it.
            $this->rules->map($this->tenancy->require()->organizationId());
            if (Account::query()->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => "Account code {$data['code']} is already in the chart."]);
            }
            $type = AccountType::from($data['type']);
            $account = new Account;
            $account->forceFill([
                'code' => $data['code'],
                'name' => $data['name'],
                'type' => $type,
                'normal_side' => isset($data['normal_side']) ? Side::from($data['normal_side']) : $type->defaultSide(),
                'is_system' => false,
                'position' => Cell::int(Account::query()->max('position')) + 10,
                'description' => $data['description'] ?? '',
            ])->save();
            $this->audit->record($account, 'created', null, AuditTrail::snapshot($account));

            return $account;
        });
    }

    /**
     * @param  array{code?: string, name?: string, type?: string, normal_side?: string, description?: string|null, is_active?: bool}  $data  validated
     */
    public function update(Account $account, array $data): Account
    {
        return DB::transaction(function () use ($account, $data): Account {
            $locked = Account::query()->lockForUpdate()->findOrFail($account->id);
            $before = AuditTrail::snapshot($locked);

            $fixed = ['code', 'type', 'normal_side'];
            $changesFixed = false;
            foreach ($fixed as $field) {
                if (array_key_exists($field, $data) && (string) $data[$field] !== ($locked->{$field} instanceof \BackedEnum ? (string) $locked->{$field}->value : (string) $locked->{$field})) {
                    $changesFixed = true;
                }
            }
            if ($changesFixed && JournalLine::query()->where('account_id', $locked->id)->exists()) {
                throw new ConflictException("{$locked->name} has been posted to; its code, type and side are fixed. Rename it or add a new account instead.", ['reason' => 'account_posted']);
            }
            if ($changesFixed && $locked->is_system) {
                throw new ConflictException("{$locked->name} is part of the standard chart; its code, type and side are fixed.", ['reason' => 'account_system']);
            }
            if (isset($data['code']) && $data['code'] !== $locked->code && Account::query()->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => "Account code {$data['code']} is already in the chart."]);
            }
            if (($data['is_active'] ?? true) === false && PostingRule::query()->where('account_id', $locked->id)->exists()) {
                throw new ConflictException("{$locked->name} is where a posting rule posts; point the rule at another account first.", ['reason' => 'account_in_use_by_rule']);
            }

            $locked->forceFill(array_filter([
                'code' => $data['code'] ?? null,
                'name' => $data['name'] ?? null,
                'type' => isset($data['type']) ? AccountType::from($data['type']) : null,
                'normal_side' => isset($data['normal_side']) ? Side::from($data['normal_side']) : null,
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? null,
            ], fn (mixed $value): bool => $value !== null))->save();
            $this->audit->record($locked, 'updated', $before, AuditTrail::snapshot($locked));

            return $locked;
        });
    }

    /**
     * The code (Xero) or name (QuickBooks Online) the accountant's books use for each account.
     *
     * @param  list<array{account_id: string, target: string, external_code?: string|null, external_name?: string|null}>  $rows  validated
     * @return list<AccountExportMapping>
     */
    public function mapForExport(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            $saved = [];
            foreach ($rows as $index => $row) {
                if (! Account::query()->whereKey($row['account_id'])->exists()) {
                    throw ValidationException::withMessages(["mappings.{$index}.account_id" => 'Unknown account.']);
                }
                $code = trim((string) ($row['external_code'] ?? ''));
                $name = trim((string) ($row['external_name'] ?? ''));
                $mapping = AccountExportMapping::query()->where('account_id', $row['account_id'])->where('target', $row['target'])->first() ?? new AccountExportMapping;
                if ($code === '' && $name === '') {
                    if ($mapping->exists) {
                        $mapping->delete();
                    }

                    continue;
                }
                $before = $mapping->exists ? AuditTrail::snapshot($mapping) : null;
                $mapping->forceFill([
                    'account_id' => $row['account_id'],
                    'target' => $row['target'],
                    'external_code' => $code === '' ? null : $code,
                    'external_name' => $name === '' ? null : $name,
                ])->save();
                $this->audit->record($mapping, $before === null ? 'mapped' : 'remapped', $before, AuditTrail::snapshot($mapping));
                $saved[] = $mapping;
            }

            return $saved;
        });
    }

    public function setAccountingTarget(string $target): Organization
    {
        return DB::transaction(function () use ($target): Organization {
            $organization = Organization::query()->lockForUpdate()->findOrFail($this->tenancy->require()->organizationId());
            $before = ['accounting_target' => $organization->accounting_target];
            $organization->forceFill(['accounting_target' => $target])->save();
            $this->audit->record($organization, 'accounting_target_changed', $before, ['accounting_target' => $target]);

            return $organization;
        });
    }
}
