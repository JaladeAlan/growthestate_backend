<?php

namespace App\Http\Middleware;

use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Exceptions\TokenExpiredException;
use Tymon\JWTAuth\Exceptions\TokenInvalidException;
use Illuminate\Http\Request;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // The frontend moved to httpOnly-cookie auth (see AuthController's
            // buildAuthCookies) and stopped sending an Authorization header
            // entirely — it relies on the browser attaching the auth_token
            // cookie via withCredentials. tymon/jwt-auth's parseToken()
            // always re-extracts from the header/query string, which would
            // silently wipe out a token set manually from the cookie — so
            // the two paths are branched explicitly rather than combined.
            // Header still takes priority for non-browser API clients
            // (mobile app, Postman, server-to-server) that authenticate the
            // classic way.
            if ($request->bearerToken()) {
                $user = JWTAuth::parseToken()->authenticate();
            } elseif ($request->cookie('auth_token')) {
                $user = JWTAuth::setToken($request->cookie('auth_token'))->authenticate();
            } else {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            if (! $user) {
                return response()->json(['message' => 'User not found'], 401);
            }

            // Bind user to Laravel's auth system so $request->user() works everywhere
            auth()->setUser($user);

        } catch (TokenExpiredException $e) {
            return response()->json(['message' => 'Token has expired'], 401);
        } catch (TokenInvalidException $e) {
            return response()->json(['message' => 'Token is invalid'], 401);
        } catch (JWTException $e) {
            return response()->json(['message' => 'Token not provided'], 401);
        }

        return $next($request);
    }
}