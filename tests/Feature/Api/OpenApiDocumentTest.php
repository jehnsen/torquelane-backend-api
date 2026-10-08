<?php

declare(strict_types=1);

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApiProbeController;

/**
 * @return array<string, mixed>
 */
function generateOpenApi(): array
{
    /** @var array<string, mixed> */
    return app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));
}

it('documents inferred errors as the envelope, not Laravel\'s default shapes', function () {
    Route::middleware(['api', 'auth:sanctum'])
        ->post('api/v1/_probe', [OpenApiProbeController::class, 'store']);

    $responses = generateOpenApi()['paths']['/_probe']['post']['responses'];
    $components = generateOpenApi()['components']['responses'] ?? [];

    $resolve = fn (array $response) => isset($response['$ref'])
        ? $components[str_replace('#/components/responses/', '', $response['$ref'])]
        : $response;

    foreach (['401' => 'unauthenticated', '409' => 'conflict', '422' => 'validation'] as $status => $code) {
        expect($responses)->toHaveKey($status);
        $schema = $resolve($responses[$status])['content']['application/json']['schema'];

        expect($schema['required'])->toBe(['error'])
            ->and($schema['properties']['error']['properties']['code']['enum'])->toBe([$code])
            ->and($schema['properties'])->not->toHaveKey('message');
    }
});

it('pins the server to the relative /api/v1 path so the committed spec is machine-independent', function () {
    expect(generateOpenApi()['servers'])->toBe([[
        'url' => '/api/v1',
        'description' => 'Relative to the environment host, e.g. https://api-staging.<domain>',
    ]]);
});
