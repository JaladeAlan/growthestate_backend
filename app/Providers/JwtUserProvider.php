<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * JwtUserProvider
 *
 * The JWT package stores the token subject via getJWTIdentifier(), which
 * returns $this->uid (e.g. "USR-XCV7PZ"). When Laravel resolves the user
 * from a token it calls retrieveById($identifier) on the provider, which
 * by default runs: WHERE id = 'USR-XCV7PZ' 
 *
 * This provider overrides retrieveById() to query by `uid` instead.
 */
class JwtUserProvider extends EloquentUserProvider
{
    /**
     * Called by JWTGuard to hydrate the user from the token subject.
     * The $identifier here is whatever getJWTIdentifier() returned — our uid.
     */
    public function retrieveById($identifier): ?Authenticatable
    {
        return $this->createModel()
            ->newQuery()
            ->where('uid', $identifier)
            ->first();
    }
}
