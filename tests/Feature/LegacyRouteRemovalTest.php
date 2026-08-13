<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// ─────────────────────────────────────────────────────────────────────────────
// routes/web.php used to carry a full parallel copy of the API, including
// POST /lands/{id}/purchase and /lands/{id}/sell calling the SAME
// PurchaseController methods as the real API — but without 'check.pin',
// 'screening.transact', or 'suspended' middleware. Since PurchaseController
// itself never verifies the PIN (that check lives entirely in the
// CheckTransactionPin middleware), this let an authenticated + verified user
// buy or sell land with no PIN at all. This test locks in that the
// unprefixed, unprotected routes are gone.
// ─────────────────────────────────────────────────────────────────────────────

it('no longer exposes the unprotected legacy land purchase route', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => Hash::make('1234'),
    ]);

    // Deliberately omitting transaction_pin from the payload — if this
    // route still existed and still bypassed CheckTransactionPin, a
    // non-404 response here would mean the bypass is back.
    $response = $this->actingAs($user, 'api')
        ->postJson('/lands/1/purchase', ['units' => 1]);

    $response->assertStatus(404);
});

it('no longer exposes the unprotected legacy sell route', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'transaction_pin'   => Hash::make('1234'),
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/lands/1/sell', ['units' => 1]);

    $response->assertStatus(404);
});

it('no longer exposes the dead legacy deposit/withdraw/land routes', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $this->actingAs($user, 'api')->postJson('/deposit')->assertStatus(404);
    $this->actingAs($user, 'api')->postJson('/withdraw')->assertStatus(404);
    $this->actingAs($user, 'api')->postJson('/lands')->assertStatus(404);
    $this->actingAs($user, 'api')->getJson('/lands/1/units')->assertStatus(404);
    $this->actingAs($user, 'api')->getJson('/user/lands')->assertStatus(404);
    $this->getJson('/deposit/callback')->assertStatus(404);
});

it('still serves the root status route', function () {
    $this->getJson('/')->assertStatus(200)->assertJsonPath('status', 'success');
});
