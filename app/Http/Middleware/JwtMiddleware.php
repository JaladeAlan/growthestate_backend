<?php

namespace App\Http\Middleware;

use Closure;
use Tymon\JWTAuth\Facades\JWTAuth;
use Tymon\JWTAuth\Exceptions\JWTException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response;

class JwtMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        try {
            // Check if the token exists in the request
            if (!$token = JWTAuth::parseToken()) {
                return response()->json(['message' => 'Token not provided'], 401);
            }

            // Check if the token is valid
            $user = JWTAuth::authenticate($token);

            if (!$user) {
                return response()->json(['message' => 'User not found'], 401);
            }

            // Make the authenticated user available for the route
            $request->user = $user;
        } catch (JWTException $e) {
            return response()->json(['message' => 'Token is invalid or expired'], 401);
        }

        return $next($request);
    }
}
