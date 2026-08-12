<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

// ─────────────────────────────────────────────────────────────────────────────
// Verifies SupportController::chat's internal 20-messages/10-minutes limit
// (RateLimiter key 'support-chat:{user_id}') actually blocks the 21st
// message, and that it's scoped per user. The route-level `throttle:20,10`
// middleware sits in front of the same limit, so a single test also
// exercises that layer. Previously untested (item #11).
// ─────────────────────────────────────────────────────────────────────────────

function fakeChatMessage(string $text = 'Tell me about your platform fees'): array
{
    return [
        'messages' => [
            ['role' => 'user', 'content' => $text],
        ],
    ];
}

beforeEach(function () {
    // Every non-FAQ, non-financial-keyword message reaches the OpenAI call —
    // fake it so the test never makes a real network call and never trips
    // on FAQ-table content that may or may not exist in the test DB.
    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'This is a canned test reply.']],
            ],
        ], 200),
    ]);
});

it('allows chat messages under the 20-per-10-minutes limit', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/support/chat', fakeChatMessage());

    $response->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('blocks the 21st chat message within 10 minutes with a 429', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($user, 'api')
            ->postJson('/api/support/chat', fakeChatMessage("Question number {$i}"));
    }

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/support/chat', fakeChatMessage('One too many'));

    $response->assertStatus(429)
        ->assertJsonPath('success', false)
        ->assertJsonStructure(['message', 'retry_after']);
});

it('scopes the chat rate limit per user, not globally', function () {
    $userA = User::factory()->create(['email_verified_at' => now()]);
    $userB = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($userA, 'api')
            ->postJson('/api/support/chat', fakeChatMessage("A question {$i}"));
    }

    $this->actingAs($userA, 'api')
        ->postJson('/api/support/chat', fakeChatMessage('A is now limited'))
        ->assertStatus(429);

    $this->actingAs($userB, 'api')
        ->postJson('/api/support/chat', fakeChatMessage('B should be fine'))
        ->assertStatus(200)
        ->assertJsonPath('success', true);
});

it('does not count auto-escalated financial-keyword messages against the AI rate limit budget differently', function () {
    // Financial-keyword messages short-circuit before the OpenAI call but
    // still pass through the same RateLimiter::hit() — confirm they still
    // count toward the 20-message budget rather than being a free bypass.
    $user = User::factory()->create(['email_verified_at' => now()]);

    for ($i = 0; $i < 20; $i++) {
        $this->actingAs($user, 'api')
            ->postJson('/api/support/chat', fakeChatMessage('my withdrawal failed and money missing'));
    }

    $this->actingAs($user, 'api')
        ->postJson('/api/support/chat', fakeChatMessage('still missing funds'))
        ->assertStatus(429);
});
