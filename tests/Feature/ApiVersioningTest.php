<?php

use App\Models\User;

// ─────────────────────────────────────────────────────────────────────────────
// Confirms the /v1 aliasing in routes/api.php actually works: every
// non-webhook route is reachable at both the unprefixed path (unchanged,
// what the frontend uses today) and the /v1 path (new). Also confirms the
// webhook routes were NOT duplicated under /v1, since they're called by
// external providers at a fixed, dashboard-configured URL.
// ─────────────────────────────────────────────────────────────────────────────

it('serves a public route at both the unprefixed and /v1 paths identically', function () {
    $unprefixed = $this->getJson('/api/land');
    $v1         = $this->getJson('/api/v1/land');

    $unprefixed->assertStatus($v1->getStatusCode());
    expect($unprefixed->json())->toEqual($v1->json());
});

it('serves an authenticated route at both the unprefixed and /v1 paths identically', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $unprefixed = $this->actingAs($user, 'api')->getJson('/api/me');
    $v1         = $this->actingAs($user, 'api')->getJson('/api/v1/me');

    $unprefixed->assertStatus($v1->getStatusCode());
});

it('does not expose payment webhooks under /v1', function () {
    // Webhooks are registered once, unprefixed only — a request to the /v1
    // variant should 404, not silently succeed with an unverified payload.
    $this->postJson('/api/v1/paystack/webhook')->assertStatus(404);
    $this->postJson('/api/v1/monnify/webhook')->assertStatus(404);
    $this->postJson('/api/v1/opay/webhook')->assertStatus(404);
});

it('still serves payment webhooks at the unprefixed path', function () {
    // Not asserting 200 here — the real handler will reject an empty/
    // unsigned payload with 4xx. The point is that it's routed at all
    // (not a 404), proving the webhook registration itself is intact.
    $response = $this->postJson('/api/paystack/webhook');
    expect($response->getStatusCode())->not->toBe(404);
});
