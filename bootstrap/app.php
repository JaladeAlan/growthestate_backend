<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Must be registered for config/trustedproxy.php to have any
        // effect — without this, $request->ip() returns the load
        // balancer's own IP for every request (Render sits in front of
        // the app), so IP-keyed rate limits (e.g. throttle:5,60 on
        // /register) end up shared across all users behind it instead
        // of being per-client.
        // NOTE: config() is not yet available this early in the
        // bootstrap lifecycle (this closure runs before config files
        // are loaded), so this reads the env vars directly rather than
        // via config('trustedproxy.*').
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: \Illuminate\Http\Request::HEADER_X_FORWARDED_FOR |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_HOST |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PORT |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_PROTO |
                     \Illuminate\Http\Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        // Enables Sanctum's stateful-SPA session + CSRF plumbing on the
        // 'api' group, for requests coming from a domain listed in
        // config/sanctum.php's 'stateful' array (the frontend). It does NOT
        // change how users are authenticated — that's still entirely
        // App\Http\Middleware\JwtMiddleware / the auth_token cookie. This
        // only adds: EncryptCookies, AddQueuedCookiesToResponse,
        // StartSession, VerifyCsrfToken ahead of the existing stack, so
        // that the CSRF token issued by GET /sanctum/csrf-cookie is
        // actually checked on mutating requests. A request authenticated
        // via Authorization: Bearer (mobile app, Postman, server-to-server)
        // is NOT from a stateful domain and skips this entirely — CSRF only
        // applies to cookie-authenticated browser requests, which is the
        // only place CSRF is a real risk in the first place.
        //
        // Before this, `validateCsrfTokens(except: [...])` below configured
        // exemptions for a check that never ran on api/* routes at all —
        // ValidateCsrfToken only runs on the 'web' group by default. That
        // except-list is now load-bearing.
        $middleware->statefulApi();

        $middleware->encryptCookies(except: [
            'is_authed',
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/paystack/webhook',
            'api/monnify/webhook',
            'api/opay/webhook',       
        ]);
        
        $middleware->alias([
            // Auth
            'auth'               => \App\Http\Middleware\Authenticate::class,
            // `jwt.auth` is reserved and subsequently registered by tymon/jwt-auth.
            // Keep this alias unique so our cookie-aware authentication runs.
            'jwt.custom'         => \App\Http\Middleware\JwtMiddleware::class,
            'jwt.refresh'        => \Tymon\JWTAuth\Http\Middleware\RefreshToken::class,
    
            // Authorization
            'admin'              => \App\Http\Middleware\AdminMiddleware::class,
            'permission'         => \App\Http\Middleware\CheckPermission::class,
            'verified'           => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'suspended'          => \App\Http\Middleware\EnsureUserIsNotSuspended::class,
    
            // Transaction PIN
            'check.pin'          => \App\Http\Middleware\CheckTransactionPin::class,
            'idempotent'         => \App\Http\Middleware\EnsureIdempotency::class,

            // Sanctions screening
            'screening.status'   => \App\Http\Middleware\CheckScreeningStatus::class,
            'screening.transact' => \App\Http\Middleware\CheckScreeningClear::class,
    
            // Rate limiting
            'throttle.sensitive' => \App\Http\Middleware\ThrottleSensitiveRequests::class,

            // Audit / request logging
            'audit.log'          => \App\Http\Middleware\LogSensitiveRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
