<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Facades\JWTAuth;

// ─────────────────────────────────────────────────────────────────────────────
// Companion to LoginTest.php — covers the other three JWT session-lifecycle
// endpoints on AuthController: refresh, logout, and changePassword. All three
// sit behind the 'jwt.custom' middleware (JwtMiddleware), which authenticates
// via either a Bearer header or the auth_token cookie — production traffic
// uses the cookie, so that's the primary path exercised here, with one test
// confirming the Bearer-header path (mobile/API clients) still works too.
// ─────────────────────────────────────────────────────────────────────────────

function loggedInUser(array $overrides = []): array
{
    $user = User::factory()->create(array_merge([
        'password'          => Hash::make('Passw0rd!'),
        'email_verified_at' => now(),
    ], $overrides));

    $token = JWTAuth::fromUser($user);

    return [$user, $token];
}

// ── REFRESH ────────────────────────────────────────────────────────────────

it('refreshes a valid token via the auth_token cookie and rotates the cookies', function () {
    [$user, $token] = loggedInUser();

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/refresh');

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Token refreshed successfully')
        ->assertJsonStructure(['data' => ['token', 'expires_at']]);

    $newToken = $response->json('data.token');
    expect($newToken)->not->toBe($token);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    expect($cookies->has('auth_token'))->toBeTrue();
    expect($cookies['auth_token']->getValue())->not->toBe($token);
});

it('invalidates the old token after a refresh', function () {
    [$user, $token] = loggedInUser();

    $this->withCookie('auth_token', $token)
        ->postJson('/api/refresh')
        ->assertStatus(200);

    // The pre-refresh token should no longer authenticate.
    $this->withCookie('auth_token', $token)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('rejects a refresh with no token at all', function () {
    $response = $this->postJson('/api/refresh');

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Token not provided');
});

it('sets user_role on refresh to match the current admin status', function () {
    [$user, $token] = loggedInUser(['is_admin' => true]);

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/refresh');

    $response->assertStatus(200);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());
    expect($cookies['user_role']->getValue())->toBe('admin');
});

// ── LOGOUT ────────────────────────────────────────────────────────────────

it('logs out and clears all three auth cookies', function () {
    [$user, $token] = loggedInUser();

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/logout');

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Successfully logged out');

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($c) => $c->getName());

    // Cleared cookies are still present in the Set-Cookie header, but with
    // an expiry in the past / empty value — assert they're expired rather
    // than absent.
    foreach (['auth_token', 'user_role', 'is_authed'] as $name) {
        expect($cookies->has($name))->toBeTrue();
        expect($cookies[$name]->getExpiresTime())->toBeLessThanOrEqual(time());
    }
});

it('invalidates the token server-side on logout, not just the cookie', function () {
    [$user, $token] = loggedInUser();

    $this->withCookie('auth_token', $token)
        ->postJson('/api/logout')
        ->assertStatus(200);

    // Re-presenting the same token after logout must fail — logout should
    // be a server-side invalidation, not merely a client-side cookie clear.
    $this->withCookie('auth_token', $token)
        ->getJson('/api/me')
        ->assertStatus(401);
});

it('rejects logout with no token', function () {
    $response = $this->postJson('/api/logout');

    $response->assertStatus(401)
        ->assertJsonPath('message', 'Token not provided');
});

// ── CHANGE PASSWORD ───────────────────────────────────────────────────────

it('changes the password when the current password is correct', function () {
    [$user, $token] = loggedInUser(['password' => Hash::make('OldPassw0rd!')]);

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/user/change-password', [
            'current_password'          => 'OldPassw0rd!',
            'new_password'              => 'NewPassw0rd!',
            'new_password_confirmation' => 'NewPassw0rd!',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('message', 'Password has been changed successfully.');

    $user->refresh();
    expect(Hash::check('NewPassw0rd!', $user->password))->toBeTrue();
});

it('rejects a password change with the wrong current password', function () {
    [$user, $token] = loggedInUser(['password' => Hash::make('OldPassw0rd!')]);

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/user/change-password', [
            'current_password'          => 'TotallyWrong!',
            'new_password'              => 'NewPassw0rd!',
            'new_password_confirmation' => 'NewPassw0rd!',
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'Current password is incorrect.');

    $user->refresh();
    expect(Hash::check('OldPassw0rd!', $user->password))->toBeTrue();
});

it('rejects a new password identical to the current one', function () {
    [$user, $token] = loggedInUser(['password' => Hash::make('SamePassw0rd!')]);

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/user/change-password', [
            'current_password'          => 'SamePassw0rd!',
            'new_password'              => 'SamePassw0rd!',
            'new_password_confirmation' => 'SamePassw0rd!',
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('message', 'New password cannot be the same as your current password.');
});

it('rejects a weak new password that fails the complexity regex', function () {
    [$user, $token] = loggedInUser(['password' => Hash::make('OldPassw0rd!')]);

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/user/change-password', [
            'current_password'          => 'OldPassw0rd!',
            'new_password'              => 'alllowercase',
            'new_password_confirmation' => 'alllowercase',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('message', 'Password validation errors occurred');
});

it('rate limits repeated change-password attempts for the same user', function () {
    [$user, $token] = loggedInUser(['password' => Hash::make('OldPassw0rd!')]);

    for ($i = 0; $i < 5; $i++) {
        $this->withCookie('auth_token', $token)
            ->postJson('/api/user/change-password', [
                'current_password'          => 'WrongEveryTime!',
                'new_password'              => 'NewPassw0rd!',
                'new_password_confirmation' => 'NewPassw0rd!',
            ])->assertStatus(400);
    }

    $response = $this->withCookie('auth_token', $token)
        ->postJson('/api/user/change-password', [
            'current_password'          => 'WrongEveryTime!',
            'new_password'              => 'NewPassw0rd!',
            'new_password_confirmation' => 'NewPassw0rd!',
        ]);

    $response->assertStatus(429)
        ->assertJsonStructure(['message', 'retry_after']);
});

it('rejects change-password with no token', function () {
    $response = $this->postJson('/api/user/change-password', [
        'current_password'          => 'Whatever1!',
        'new_password'              => 'NewPassw0rd!',
        'new_password_confirmation' => 'NewPassw0rd!',
    ]);

    $response->assertStatus(401);
});

// ── Bearer-header path (mobile / server-to-server clients) ─────────────────

it('authenticates refresh, logout, and change-password via a Bearer header instead of a cookie', function () {
    [$user, $token] = loggedInUser(['password' => Hash::make('OldPassw0rd!')]);

    $this->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/refresh')
        ->assertStatus(200);

    [$user2, $token2] = loggedInUser(['password' => Hash::make('OldPassw0rd!')]);

    $this->withHeader('Authorization', "Bearer {$token2}")
        ->postJson('/api/user/change-password', [
            'current_password'          => 'OldPassw0rd!',
            'new_password'              => 'NewPassw0rd!',
            'new_password_confirmation' => 'NewPassw0rd!',
        ])->assertStatus(200);

    $this->withHeader('Authorization', "Bearer {$token2}")
        ->postJson('/api/logout')
        ->assertStatus(200);
});
