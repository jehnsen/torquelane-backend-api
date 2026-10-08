<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use App\Domain\Shared\Calendar;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

/**
 * Reads ../web's golden fixtures (copied into tests/Golden/fixtures/web from
 * pms-monitoring-frontend@d45871e, fixtures/golden) and resolves their value
 * encoding (fixtures README):
 *
 *  - `{"$seed": "state/fleetClients/fc-actimed"}` → that node of
 *    database/seeders/data/demo-seed.json (an array segment selects the
 *    element whose `id` equals it, else a numeric index);
 *  - `{"$health": "veh-001/items/3"}` → that node of the fleet health defined
 *    by pms.json's `sweep › evaluateFleet › whole seed fleet (defines $health)`
 *    (`*` is the whole array; the first segment selects by vehicle id);
 *  - `{"$date": "2026-10-08T10:00:00.000+08:00"}` → DateTimeImmutable (Manila);
 *  - `{"$undefined": true}` → null (the PHP ports take null for "absent");
 *  - `{"$number": "Infinity"}` → INF / -INF / NAN;
 *  - `{"$money": 1234.5, "cents": 123450}` → the number the TypeScript
 *    produced, or a WebMoney when the replay asserts in centavos.
 *
 * `$map` / `$set` do not occur in the modules replayed so far; meeting one
 * throws, so a fixture change cannot slip past unresolved.
 */
final class WebFixtures
{
    public const string FROZEN_AT = '2026-10-08T10:00:00+08:00';

    /** @var array<string, mixed>|null */
    private static ?array $seed = null;

    /** @var list<mixed>|null */
    private static ?array $health = null;

    /** @var array<string, list<array{case: string, fn: string, input: mixed, output: mixed}>> */
    private static array $raw = [];

    public static function frozenNow(): DateTimeImmutable
    {
        return Calendar::local(new DateTimeImmutable(self::FROZEN_AT));
    }

    /**
     * @return list<array{case: string, fn: string, input: mixed, output: mixed}>
     */
    public static function cases(string $module, bool $keepMoney = false): array
    {
        return array_map(fn (array $case): array => [
            'case' => $case['case'],
            'fn' => $case['fn'],
            'input' => self::resolve($case['input'], $keepMoney),
            'output' => self::resolve($case['output'], $keepMoney),
        ], self::raw($module));
    }

    /**
     * Unresolved cases (cheap to filter before resolving).
     *
     * @return list<array{case: string, fn: string, input: mixed, output: mixed}>
     */
    public static function raw(string $module): array
    {
        if (! isset(self::$raw[$module])) {
            $json = file_get_contents(dirname(__DIR__).'/fixtures/web/'.$module.'.json');
            if ($json === false) {
                throw new LogicException("Missing fixture {$module}.json");
            }
            /** @var list<array{case: string, fn: string, input: mixed, output: mixed}> $cases */
            $cases = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            self::$raw[$module] = $cases;
        }

        return self::$raw[$module];
    }

    public static function resolve(mixed $value, bool $keepMoney = false): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists('$seed', $value) && count($value) === 1) {
            return self::node(self::seed(), (string) $value['$seed'], 'seed');
        }
        if (array_key_exists('$health', $value) && count($value) === 1) {
            $path = (string) $value['$health'];

            return $path === '*' ? self::health() : self::healthNode($path);
        }
        if (array_key_exists('$date', $value)) {
            return Calendar::local(new DateTimeImmutable((string) $value['$date']));
        }
        if (array_key_exists('$undefined', $value)) {
            return null;
        }
        if (array_key_exists('$number', $value)) {
            return match ($value['$number']) {
                'Infinity' => INF,
                '-Infinity' => -INF,
                default => NAN,
            };
        }
        if (array_key_exists('$money', $value)) {
            /** @var float|int $money */
            $money = $value['$money'];

            return $keepMoney ? new WebMoney($money, (int) $value['cents'], (bool) ($value['subCentavo'] ?? false)) : $money;
        }
        foreach (['$map', '$set'] as $encoding) {
            if (array_key_exists($encoding, $value)) {
                throw new LogicException("Encoding {$encoding} is not handled yet.");
            }
        }

        return array_map(fn (mixed $item): mixed => self::resolve($item, $keepMoney), $value);
    }

    /**
     * Dates → the fixture's own string form, recursively, so outputs compare
     * exactly (instant and offset), not by DateTime equality.
     */
    public static function comparable(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return Calendar::local($value)->format('Y-m-d\TH:i:s.vP');
        }
        if ($value instanceof WebMoney) {
            return ['cents' => $value->cents];
        }

        return is_array($value) ? array_map(self::comparable(...), $value) : $value;
    }

    /**
     * A strict-comparison form: object keys sorted, and a float with no
     * fraction written as the int JavaScript would print (JS has one number
     * type; `4053` and `4053.0` are the same value there). Compare with ===:
     * loose == would let null pass for 0.
     */
    public static function canonical(mixed $value): mixed
    {
        $value = self::comparable($value);
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonical(...), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public static function seed(): array
    {
        if (self::$seed === null) {
            $json = file_get_contents(dirname(__DIR__, 3).'/database/seeders/data/demo-seed.json');
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
            self::$seed = $decoded;
        }

        return self::$seed;
    }

    /**
     * @return list<mixed>
     */
    public static function health(): array
    {
        if (self::$health === null) {
            foreach (self::raw('pms') as $case) {
                if ($case['case'] === 'sweep › evaluateFleet › whole seed fleet (defines $health)') {
                    /** @var list<mixed> $resolved */
                    $resolved = self::resolve($case['output']);
                    self::$health = $resolved;
                }
            }
        }

        return self::$health ?? throw new LogicException('pms.json does not define $health.');
    }

    private static function healthNode(string $path): mixed
    {
        $segments = explode('/', $path);
        $vehicleId = array_shift($segments);

        foreach (self::health() as $entry) {
            if (is_array($entry) && is_array($entry['vehicle'] ?? null) && ($entry['vehicle']['id'] ?? null) === $vehicleId) {
                return $segments === [] ? $entry : self::node($entry, implode('/', $segments), 'health');
            }
        }

        throw new LogicException("No health for {$vehicleId}.");
    }

    private static function node(mixed $node, string $path, string $root): mixed
    {
        if ($path === '') {
            return $node;
        }

        foreach (explode('/', $path) as $segment) {
            if (! is_array($node)) {
                throw new LogicException("{$root} path {$path} runs past a leaf.");
            }
            if (array_is_list($node)) {
                $match = null;
                foreach ($node as $element) {
                    if (is_array($element) && ($element['id'] ?? null) === $segment) {
                        $match = $element;
                        break;
                    }
                }
                $node = $match ?? ($node[(int) $segment] ?? throw new LogicException("{$root} path {$path} not found."));
            } else {
                $node = array_key_exists($segment, $node) ? $node[$segment] : throw new LogicException("{$root} path {$path} not found.");
            }
        }

        return $node;
    }
}
