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
        'pin_reset_code'       => Hash::make('999999'),
        'pin_reset_expires_at' => now()->addMinutes(30),
    ]);

    for ($i = 0; $i < 3; $i++) {
        $this->actingAs($user, 'api')
            ->postJson('/api/pin/verify-code', ['code' => '000000']); // wrong code, but still consumes an attempt
    }

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/pin/verify-code', ['code' => '000000']);

    $response->assertStatus(429)
        ->assertJsonPath('success', false);
});
