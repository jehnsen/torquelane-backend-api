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
        // Phase 2: the fleet.
        'vehicles' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'vehicle_ownerships' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'meter_readings' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'vehicle' => 'vehicle_id'],
        'maintenance_states' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'vehicle' => 'vehicle_id'],
        'service_tasks' => ['org' => 'organization_id', 'account' => null, 'branch' => null],
        'documents' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'alert_interactions' => ['org' => 'organization_id', 'account' => null, 'branch' => null],
        // Phase 3: repair. A work order's children belong to their order.
        'approval_settings' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'work_orders' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => 'branch_id'],
        'work_order_lines' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'work_order' => 'work_order_id'],
        'work_order_tasks' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'work_order' => 'work_order_id'],
        'work_order_parts' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'work_order' => 'work_order_id'],
        'work_order_events' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'work_order' => 'work_order_id'],
        'approval_log' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'work_order' => 'work_order_id'],
        // Phase 4
        'vendors' => ['org' => 'organization_id', 'account' => null, 'branch' => null],
        'fleet_parts' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'fleet_part_usages' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'fleet_part' => 'fleet_part_id'],
        'purchase_orders' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'purchase_order_lines' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => null],
        'purchase_order_events' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'purchase_order' => 'purchase_order_id'],
        // Phase 6: the shop's stock room. Branch-owned and staff-only; a transfer belongs to both its branches.
        'items' => ['org' => 'organization_id', 'account' => null, 'branch' => null],
        'item_branch_settings' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'stock_locations' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'stock_balances' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'stock_moves' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'shop_purchase_orders' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'shop_purchase_order_lines' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'shop_purchase_order' => 'shop_purchase_order_id'],
        'shop_purchase_order_events' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'shop_purchase_order' => 'shop_purchase_order_id'],
        'goods_receipts' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'goods_receipt_lines' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'goods_receipt' => 'goods_receipt_id'],
        'stock_counts' => ['org' => 'organization_id', 'account' => null, 'branch' => 'branch_id'],
        'stock_count_lines' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'stock_count' => 'stock_count_id'],
        'stock_transfers' => ['org' => 'organization_id', 'account' => null, 'branch' => 'from_branch_id', 'branch2' => 'to_branch_id'],
        'stock_transfer_lines' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'stock_transfer' => 'stock_transfer_id'],
        // Phase 7: order-to-cash. Invoices and payments belong to their account AND their branch.
        'invoices' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => 'branch_id'],
        'invoice_lines' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'invoice' => 'invoice_id'],
        'invoice_work_orders' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'invoice' => 'invoice_id'],
        'payments' => ['org' => 'organization_id', 'account' => 'customer_account_id', 'branch' => 'branch_id'],
        'payment_allocations' => ['org' => 'organization_id', 'account' => null, 'branch' => null, 'payment' => 'payment_id'],
    ];

    /**
     * Tables that are organization-wide but account-scoped: a portal user may
     * see their own account's rows only. A vehicle's readings and maintenance
     * state belong to the vehicle's CURRENT owner (service history stays with
     * the vehicle); documents to the account they were filed under; a work
     * order (and everything on it) to the account it was raised for; a spare
     * part, a purchase order and their children to the account that owns
     * them. Vendors are the provider's own list: staff-only.
     */
    private const array ACCOUNT_OWNED = [
        'customer_accounts', 'contacts', 'consents', 'users', 'invitations', 'vehicles', 'meter_readings', 'maintenance_states', 'documents',
        'work_orders', 'work_order_lines', 'work_order_tasks', 'work_order_parts', 'work_order_events', 'approval_log',
        'fleet_parts', 'fleet_part_usages', 'purchase_orders', 'purchase_order_lines', 'purchase_order_events',
        'invoices', 'invoice_lines', 'invoice_work_orders', 'payments', 'payment_allocations',
    ];

    /** Organization-wide records a portal user may see (the catalogue they are measured against). */
    private const array ORGANIZATION_WIDE = ['organizations', 'service_tasks'];

    /** Child tables whose owner is their parent row's (column alias → parent). */
    private const array PARENTS = ['vehicle', 'work_order', 'fleet_part', 'purchase_order', 'shop_purchase_order', 'goods_receipt', 'stock_count', 'stock_transfer', 'invoice', 'payment'];

    /** @var array<string, array{org: string, account: string|null, branch: string|null, branches: list<string>, table: string, parents: array<string, string>}> */
    private array $rows = [];

    public function __construct(public readonly World $world)
    {
        foreach (self::TABLES as $table => $columns) {
            $select = array_values(array_filter([
                'id',
                $columns['org'].' as org',
                $columns['account'] === null ? null : $columns['account'].' as account',
                $columns['branch'] === null ? null : $columns['branch'].' as branch',
                isset($columns['branch2']) ? $columns['branch2'].' as branch2' : null,
            ]));
            foreach (self::PARENTS as $parent) {
                if (isset($columns[$parent])) {
                    $select[] = $columns[$parent].' as '.$parent;
                }
            }
            foreach (DB::table($table)->select($select)->get() as $row) {
                $parents = [];
                foreach (self::PARENTS as $parent) {
                    if (isset($row->{$parent})) {
                        $parents[$parent] = (string) $row->{$parent};
                    }
                }
                $this->rows[(string) $row->id] = [
                    'org' => (string) $row->org,
                    'account' => isset($row->account) ? (string) $row->account : null,
                    'branch' => isset($row->branch) ? (string) $row->branch : null,
                    // Every branch the record belongs to (a transfer has two): visible if the caller may see any.
                    'branches' => array_values(array_filter([isset($row->branch) ? (string) $row->branch : null, isset($row->branch2) ? (string) $row->branch2 : null])),
                    'table' => $table,
                    'parents' => $parents,
                ];
            }
        }

        // Vehicle-owned rows belong to the vehicle's current owner; a work
        // order's children to the order's account and branch; a part's or a
        // purchase order's children to its account.
        foreach ($this->rows as $id => $row) {
            foreach ($row['parents'] as $parentId) {
                $this->rows[$id]['account'] = $this->rows[$parentId]['account'] ?? null;
                $this->rows[$id]['branch'] = $this->rows[$parentId]['branch'] ?? $row['branch'];
                $this->rows[$id]['branches'] = $this->rows[$parentId]['branches'] ?? $row['branches'];
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
            if (in_array($row['table'], self::ORGANIZATION_WIDE, true)) {
                return 'visible';
            }

            return 'staff-only';
        }

        if ($allowedBranches !== null && $row['branches'] !== [] && array_intersect($row['branches'], $allowedBranches) === []) {
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
            $key = $row['org'].'|'.($row['account'] ?? '-').'|'.($row['branches'] === [] ? '-' : implode('+', $row['branches']));
            $buckets[$key] ??= $id;
        }

        return array_values($buckets);
    }
}
