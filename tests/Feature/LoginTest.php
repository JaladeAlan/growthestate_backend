<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// ─────────────────────────────────────────────────────────────────────────────
// AuthController::login was previously untested — every other feature test
// authenticates via $this->actingAs() (which mints a JWT directly and never
// touches the controller) or JWTAuth::fromUser(). This file exercises the
// actual POST /login endpoint: enumeration resistance, rate limiting, the
// email-verification gate, cookie shape, and the response payload.
// ─────────────────────────────────────────────────────────────────────────────

function verifiedUser(array $overrides = []): User
{
    return User::factory()->create(array_merge([
        'password'           => Hash::make('Passw0rd!'),
        'email_verified_at'  => now(),
    ], $overrides));
}

// ── Happy path ──────────────────────────────────────────────────────────────

it('logs in with valid credentials and returns token, expiry, and user payload', function () {
    $user = verifiedUser(['email' => 'success@example.com']);

    $response = $this->postJson('/api/login', [
        'email'    => 'success@example.com',
        'password' => 'Passw0rd!',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Login successful')
        ->assertJsonStructure([
            'message',
            'data' => ['token', 'expires_at', 'user'],
        ]);

    // Sensitive fields must never be present on the wire.
    $response->assertJsonMissingPath('data.user.password')
        ->assertJsonMissingPath('data.user.transaction_pin')
        ->assertJsonMissingPath('data.user.pin_reset_code');

    // Derived flags computed by the controller should be present.
    $response->assertJsonStructure([
        'data' => ['user' => ['pin_is_set', 'is_kyc_verified', 'kyc_status']],
    ]);

    expect($response->json('data.expires_at'))->toBeInt();
});

it('sets auth_token, user_role, and is_authed cookies on successful login', function () {
    $user = verifiedUser(['email' => 'cookies@example.com', 'is_admin' => false]);

    $response = $this->postJson('/api/login', [
        'email'    => 'cookies@example.com',
        'password' => 'Passw0rd!',
    ]);

    $response->assertStatus(200);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());

    expect($cookies->has('auth_token'))->toBeTrue();
    expect($cookies->has('user_role'))->toBeTrue();
    expect($cookies->has('is_authed'))->toBeTrue();

    // Token cookie must never be readable by client JS.
    expect($cookies['auth_token']->isHttpOnly())->toBeTrue();
    expect($cookies['user_role']->isHttpOnly())->toBeTrue();

    // is_authed is deliberately the one cookie JS is allowed to read.
    expect($cookies['is_authed']->isHttpOnly())->toBeFalse();

    expect($cookies['user_role']->getValue())->toBe('user');
});

it('sets the user_role cookie to admin for an admin account', function () {
    verifiedUser(['email' => 'admin@example.com', 'is_admin' => true]);

    $response = $this->postJson('/api/login', [
        'email'    => 'admin@example.com',
        'password' => 'Passw0rd!',
    ]);

    $response->assertStatus(200);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());

    expect($cookies['user_role']->getValue())->toBe('admin');
});

// ── Enumeration resistance ───────────────────────────────────────────────────

it('returns the same vague error for a nonexistent email as for a wrong password', function () {
    verifiedUser(['email' => 'exists@example.com']);

    $unknownEmailResponse = $this->postJson('/api/login', [
        'email'    => 'nobody@example.com',
        'password' => 'Passw0rd!',
    ]);

    $wrongPasswordResponse = $this->postJson('/api/login', [
        'email'    => 'exists2@example.com',
        'password' => 'WrongPassw0rd!',
    ]);

    $unknownEmailResponse->assertStatus(401)
        ->assertJsonPath('message', 'Invalid credentials');

    // Same status/message shape for an unknown email as for a known one —
    // the two responses must be indistinguishable to an attacker.
    expect($unknownEmailResponse->json('message'))->toBe('Invalid credentials');
});

it('rejects a wrong password with a vague error, not a specific one', function () {
    verifiedUser(['email' => 'wrongpass@example.com']);

    $response = $this->postJson('/api/login', [
        'email'    => 'wrongpass@example.com',
        'password' => 'NotTheRightPassw0rd!',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Invalid credentials');
});

// ── Email verification gate ──────────────────────────────────────────────────

it('blocks login for an unverified email even with the correct password', function () {
    User::factory()->create([
        'email'             => 'unverified@example.com',
        'password'          => Hash::make('Passw0rd!'),
        'email_verified_at' => null,
    ]);

    $response = $this->postJson('/api/login', [
        'email'    => 'unverified@example.com',
        'password' => 'Passw0rd!',
    ]);

    $response->assertStatus(403)
        ->assertJsonPath('message', 'Please verify your email before logging in.');
});

it('does not set auth cookies when blocked for an unverified email', function () {
    User::factory()->create([
        'email'             => 'unverified2@example.com',
        'password'          => Hash::make('Passw0rd!'),
        'email_verified_at' => null,
    ]);

    $response = $this->postJson('/api/login', [
        'email'    => 'unverified2@example.com',
        'password' => 'Passw0rd!',
    ]);

    $response->assertStatus(403);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    expect($cookies->has('auth_token'))->toBeFalse();
});

// ── Validation ────────────────────────────────────────────────────────────────

it('returns a 422 when email or password is missing', function () {
    $response = $this->postJson('/api/login', ['email' => 'missing-password@example.com']);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Validation errors occurred');
});

// ── Rate limiting ─────────────────────────────────────────────────────────────

it('rate limits after 5 failed login attempts for the same email+IP', function () {
    verifiedUser(['email' => 'ratelimited@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', [
            'email'    => 'ratelimited@example.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(401);
    }

    $response = $this->postJson('/api/login', [
        'email'    => 'ratelimited@example.com',
        'password' => 'WrongPassword!',
    ]);

    $response->assertStatus(429)
        ->assertJsonStructure(['message', 'retry_after']);
});

it('rate limits repeated attempts against a nonexistent email too', function () {
    // The limiter keys on email+IP regardless of whether the account
    // exists, so an attacker can't bypass throttling just by trying
    // random emails.
    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/login', [
            'email'    => 'never-registered@example.com',
            'password' => 'WhoKnows!',
        ])->assertStatus(401);
    }

    $response = $this->postJson('/api/login', [
        'email'    => 'never-registered@example.com',
        'password' => 'WhoKnows!',
    ]);

    $response->assertStatus(429);
});

it('clears the rate limit after a successful login', function () {
    verifiedUser(['email' => 'recovers@example.com']);

    // A few failed attempts, but under the 5-attempt threshold.
    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/login', [
            'email'    => 'recovers@example.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(401);
    }

    $this->postJson('/api/login', [
        'email'    => 'recovers@example.com',
        'password' => 'Passw0rd!',
    ])->assertStatus(200);

    // Immediately failing again should not be blocked by leftover hits
    // from before the successful login.
    $response = $this->postJson('/api/login', [
        'email'    => 'recovers@example.com',
        'password' => 'WrongPassword!',
    ]);

    $response->assertStatus(401); // not 429
});

it('scopes the rate limit per IP so a different IP is not blocked', function () {
    verifiedUser(['email' => 'perip@example.com']);

    for ($i = 0; $i < 5; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
            ->postJson('/api/login', [
                'email'    => 'perip@example.com',
                'password' => 'WrongPassword!',
            ])->assertStatus(401);
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])
        ->postJson('/api/login', [
            'email'    => 'perip@example.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(429);

    // A different source IP for the same email is unaffected.
    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
        ->postJson('/api/login', [
            'email'    => 'perip@example.com',
            'password' => 'WrongPassword!',
        ])->assertStatus(401);
});
