<?php
// ══════════════════════════════════════════════════════
// app/Http/Middleware/CheckScreeningClear.php
// Apply to deposit/withdraw/purchase routes only
// ══════════════════════════════════════════════════════

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckScreeningClear
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user) return $next($request);

        if (in_array($user->screening_status, ['blocked', 'flagged', 'pending'])) {
            return response()->json([
                'message' => match ($user->screening_status) {
                    'blocked' => 'Your account has been restricted. Please contact support.',
                    'flagged' => 'Your account is under review. Transactions are temporarily paused.',
                    'pending' => 'Your account verification is pending. Please complete KYC.',
                    default   => 'Unable to process transaction at this time.',
                },
                'code' => 'SCREENING_' . strtoupper($user->screening_status),
            ], 403);
        }

        return $next($request);
    }
}
