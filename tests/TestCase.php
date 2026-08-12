<?php

namespace Tests;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

abstract class TestCase extends BaseTestCase
{
    /**
     * The app's App\Http\Middleware\JwtMiddleware authenticates every
     * protected route by calling JWTAuth::parseToken() directly against
     * the request's Authorization header — it never consults Laravel's
     * Auth guard. The framework's default actingAs() only sets the
     * guard's resolved user, which JwtMiddleware never looks at, so
     * every "authenticated" test request was coming back 401.
     *
     * Issue a real JWT for the user and attach it as the default
     * Authorization header so existing tests using actingAs($user, 'api')
     * or actingAs($user, 'sanctum') keep working unmodified.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        if ($user instanceof User) {
            $token = JWTAuth::fromUser($user);
            $this->withHeader('Authorization', 'Bearer '.$token);
        }

        return parent::actingAs($user, $guard);
    }
}
