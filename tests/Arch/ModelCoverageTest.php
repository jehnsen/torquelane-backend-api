<?php

declare(strict_types=1);

/*
 * R5: every model is classified here. Adding a model without classifying it
 * fails this test.
 *
 * Phase 1 adds the `tenant` classification, whose models must use the
 * BelongsToOrganization trait, and asserts it here. Until then every model is
 * `global`, with the reason it carries no organization_id.
 */
const MODEL_TENANCY = [
    'App\Models\User' => ['global', 'Identity spans organizations; membership arrives in Phase 1.'],
    'App\Models\IdempotencyKey' => ['global', 'Infrastructure keyed per user, pruned after 24h; holds no business data.'],
];

it('classifies every model in app/Models', function () {
    $models = collect(glob(dirname(__DIR__, 2).'/app/Models/*.php') ?: [])
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->sort()
        ->values()
        ->all();

    $classified = array_keys(MODEL_TENANCY);
    sort($classified);

    expect($models)->toBe($classified);
});

it('gives a reason for every global model', function () {
    foreach (MODEL_TENANCY as $model => [$kind, $reason]) {
        expect($kind)->toBeIn(['global', 'tenant'])
            ->and($kind === 'global' ? $reason : 'n/a')->not->toBeEmpty("{$model} needs a reason");
    }
});
