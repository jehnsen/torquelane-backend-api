<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exceptions\AccountSuspendedException;
use App\Exceptions\ConflictException;
use App\Exceptions\InvalidTransitionException;
use App\Exceptions\ModuleDisabledException;
use App\Http\Requests\PaginatedRequest;
use App\Http\Resources\ApiResourceCollection;
use App\Models\User;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Routes that exist only inside tests, under /api/v1/_test, to drive the
 * plumbing (errors, idempotency, pagination) before any business route exists.
 */
final class TestRoutes
{
    public const PREFIX = 'api/v1/_test';

    /** The Phase 0A user shape the pagination tests pin. */
    private const USER_COLUMNS = ['id', 'name', 'email', 'email_verified_at', 'created_at', 'updated_at'];

    public static function register(): void
    {
        Route::middleware('api')->prefix(self::PREFIX)->group(function (): void {
            Route::get('auth', fn () => ['ok' => true])->middleware('auth:sanctum');

            // Users are tenant-scoped since Phase 1; these plumbing routes have no tenant,
            // so they read in a named system context.
            Route::get('missing-model', fn () => app(TenantManager::class)->system('test route', fn () => User::query()->findOrFail('01JAAAAAAAAAAAAAAAAAAAAAAA')));
            Route::get('out-of-scope', fn () => AuthResponse::denyAsNotFound()->authorize());
            Route::get('forbidden', fn () => AuthResponse::deny('Only a provider admin may do this.')->authorize());

            Route::post('validate', fn (Request $request) => $request->validate([
                'name' => ['required', 'string'],
                'quantity' => ['required', 'integer'],
            ]));

            Route::post('transition', fn () => throw new InvalidTransitionException('Cannot move a closed work order back to draft.', ['from' => 'closed', 'to' => 'draft']));
            Route::post('conflict', fn () => throw new ConflictException);
            Route::get('module', fn () => throw new ModuleDisabledException);
            Route::get('account-suspended', fn () => throw new AccountSuspendedException('This customer account is suspended.', ['reason' => 'account_suspended']));

            Route::get('throttled', fn () => ['ok' => true])->middleware('throttle:1,1');

            Route::get('boom', fn () => throw new RuntimeException('SQLSTATE password=hunter2 leaked'));

            Route::get('request-id', fn () => ['request_id' => Context::get('request_id')]);

            Route::middleware(['auth:sanctum', 'idempotent'])->group(function (): void {
                Route::post('orders', function (Request $request): JsonResponse {
                    $n = Cache::increment('test:orders');

                    return new JsonResponse(['data' => ['n' => $n, 'echo' => $request->input('item')]], 201, ['Location' => '/api/v1/orders/'.$n]);
                });
                Route::post('other-orders', fn () => new JsonResponse(['data' => ['n' => Cache::increment('test:orders')]], 201));
                Route::post('failing-orders', function (): never {
                    Cache::increment('test:orders');

                    throw new RuntimeException('downstream failed');
                });
            });
            Route::post('strict-orders', fn () => new JsonResponse(['data' => ['n' => Cache::increment('test:orders')]], 201))
                ->middleware(['auth:sanctum', 'idempotent:required']);

            Route::get('users', fn (PaginatedRequest $request) => app(TenantManager::class)->system('test route', fn () => new class(User::query()->select(self::USER_COLUMNS)->orderBy('email')->paginate($request->perPage())) extends ApiResourceCollection {}));
            Route::get('users-cursor', fn (PaginatedRequest $request) => app(TenantManager::class)->system('test route', fn () => new class(User::query()->select(self::USER_COLUMNS)->orderBy('id')->cursorPaginate($request->perPage())) extends ApiResourceCollection {}));
        });
    }
}
