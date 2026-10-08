<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/**
 * @return array<string, array<string, string>>
 */
function isolationCoverage(): array
{
    return require __DIR__.'/coverage.php';
}

/**
 * @return list<string>
 */
function apiGetRoutes(): array
{
    return collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route) => in_array('GET', $route->methods(), true) && str_starts_with($route->uri(), 'api/v1/'))
        ->map(fn (Route $route) => $route->uri())
        ->unique()
        ->sort()
        ->values()
        ->all();
}

it('declares every GET route as public or isolation-tested', function () {
    $undeclared = array_diff(apiGetRoutes(), array_keys(isolationCoverage()));

    expect($undeclared)->toBeEmpty('Add to tests/Isolation/coverage.php: '.implode(', ', $undeclared));
});

it('names no route that no longer exists', function () {
    $stale = array_diff(array_keys(isolationCoverage()), apiGetRoutes());

    expect($stale)->toBeEmpty('Remove from tests/Isolation/coverage.php: '.implode(', ', $stale));
});

it('points every isolation-tested route at an existing test class', function () {
    foreach (isolationCoverage() as $uri => $entry) {
        if (isset($entry['isolation'])) {
            expect(class_exists($entry['isolation']))->toBeTrue("{$uri}: {$entry['isolation']} does not exist");
        } else {
            expect($entry['public'] ?? '')->not->toBeEmpty("{$uri} needs a reason to be public");
        }
    }
});

it('serves every application route under /api/v1', function () {
    $outside = collect(Router::getRoutes()->getRoutes())
        ->map(fn (Route $route) => $route->uri())
        // Scramble's docs UI and its asset; its own middleware restricts them to APP_ENV=local.
        ->reject(fn (string $uri) => str_starts_with($uri, 'docs/api') || str_starts_with($uri, '_scramble/'))
        ->reject(fn (string $uri) => str_starts_with($uri, 'api/v1/'))
        ->values()
        ->all();

    expect($outside)->toBeEmpty();
});
