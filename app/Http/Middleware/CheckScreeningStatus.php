<?php
// ══════════════════════════════════════════════════════
// app/Http/Middleware/CheckScreeningStatus.php
// ══════════════════════════════════════════════════════

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckScreeningStatus
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) return $next($request);

        if ($user->screening_status === 'blocked') {
            return response()->json([
                'message' => 'Your account has been restricted. Please contact support.',
                'code'    => 'ACCOUNT_RESTRICTED',
            ], 403);
        }

        // Flagged users can still access the platform but cannot transact
        // (Apply 'screening.transact' middleware to financial routes specifically)
        return $next($request);
    }
}
