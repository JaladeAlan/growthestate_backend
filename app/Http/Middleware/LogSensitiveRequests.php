<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Logs who did what, when, from where, for admin mutations and financial
 * transactions — separate from AdminActionLog (which records business-level
 * "what changed" facts in the DB for the admin UI). This middleware is a
 * lower-level access/request log: method, path, status, actor, IP,
 * user-agent, latency. Useful for incident response and abuse investigation
 * even on endpoints that don't call AdminActionLog::record().
 *
 * Only fires for mutating verbs (POST/PUT/PATCH/DELETE) — GET/HEAD requests
 * are not logged here to keep volume down. Read access on sensitive data
 * (e.g. KYC images) is logged separately, at the point of access, by the
 * controller that serves it — see KycImageController::show()'s
 * 'kyc_image_accessed' audit log entry.
 *
 * Request/response bodies are intentionally NOT logged — these routes carry
 * bank details, PINs, and KYC data, and body logging is a common source of
 * secrets ending up in log aggregators.
 */
class LogSensitiveRequests
{
    public function handle(Request $request, Closure $next)
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $start = microtime(true);

        $response = $next($request);

        $user = $request->user();

        Log::channel('audit')->info('sensitive_request', [
            'method'        => $request->method(),
            'path'          => $request->path(),
            'status'        => $response->getStatusCode(),
            'user_id'       => $user?->id,
            'is_admin'      => $user?->is_admin ?? false,
            'ip'            => $request->ip(),
            'user_agent'    => substr((string) $request->userAgent(), 0, 255),
            'duration_ms'   => (int) round((microtime(true) - $start) * 1000),
            'timestamp'     => now()->toIso8601String(),
        ]);

        return $response;
    }
}
