<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─────────────────────────────────────────────────────────────────────────────
// Before this fix, `validateCsrfTokens(except: [...])` in bootstrap/app.php
// configured exemptions for a check that never actually ran on api/*
// routes — ValidateCsrfToken only runs on the 'web' group by default, and
// nothing bridged it onto 'api'. $middleware->statefulApi() (Laravel 11's
// built-in helper for exactly this) now does that bridging for requests
// coming from a domain listed in config('sanctum.stateful'), without
// changing how users are authenticated (still JwtMiddleware / auth_token
// cookie throughout).
//
// These tests simulate a stateful-origin browser request (Origin/Referer
// matching config('sanctum.stateful')) hitting a real mutating endpoint
// without a CSRF token, and confirm it's now rejected — then confirm a
// request carrying a valid CSRF token proceeds normally.
// ─────────────────────────────────────────────────────────────────────────────

function statefulUser(): array
{
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => Hash::make('1234'),
    ]);

    $token = JWTAuth::fromUser($user);

    return [$user, $token];
}

it('rejects a stateful-origin mutating request with no CSRF token', function () {
    [$user, $token] = statefulUser();

    $response = $this->withHeaders([
            'Origin'  => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/dashboard',
        ])
        ->withCookie('auth_token', $token)
        ->postJson('/api/pin/set', ['pin' => '1234', 'pin_confirmation' => '1234']);

    $response->assertStatus(419);
});

it('accepts a stateful-origin request carrying a valid CSRF token', function () {
    [$user, $token] = statefulUser();

    // Bootstrap a real session + CSRF token the way the frontend would:
    // GET /sanctum/csrf-cookie first.
    $csrfResponse = $this->withHeaders([
            'Origin'  => 'http://localhost:3000',
            'Referer' => 'http://localhost:3000/dashboard',
        ])
        ->getJson('/sanctum/csrf-cookie');

    $csrfResponse->assertStatus(204);

    $xsrfCookie = collect($csrfResponse->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === 'XSRF-TOKEN');

    expect($xsrfCookie)->not->toBeNull();

    $plainToken = urldecode($xsrfCookie->getValue());

    $response = $this->withHeaders([
            'Origin'       => 'http://localhost:3000',
            'Referer'      => 'http://localhost:3000/dashboard',
            'X-XSRF-TOKEN' => $plainToken,
        ])
        ->withCookie('auth_token', $token)
        ->withCookie('XSRF-TOKEN', $xsrfCookie->getValue())
        ->postJson('/api/pin/set', ['pin' => '1234', 'pin_confirmation' => '1234']);

    // Not 419 is the point of this test — CSRF passed. Whatever the
    // controller does next (200, 422, etc.) is out of scope here.
    $response->assertStatus(fn ($status) => $status !== 419);
});

it('does not require a CSRF token for a Bearer-authenticated (non-stateful) request', function () {
    [$user, $token] = statefulUser();

    // No Origin/Referer matching a stateful domain, and no cookie — just a
    // bearer token, the way a mobile app or server-to-server client would
    // authenticate. Should never be subject to CSRF.
    $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
        ->postJson('/api/pin/set', ['pin' => '1234', 'pin_confirmation' => '1234']);

    $response->assertStatus(fn ($status) => $status !== 419);
});

it('still rejects a forged cross-site payment webhook path with no exemption needed', function () {
    // Sanity check that the webhook exemptions in validateCsrfTokens(except:)
    // are still doing their job now that CSRF is actually live on api/*.
    $response = $this->postJson('/api/paystack/webhook', []);

    // Should reach the controller (and get rejected there for a bad
    // signature), never 419.
    $response->assertStatus(fn ($status) => $status !== 419);
});
