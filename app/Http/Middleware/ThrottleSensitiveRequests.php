<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class ThrottleSensitiveRequests
{
    /**
     * 3 attempts per 15 minutes per email+IP combination.
     * After exhaustion, the user must wait 15 minutes (900 seconds).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $email = $request->input('email', '');
        $key   = 'sensitive:' . sha1($email . '|' . $request->ip());

        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'error'       => 'Too many attempts. Please try again later.',
                'retry_after' => $seconds,
            ], 429);
        }

        // Decay = 15 minutes (900 seconds)
        RateLimiter::hit($key, 900);

        $response = $next($request);

        // Clear on success (2xx) so legitimate users aren't permanently locked
        if ($response->getStatusCode() < 300) {
            RateLimiter::clear($key);
        }

        return $response;
    }
}