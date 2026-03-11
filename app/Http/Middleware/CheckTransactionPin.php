<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class CheckTransactionPin
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if (! $user || ! $user->transaction_pin) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction PIN not set. Please create a PIN first.',
            ], 400);
        }

        $pin = $request->input('transaction_pin');

        if (! $pin) {
            return response()->json([
                'success' => false,
                'message' => 'Transaction PIN is required.',
            ], 400);
        }

        $key = 'pin-attempts:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'success'     => false,
                'message'     => 'Too many failed PIN attempts. Please try again later.',
                'retry_after' => $seconds,
            ], 429);
        }

        if (! Hash::check($pin, $user->transaction_pin)) {
            RateLimiter::hit($key, 900);

            $remaining = 5 - RateLimiter::attempts($key);

            return response()->json([
                'success'            => false,
                'message'            => 'Invalid transaction PIN.',
                'attempts_remaining' => max(0, $remaining),
            ], 403);
        }

        RateLimiter::clear($key);

        return $next($request);
    }
}