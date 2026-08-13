<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

// ─────────────────────────────────────────────────────────────────────────────
// SupportController::chat() embeds the user's real wallet balance, rewards
// balance, and recent deposit/withdrawal amounts into the OpenAI system
// prompt so the assistant can answer naturally, then instructs the model
// never to repeat that data back. That's an instruction, not enforcement —
// a jailbroken reply that doesn't trip the financial-keyword pre-filter
// could still leak it. These tests verify the output-side guard added for
// item #18: any reply containing one of the exact injected values is
// withheld and replaced with a safe canned response instead of returned
// to the user.
// ─────────────────────────────────────────────────────────────────────────────

function chatMessage(string $text): array
{
    return ['messages' => [['role' => 'user', 'content' => $text]]];
}

it('withholds an AI reply that leaks the user\'s real wallet balance', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'wallet_balance'    => 543210, // ₦5,432.10
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Sure — your wallet balance is ₦5,432.10.']],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/support/chat', chatMessage('what is my balance right now'));

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    expect($response->json('data.reply'))
        ->not->toContain('5,432.10');
});

it('passes through a normal AI reply that contains no sensitive values', function () {
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'wallet_balance'    => 543210,
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'You can fund your wallet from the Deposit tab.']],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/support/chat', chatMessage('how do I add money'));

    $response->assertStatus(200)
        ->assertJsonPath('data.reply', 'You can fund your wallet from the Deposit tab.');
});

it('does not false-positive on a zero balance', function () {
    // "0.00" is filtered out of the sensitive-value set specifically because
    // it would otherwise match ordinary replies unrelated to leakage.
    $user = User::factory()->create([
        'email_verified_at' => now(),
        'wallet_balance'    => 0,
        'rewards_balance'   => 0,
    ]);

    Http::fake([
        'api.openai.com/*' => Http::response([
            'choices' => [
                ['message' => ['content' => 'Deposits usually confirm within 0.00 to 2 minutes.']],
            ],
        ], 200),
    ]);

    $response = $this->actingAs($user, 'api')
        ->postJson('/api/support/chat', chatMessage('how long does a deposit take'));

    $response->assertStatus(200)
        ->assertJsonPath('data.reply', 'Deposits usually confirm within 0.00 to 2 minutes.');
});
