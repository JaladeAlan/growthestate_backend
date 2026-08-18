<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

// ─────────────────────────────────────────────────────────────────────────────
// Verifies the PIN-reset flow's dual rate limits actually bite:
//   1. The application-level limit in PinController (3 attempts / 15 min,
//      keyed by user+IP, checked via RateLimiter inside checkPinFlowLimit).
//   2. The route-level `throttle:5,15` middleware in routes/api.php.
// Item #1 wasn't covered by any existing test, so a regression here (e.g. a
// refactor that drops the checkPinFlowLimit() call) would previously have
// gone unnoticed.
// ─────────────────────────────────────────────────────────────────────────────

function pinTestUser(): User
{
    return User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => Hash::make('1234'),
    ]);
}

it('allows the first 3 /pin/forgot attempts within 15 minutes', function () {
    Mail::fake();

    $user = pinTestUser();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user, 'api')
            ->postJson('/api/pin/forgot')
            ->assertStatus(200)
            ->assertJsonPath('success', true);
    }
});

it('blocks the 4th /pin/forgot attempt within 15 minutes with a 429', function () {
    Mail::fake();

    $user = pinTestUser();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user, 'api')->postJson('/api/pin/forgot');
    }

    $response = $this->actingAs($user, 'api')->postJson('/api/pin/forgot');

    $response->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['message', 'retry_after']);
});

it('scopes the /pin/forgot limit per user, not globally', function () {
    Mail::fake();

    $userA = pinTestUser();
    $userB = pinTestUser();

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($userA, 'api')->postJson('/api/pin/forgot');
    }

    // User A is now rate-limited...
    $this->actingAs($userA, 'api')
        ->postJson('/api/pin/forgot')
        ->assertStatus(429);

    // ...but User B, on the same 15-minute window, is unaffected.
    $this->actingAs($userB, 'api')
        ->postJson('/api/pin/forgot')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('blocks the 4th /pin/verify-code attempt within 15 minutes with a 429', function () {
    Mail::fake();

    $user = pinTestUser();

    $user->update([
        'pin_reset_code'       => Hash::make('99999999'),
        'pin_reset_expires_at' => now()->addMinutes(30),
    ]);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user, 'api')
            ->postJson('/api/pin/verify-code', ['code' => '00000000']); // wrong code, but still consumes an attempt
    }

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/pin/verify-code', ['code' => '00000000']);

    $response->assertStatus(429)
        ->assertJsonPath('success', false);
});

// ─────────────────────────────────────────────────────────────────────────────
// /pin/update against a user who never called /pin/set. Hash::check() returns
// false (not an exception) against a null hashed value, so without an
// explicit guard this fell through to the generic "Current PIN is incorrect"
// path — misleading, and it burned one of the 5 rate-limited attempts for a
// PIN that never existed.
// ─────────────────────────────────────────────────────────────────────────────

it('rejects /pin/update with a clear message when no PIN has been set yet', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => null,
    ]);

    $response = $this->actingAs($user, 'api')->postJson('/api/pin/update', [
        'current_pin'      => '1234',
        'new_pin'          => '5678',
        'pin_confirmation' => '5678',
    ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No PIN is set yet. Use /pin/set to create one.');
});

it('rejects /pin/forgot with a clear message when no PIN has been set yet, without sending mail or consuming the rate limit', function () {
    Mail::fake();

    $user = User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => null,
    ]);

    $response = $this->actingAs($user, 'api')->postJson('/api/pin/forgot');

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'No PIN is set yet. Use /pin/set to create one.');

    Mail::assertNothingQueued();

    // Guard fires before the rate limiter, so the 3/15-min forgot budget
    // is still fully available afterwards.
    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user, 'api')->postJson('/api/pin/forgot')
            ->assertStatus(422); // still no PIN — same guard, not a rate limit
    }

    $user->update(['transaction_pin' => Hash::make('1234')]);

    $this->actingAs($user, 'api')
        ->postJson('/api/pin/forgot')
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});