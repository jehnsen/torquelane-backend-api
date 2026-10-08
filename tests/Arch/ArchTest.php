<?php

declare(strict_types=1);

use App\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

arch('php preset')->preset()->php();

arch('security preset')->preset()->security();

arch('strict types everywhere')
    ->expect('App')
    ->toUseStrictTypes();

arch('the domain is plain PHP: no HTTP, no Eloquent, no facades, no container helpers')
    ->expect('App\Domain')
    ->not->toUse([
        'Illuminate',
        'Symfony',
        'App\Actions',
        'App\Http',
        'App\Models',
        'App\Policies',
        'app', 'config', 'env', 'now', 'today', 'request', 'resolve', 'cache', 'event', 'dispatch', 'logger', 'auth', 'info',
    ]);

arch('controllers stay thin: no queries, no transactions')
    ->expect('App\Http\Controllers')
    ->not->toUse([
        'Illuminate\Support\Facades\DB',
        'Illuminate\Database\Eloquent\Builder',
        'Illuminate\Database\Query\Builder',
    ]);

arch('every model has a ULID key')
    ->expect('App\Models')
    ->classes()
    ->toExtend(Model::class)
    ->toUseTrait(HasUlids::class);

arch('every error the app raises on purpose carries an envelope code')
    ->expect('App\Exceptions')
    ->classes()
    ->toExtend(ApiException::class)
    ->ignoring(ApiException::class);

arch('env() is read only in config files, so config:cache works')
    ->expect('env')
    ->toOnlyBeUsedIn('config');
