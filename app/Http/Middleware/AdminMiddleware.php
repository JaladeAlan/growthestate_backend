<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $user = auth()->user();

        // Gate the whole /admin prefix to staff accounts: either the
        // legacy is_admin flag, or anyone holding at least one RBAC role.
        // Which specific admin actions a staff account can perform is then
        // decided per-route by the `permission:` middleware.
        if (! $user || (! $user->is_admin && $user->roles()->doesntExist())) {
            return response()->json([
                'message' => 'Unauthorized'
            ], 403);
        }

        return $next($request);
    }
}
