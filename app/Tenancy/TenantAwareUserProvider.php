<?php

declare(strict_types=1);

namespace App\Tenancy;

use Closure;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * Users are tenant-scoped, but authentication has to find one before any
 * tenant is known: by id (the session), by remember token, by email (login,
 * password reset). Each lookup runs in the named system context
 * "authentication". Nothing else about the user is trusted from here; the
 * tenant middleware resolves scope from the row it finds.
 */
final class TenantAwareUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier): (Authenticatable&Model)|null
    {
        return $this->asSystem(fn (): (Authenticatable&Model)|null => parent::retrieveById($identifier));
    }

    public function retrieveByToken($identifier, #[SensitiveParameter] $token): (Authenticatable&Model)|null
    {
        return $this->asSystem(fn (): (Authenticatable&Model)|null => parent::retrieveByToken($identifier, $token));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function retrieveByCredentials(#[SensitiveParameter] array $credentials): (Authenticatable&Model)|null
    {
        return $this->asSystem(fn (): (Authenticatable&Model)|null => parent::retrieveByCredentials($credentials));
    }

    /**
     * @param  Closure(): ((Authenticatable&Model)|null)  $lookup
     */
    private function asSystem(Closure $lookup): (Authenticatable&Model)|null
    {
        return app(TenantManager::class)->system('authentication', $lookup);
    }
}
