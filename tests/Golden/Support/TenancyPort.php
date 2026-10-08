<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use App\Domain\Access\Role;
use App\Domain\Access\Side;
use App\Domain\Approvals\ApprovalBands;
use App\Domain\Branding\Branding;
use App\Domain\Branding\BrandMark;
use App\Domain\Tenancy\AccountFacts;
use App\Domain\Tenancy\OrganizationFacts;
use App\Domain\Tenancy\ScopeResolution;
use App\Domain\Tenancy\SessionFacts;
use App\Domain\Tenancy\TenantScope;
use App\Domain\Tenancy\TenantScopeResolver;
use LogicException;

/**
 * Calls the PHP ports with a fixture's frontend-shaped arguments and returns
 * the result in the frontend's vocabulary, so the golden test can compare it
 * with the fixture output as-is:
 *
 *   provider            ↔ organization        {kind: provider}  ↔ staff scope
 *   fleet client        ↔ customer account    {kind: client}    ↔ portal scope
 *   "provider:<id>" key ↔ "organization:<id>" "client:<id>"      ↔ "account:<id>"
 *   ScopeDenial::webName() for the denial names.
 *
 * A session's side is the frontend's own rule: provider side when no fleet
 * client is pinned, client side otherwise.
 */
final class TenancyPort
{
    /** Fixture functions replayed in Phase 1. */
    public const array REPLAYED = [
        'explainTenantScope', 'resolveTenantScope', 'visibleFleetClientIds', 'isProviderRole',
        'tenantScopeKey', 'scopeAccounts', 'providerBranding', 'effectiveApprovalSettings',
        'approvalSettingsForClient',
    ];

    /** Fixture functions whose subject arrives in a later phase. */
    public const array DEFERRED = [
        'scopeFleetState' => 'Scopes vehicles, work orders, documents, parts and purchase orders: replayed when those tables arrive (repair/PMS phases).',
        'alertsForScope' => 'Alert read/dismiss buckets: replayed with the alerts phase. TenantScope::key() already reproduces their keys (tenantScopeKey is replayed now).',
    ];

    public static function call(string $fn, mixed $input): mixed
    {
        if (! is_array($input)) {
            throw new LogicException("{$fn}: fixture input is not an argument list.");
        }

        return match ($fn) {
            'explainTenantScope' => self::explanation(self::explain($input)),
            'resolveTenantScope' => self::webScope(self::explain($input)->scope),
            'visibleFleetClientIds' => TenantScopeResolver::visibleAccountIds(self::scope($input[0]), self::accounts($input[1])),
            'isProviderRole' => is_string($input[0] ?? null) && (Role::tryFrom($input[0])?->isStaff() ?? false),
            'tenantScopeKey' => self::webKey(self::scope($input[0]) ?? throw new LogicException('tenantScopeKey takes a scope.')),
            'scopeAccounts' => self::directory($input[0], self::scope($input[1])),
            'providerBranding' => self::branding($input[0], self::scope($input[1])),
            'effectiveApprovalSettings' => self::effectiveSettings($input[0], self::scope($input[1])),
            'approvalSettingsForClient' => ApprovalBands::forAccount(self::overrides($input[0]), self::map($input[1])),
            default => throw new LogicException("{$fn} is not replayed in Phase 1."),
        };
    }

    /**
     * @param  array<int, mixed>  $input
     */
    private static function explain(array $input): ScopeResolution
    {
        $session = $input[0] ?? null;

        return TenantScopeResolver::explain(
            is_array($session) ? self::session($session) : null,
            array_map(fn (mixed $provider): OrganizationFacts => new OrganizationFacts(self::str(self::map($provider)['id'] ?? null)), self::list($input[1] ?? [])),
            self::accounts($input[2] ?? []),
        );
    }

    /**
     * @param  array<array-key, mixed>  $session
     */
    private static function session(array $session): SessionFacts
    {
        $client = $session['fleetClientId'] ?? null;

        return new SessionFacts(
            self::str($session['uid'] ?? null),
            is_string($session['providerId'] ?? null) ? $session['providerId'] : null,
            $client === null ? Side::Staff : Side::Portal,
            is_string($session['role'] ?? null) ? $session['role'] : null,
            is_string($client) ? $client : null,
        );
    }

    /**
     * @return list<AccountFacts>
     */
    private static function accounts(mixed $clients): array
    {
        return array_map(function (mixed $client): AccountFacts {
            $client = self::map($client);

            return new AccountFacts(self::str($client['id'] ?? null), self::str($client['providerId'] ?? null), ($client['status'] ?? null) === 'suspended');
        }, self::list($clients));
    }

    private static function scope(mixed $scope): ?TenantScope
    {
        if ($scope === null) {
            return null;
        }
        $scope = self::map($scope);

        return match ($scope['kind'] ?? null) {
            'provider' => TenantScope::staff(self::str($scope['providerId'] ?? null)),
            'client' => TenantScope::portal(self::str($scope['providerId'] ?? null), self::str($scope['fleetClientId'] ?? null)),
            default => throw new LogicException('Unknown scope kind.'),
        };
    }

    /**
     * @return array<string, string>|null
     */
    private static function webScope(?TenantScope $scope): ?array
    {
        if ($scope === null) {
            return null;
        }

        return $scope->isStaff()
            ? ['kind' => 'provider', 'providerId' => $scope->organizationId]
            : ['kind' => 'client', 'providerId' => $scope->organizationId, 'fleetClientId' => (string) $scope->customerAccountId];
    }

    /**
     * @return array{scope: array<string, string>|null, denial: string|null}
     */
    private static function explanation(ScopeResolution $resolution): array
    {
        return [
            'scope' => self::webScope($resolution->scope),
            'denial' => $resolution->denial === null ? null : ($resolution->denial->webName() ?? $resolution->denial->value),
        ];
    }

    private static function webKey(TenantScope $scope): string
    {
        $key = $scope->key();

        return str_starts_with($key, 'organization:')
            ? 'provider:'.substr($key, strlen('organization:'))
            : 'client:'.substr($key, strlen('account:'));
    }

    /**
     * @return list<mixed>
     */
    private static function directory(mixed $accounts, ?TenantScope $scope): array
    {
        $accounts = self::list($accounts);
        $entries = [];
        foreach ($accounts as $i => $account) {
            $account = self::map($account);
            $entries[] = [
                'organization_id' => $account['providerId'] ?? null,
                'customer_account_id' => $account['fleetClientId'] ?? null,
                'index' => $i,
            ];
        }

        return array_map(fn (array $entry): mixed => $accounts[$entry['index']], TenantScopeResolver::directory($scope, $entries));
    }

    /**
     * @return array{displayName: string, logoUrl: string|null, brandColor: string|null, supportEmail: string|null}
     */
    private static function branding(mixed $state, ?TenantScope $scope): array
    {
        $state = self::map($state);
        $tenant = self::map($state['tenant'] ?? null);
        $fallback = new Branding(self::str($tenant['displayName'] ?? null), self::nullableStr($tenant['logoUrl'] ?? null), self::nullableStr($tenant['brandColor'] ?? null), self::nullableStr($tenant['supportEmail'] ?? null));

        $organization = $scope === null ? null : self::findById($state['providers'] ?? [], $scope->organizationId);
        $account = $scope?->customerAccountId === null ? null : self::findById($state['fleetClients'] ?? [], $scope->customerAccountId);

        $branding = Branding::resolve(
            $scope,
            $organization === null ? null : new BrandMark(self::str($organization['name'] ?? null), self::nullableStr($organization['logoUrl'] ?? null), self::nullableStr($organization['brandColor'] ?? null), self::nullableStr($organization['supportEmail'] ?? null)),
            $account === null ? null : new BrandMark(self::str($account['name'] ?? null), self::nullableStr($account['logoUrl'] ?? null), self::nullableStr($account['brandColor'] ?? null)),
            null,
            $fallback,
        );

        return [
            'displayName' => $branding->displayName,
            'logoUrl' => $branding->logoUrl,
            'brandColor' => $branding->brandColor,
            'supportEmail' => $branding->supportEmail,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function effectiveSettings(mixed $state, ?TenantScope $scope): array
    {
        $state = self::map($state);
        $overrides = [];
        foreach (self::list($state['fleetClients'] ?? []) as $client) {
            $client = self::map($client);
            $overrides[self::str($client['id'] ?? null)] = self::overrides($client['approvalThresholdOverrides'] ?? null);
        }

        return ApprovalBands::forScope(self::map($state['approvalSettings'] ?? null), $scope, $overrides);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function overrides(mixed $client): ?array
    {
        if (is_array($client) && array_key_exists('approvalThresholdOverrides', $client)) {
            $client = $client['approvalThresholdOverrides'];
        }

        return $client === null ? null : self::map($client);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function findById(mixed $list, string $id): ?array
    {
        foreach (self::list($list) as $item) {
            $item = self::map($item);
            if (($item['id'] ?? null) === $id) {
                return $item;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function map(mixed $value): array
    {
        if (! is_array($value)) {
            throw new LogicException('Expected an object in the fixture.');
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function list(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : throw new LogicException('Expected a string in the fixture.');
    }

    private static function nullableStr(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
