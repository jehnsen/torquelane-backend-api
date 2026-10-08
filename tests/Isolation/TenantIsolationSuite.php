<?php

declare(strict_types=1);

namespace Tests\Isolation;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use ReflectionNamedType;
use Tests\Support\World;

/**
 * Generates the isolation probes from the route list, so a new GET route is
 * covered the moment it is declared in coverage.php: every `{param}` is
 * filled, in turn, with a sample of every row of its bound model, one per
 * ownership bucket (each organization × each customer account × each
 * branch), so every probe set includes the caller's own records, a sibling
 * account's, a staff-side record, another branch's and another
 * organization's.
 *
 * For each response it asserts:
 *  1. never a 5xx;
 *  2. a path naming a record the caller must not reach (another organization,
 *     another customer account for portal users, a branch outside a pinned
 *     staff member's branches) is 404, indistinguishable from a missing one;
 *     a staff-only record of the caller's own organization requested by a
 *     portal user is 403 or 404;
 *  3. the body contains no id belonging to another organization or, for a
 *     portal user, to another customer account or the staff side.
 */
final class TenantIsolationSuite
{
    /** Tables whose rows can surface in a response; owner columns per table. */
    private const array TABLES = [
        'organizations' => ['org' => 'id', 'account' => null, 'branch' => null],
        'branches' => ['org' => 'organization_id', 'account' => null, 'branch' => 'id'],
        'customer_accounts' => ['org' => 'organization_id', 'account' => 'id', 'branch' => null],
        'contacts' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'consents' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'users' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'invitations' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'bays' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'technicians' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'organization_modules' => ['org' => 'organization_id', 'account' => null, 'branch' => null],
        'branch_modules' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'document_series' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'audit_logs' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => 'branch_id'],
    ];

    /** Tables that are organization-wide but account-scoped: a portal user may see their own account's rows only. */
    private const array ACCOUNT_OWNED = ['customer_accounts', 'contacts', 'consents', 'users', 'invitations'];

    /** @var array<string, array{org: string, account: string|null, branch: string|null, table: string}> */
    private array $rows = [];

    public function __construct(public readonly World $world)
    {
        foreach (self::TABLES as $table => $columns) {
            $select = array_values(array_filter([
                'id',
                $columns['org'].' as org',
                $columns['account'] === null ? null : $columns['account'].' as account',
                $columns['branch'] === null ? null : $columns['branch'].' as branch',
            ]));
            foreach (DB::table($table)->select($select)->get() as $row) {
                $this->rows[(string) $row->id] = [
                    'org' => (string) $row->org,
                    'account' => isset($row->account) ? (string) $row->account : null,
                    'branch' => isset($row->branch) ? (string) $row->branch : null,
                    'table' => $table,
                ];
            }
        }
    }

    /**
     * Every probe: one request per combination of sampled parameters, for
     * every GET route coverage.php marks as isolation-tested.
     *
     * @return list<array{route: string, uri: string, params: list<string>}>
     */
    public function probes(): array
    {
        /** @var array<string, array<string, string>> $coverage */
        $coverage = require __DIR__.'/coverage.php';

        $probes = [];
        foreach (Router::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! in_array('GET', $route->methods(), true) || ! isset($coverage[$uri]['isolation'])) {
                continue;
            }

            $combinations = [[]];
            foreach ($this->boundModels($route) as $name => $table) {
                $next = [];
                foreach ($combinations as $combination) {
                    foreach ($this->sample($table) as $id) {
                        $next[] = $combination + [$name => $id];
                    }
                }
                $combinations = $next;
            }

            foreach ($combinations as $params) {
                $path = $uri;
                foreach ($params as $name => $id) {
                    $path = str_replace('{'.$name.'}', $id, $path);
                }
                $probes[] = ['route' => $uri, 'uri' => '/'.$path, 'params' => array_values($params)];
            }
        }

        return $probes;
    }

    /**
     * @param  array{route: string, uri: string, params: list<string>}  $probe
     * @param  list<string>|null  $allowedBranches  null = unrestricted (or portal)
     * @param  TestResponse<JsonResponse>  $response
     */
    public function assertProbe(User $user, ?array $allowedBranches, array $probe, TestResponse $response): void
    {
        $where = "{$user->email} GET {$probe['uri']}";
        $status = $response->getStatusCode();

        expect($status)->toBeLessThan(500, "{$where} answered {$status}: ".$response->getContent());

        // A session refused by the tenant middleware (suspended account, disabled
        // user…) gets the same 403 for every path, before any id is looked up,
        // so it cannot tell existing records from missing ones.
        $deniedBeforeLookup = $status === 403 && $response->json('error.details.reason') !== null;

        foreach ($deniedBeforeLookup ? [] : $probe['params'] as $id) {
            $verdict = $this->verdict($user, $allowedBranches, $id);
            if ($verdict === 'hidden') {
                expect($status)->toBe(404, "{$where}: {$id} is out of scope, so the record must look missing");
            } elseif ($verdict === 'staff-only') {
                expect($status)->toBeIn([403, 404], "{$where}: {$id} is staff-only");
            }
        }

        $leaked = array_values(array_filter(
            $this->idsIn((string) $response->getContent()),
            fn (string $id): bool => in_array($this->verdict($user, null, $id), ['hidden', 'staff-only'], true),
        ));

        expect($leaked)->toBe([], "{$where} leaked ids it must not see: ".implode(', ', array_map(
            fn (string $id): string => $id.' ('.$this->rows[$id]['table'].')',
            $leaked,
        )));
    }

    /**
     * How a record relates to the caller: visible, hidden (must 404), or
     * staff-only (a portal user's 403/404).
     *
     * @param  list<string>|null  $allowedBranches
     */
    public function verdict(User $user, ?array $allowedBranches, string $id): string
    {
        $row = $this->rows[$id] ?? throw new LogicException("Unknown id {$id}.");

        if ($row['org'] !== $user->organization_id) {
            return 'hidden';
        }

        if ($user->customer_account_id !== null) {
            if (in_array($row['table'], self::ACCOUNT_OWNED, true)) {
                return $row['account'] === $user->customer_account_id ? 'visible' : 'hidden';
            }
            if ($row['table'] === 'organizations') {
                return 'visible';
            }

            return 'staff-only';
        }

        if ($allowedBranches !== null && $row['branch'] !== null && ! in_array($row['branch'], $allowedBranches, true)) {
            return 'hidden';
        }

        return 'visible';
    }

    /**
     * Known ids (any table) appearing as JSON strings or keys in a body.
     *
     * @return list<string>
     */
    private function idsIn(string $body): array
    {
        preg_match_all('/"([0-9a-hjkmnp-tv-z]{26})"/', $body, $matches);

        return array_values(array_unique(array_filter($matches[1], fn (string $id): bool => isset($this->rows[$id]))));
    }

    /**
     * Route parameter → the table of the model it binds.
     *
     * @return array<string, string>
     */
    private function boundModels(Route $route): array
    {
        $bound = [];
        foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }
            $class = $type->getName();
            if (! is_subclass_of($class, Model::class)) {
                continue;
            }
            $name = Str::snake($parameter->getName());
            if (! in_array($name, $route->parameterNames(), true)) {
                throw new LogicException("Route {$route->uri()} binds {$class} to an undeclared parameter {$name}.");
            }
            $bound[$name] = (new $class)->getTable();
        }

        if (count($bound) !== count($route->parameterNames())) {
            throw new LogicException("Route {$route->uri()} has a parameter the isolation suite cannot fill.");
        }

        return $bound;
    }

    /**
     * One id per ownership bucket (organization × account × branch).
     *
     * @return list<string>
     */
    private function sample(string $table): array
    {
        $buckets = [];
        foreach ($this->rows as $id => $row) {
            if ($row['table'] !== $table) {
                continue;
            }
            $key = $row['org'].'|'.($row['account'] ?? '-').'|'.($row['branch'] ?? '-');
            $buckets[$key] ??= $id;
        }

        return array_values($buckets);
    }
}
