<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Tenancy\ScopeDenial;
use App\Models\User;
use App\Tenancy\TenantContextResolver;
use App\Tenancy\TenantDenials;
use App\Tenancy\TenantManager;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

/**
 * `tenant`: resolves the TenantContext from the AUTHENTICATED user only
 * (plus the validated X-Branch-Id header) and holds it for the request.
 * Runs after auth:sanctum and before route-model binding (middleware
 * priority, bootstrap/app.php), so bound models are already tenant-scoped.
 *
 * Fails closed: no context, or an ambiguous one, is 401/403 with the reason
 * logged and returned in `details.reason`; never a fallback to unscoped.
 */
final class ResolveTenantContext
{
    public const string BRANCH_HEADER = 'X-Branch-Id';

    public function __construct(
        private readonly TenantManager $tenancy,
        private readonly TenantContextResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Never inherit a context from an earlier request in the same process.
        $this->tenancy->forget();

        $user = $request->user();
        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        $resolution = $this->resolver->resolve($user, $request->header(self::BRANCH_HEADER));
        if ($resolution->context === null) {
            throw TenantDenials::exceptionFor($resolution->denial ?? ScopeDenial::NoSession, $user->id, $request->path());
        }

        $this->tenancy->set($resolution->context);
        Context::add('organization_id', $resolution->context->organizationId());
        Context::add('user_id', $user->id);

        try {
            /** @var Response */
            return $next($request);
        } finally {
            $this->tenancy->forget();
        }
    }
}
