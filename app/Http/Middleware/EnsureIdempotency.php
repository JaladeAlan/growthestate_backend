<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Prevents duplicate deposit/withdrawal submissions when a client retries
 * a request after a network failure (the request may have actually
 * succeeded server-side even though the client never saw the response).
 *
 * Usage: client sends an `Idempotency-Key` header (any client-generated
 * unique string, e.g. a UUID) with the request. If the same user replays
 * the same key, the original response is returned unchanged instead of
 * re-running the request. The header is optional — requests without it
 * are processed normally, unprotected.
 */
class EnsureIdempotency
{
    private const TTL_SECONDS  = 86400; // 24 hours — long enough to cover realistic client retry windows
    private const LOCK_SECONDS = 30;    // guards against a second request racing in while the first is still processing

    public function handle(Request $request, Closure $next)
    {
        $key = $request->header('Idempotency-Key');

        if (! $key) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Scope by user + route + key so the same key can't be replayed
        // against a different endpoint, and one user's key can't collide
        // with another's.
        $cacheKey = 'idempotency:' . $user->id . ':' . $request->path() . ':' . hash('sha256', $key);
        $lockKey  = $cacheKey . ':lock';

        $cached = Cache::get($cacheKey);

        if ($cached) {
            return response()->json(
                $cached['body'],
                $cached['status'],
                ['X-Idempotent-Replayed' => 'true']
            );
        }

        $lock = Cache::lock($lockKey, self::LOCK_SECONDS);

        if (! $lock->get()) {
            // Another request with the same key is already in flight.
            return response()->json([
                'success' => false,
                'message' => 'A request with this idempotency key is already being processed.',
            ], 409);
        }

        try {
            $response = $next($request);

            // Only cache successful/client-error responses that represent
            // a completed decision (2xx created, 4xx validation/business
            // rejection). Server errors (5xx) are NOT cached, so a genuine
            // failure can be safely retried with the same key.
            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'status' => $response->getStatusCode(),
                    'body'   => json_decode($response->getContent(), true),
                ], self::TTL_SECONDS);
            }

            return $response;
        } catch (\Throwable $e) {
            Log::error('EnsureIdempotency: request failed, not caching', [
                'user_id' => $user->id,
                'path'    => $request->path(),
                'error'   => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
