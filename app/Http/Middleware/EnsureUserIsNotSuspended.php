<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsNotSuspended
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_suspended) {
            return response()->json([
                'success' => false,
                'message' => 'Your account has been suspended. Please contact support.',
                'code'    => 'ACCOUNT_SUSPENDED',
            ], 403);
        }

        return $next($request);
    }
}
