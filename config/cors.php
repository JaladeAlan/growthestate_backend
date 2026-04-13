<?php

/**
 * CORS Configuration
 */

return [

    /*
    |----------------------------------------------------------------------
    | Paths covered by CORS headers
    |----------------------------------------------------------------------
    */
    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    /*
    |----------------------------------------------------------------------
    | Allowed origins
    |----------------------------------------------------------------------
    | List every origin that is permitted to make credentialed requests.
    | Prefer explicit URLs over wildcard patterns.
    |
    | Environment-variable override lets you manage environments without
    | changing code:
    |   CORS_ALLOWED_ORIGINS=https://reu.ng,https://app.reu.ng
    */
    'allowed_origins' => array_filter(
        array_map(
            'trim',
            explode(',', env('CORS_ALLOWED_ORIGINS', implode(',', [
                // ── Production ────────────────────────────────────────────
                'https://reu.ng',
                'https://www.reu.ng',
                'https://app.reu.ng',
            
                // ── Local development ─────────────────────────────────────
                'http://localhost:3000',
                'http://localhost:5173',
            ])))
        )
    ),

    /*
    |----------------------------------------------------------------------
    | Allowed origin patterns (regex) — INTENTIONALLY EMPTY
    |----------------------------------------------------------------------
    */
    'allowed_origins_patterns' => [],

    /*
    |----------------------------------------------------------------------
    | Allowed HTTP methods
    |----------------------------------------------------------------------
    */
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    /*
    |----------------------------------------------------------------------
    | Allowed request headers
    |----------------------------------------------------------------------
    */
    'allowed_headers' => [
        'Content-Type',
        'Authorization',
        'X-Requested-With',
        'Accept',
        'Origin',
        'X-CSRF-TOKEN',
    ],

    /*
    |----------------------------------------------------------------------
    | Headers exposed to the browser
    |----------------------------------------------------------------------
    */
    'exposed_headers' => [],

    /*
    |----------------------------------------------------------------------
    | Preflight cache lifetime (seconds)
    |----------------------------------------------------------------------
    */
    'max_age' => 86400,

    /*
    |----------------------------------------------------------------------
    | Credentials (cookies, Authorization header)
    |----------------------------------------------------------------------
    | Must be true for JWT cookies / session-based auth to work cross-origin.
    | When true, `allowed_origins` must be explicit — "*" is rejected by
    | browsers for credentialed requests.
    */
    'supports_credentials' => true,

];
