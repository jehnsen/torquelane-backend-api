<?php

declare(strict_types=1);

namespace Tests\Golden\Support;

use LogicException;

/**
 * Reads ../web's golden fixtures (copied into tests/Golden/fixtures/web from
 * pms-monitoring-frontend@d45871e, fixtures/golden) and resolves their value
 * encoding (fixtures README):
 *
 *  - `{"$seed": "state/fleetClients/fc-actimed"}` → that node of
 *    database/seeders/data/demo-seed.json (an array segment selects the
 *    element whose `id` equals it, else a numeric index);
 *  - `{"$undefined": true}` → null (the PHP ports take null for "absent");
 *  - `{"$money": 1234.5, "cents": 123450}` → the number the TypeScript produced.
 *
 * Other encodings ($date, $map, $set, $health, $number) do not occur in the
 * modules replayed in Phase 1; meeting one throws, so a fixture change cannot
 * slip past unresolved.
 */
final class WebFixtures
{
    /** @var array<string, mixed>|null */
    private static ?array $seed = null;

    /**
     * @return list<array{case: string, fn: string, input: mixed, output: mixed}>
     */
    public static function cases(string $module): array
    {
        $json = file_get_contents(dirname(__DIR__).'/fixtures/web/'.$module.'.json');
        if ($json === false) {
            throw new LogicException("Missing fixture {$module}.json");
        }

        /** @var list<array{case: string, fn: string, input: mixed, output: mixed}> $cases */
        $cases = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return array_map(fn (array $case): array => [
            'case' => $case['case'],
            'fn' => $case['fn'],
            'input' => self::resolve($case['input']),
            'output' => self::resolve($case['output']),
        ], $cases);
    }

    public static function resolve(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_key_exists('$seed', $value) && count($value) === 1) {
            return self::seedNode((string) $value['$seed']);
        }
        if (array_key_exists('$undefined', $value)) {
            return null;
        }
        if (array_key_exists('$money', $value)) {
            return $value['$money'];
        }
        foreach (['$date', '$map', '$set', '$health', '$number'] as $encoding) {
            if (array_key_exists($encoding, $value)) {
                throw new LogicException("Encoding {$encoding} is not handled by the Phase 1 replay.");
            }
        }

        return array_map(self::resolve(...), $value);
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

    private static function seedNode(string $path): mixed
    {
        $node = self::seed();
        if ($path === '') {
            return $node;
        }

        foreach (explode('/', $path) as $segment) {
            if (! is_array($node)) {
                throw new LogicException("Seed path {$path} runs past a leaf.");
            }
            if (array_is_list($node)) {
                $match = null;
                foreach ($node as $element) {
                    if (is_array($element) && ($element['id'] ?? null) === $segment) {
                        $match = $element;
                        break;
                    }
                }
                $node = $match ?? ($node[(int) $segment] ?? throw new LogicException("Seed path {$path} not found."));
            } else {
                $node = $node[$segment] ?? throw new LogicException("Seed path {$path} not found.");
            }
        }

        return $node;
    }
}
