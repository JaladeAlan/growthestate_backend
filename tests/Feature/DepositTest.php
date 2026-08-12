<?php

use App\Models\Deposit;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

// ─────────────────────────────────────────────────────────────────────────────
// Helper: Paystack HMAC signature
// ─────────────────────────────────────────────────────────────────────────────

function paystackSig(string $payload, string $secret = 'test-secret'): string
{
    return hash_hmac('sha512', $payload, $secret);
}

// ─────────────────────────────────────────────────────────────────────────────
// Deposit initiation
// ─────────────────────────────────────────────────────────────────────────────

describe('Deposit initiation', function () {

    it('creates a pending deposit record and returns a payment URL', function () {
        Http::fake([
            'api.paystack.co/*' => Http::response([
                'status' => true,
                'data'   => [
                    'authorization_url' => 'https://checkout.paystack.com/test123',
                    'reference'         => 'DEP-test',
                ],
            ], 200),
        ]);

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/deposit', [
                'amount'  => 500_000,   // ₦5,000 in kobo
                'gateway' => 'paystack',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['payment_url', 'reference', 'gateway', 'transaction_fee', 'total_amount']);

        $this->assertDatabaseHas('deposits', [
            'user_id' => $user->id,
            'status'  => 'pending',
            'gateway' => 'paystack',
        ]);
    });

    it('rejects a deposit below the minimum amount (₦1,000)', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/deposit', [
                'amount'  => 50_000, // ₦500
                'gateway' => 'paystack',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('deposits', ['user_id' => $user->id]);
    });

    it('calculates a 2% fee capped at ₦3,000', function () {
        Http::fake([
            'api.paystack.co/*' => Http::response([
                'status' => true,
                'data'   => [
                    'authorization_url' => 'https://checkout.paystack.com/test123',
                    'reference'         => 'DEP-test',
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        // ₦5,000,000 — fee would be ₦100,000 but cap is ₦3,000
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/deposit', [
                'amount'  => 500_000_000, // ₦5,000,000
                'gateway' => 'paystack',
            ]);

        $deposit = Deposit::where('user_id', $user->id)->first();
        expect($deposit->transaction_fee)->toBeLessThanOrEqual(300_000); // ₦3,000 cap
    });

    it('deletes the deposit record when the gateway returns no URL', function () {
        Http::fake([
            'api.paystack.co/*' => Http::response([
                'status' => true,
                'data'   => ['authorization_url' => null],
            ], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/deposit', [
                'amount'  => 500_000,
                'gateway' => 'paystack',
            ])
            ->assertStatus(500);

        $this->assertDatabaseMissing('deposits', ['user_id' => $user->id]);
    });

    it('returns the cached response and does not create a duplicate deposit when the same Idempotency-Key is replayed', function () {
        Http::fake([
            'api.paystack.co/*' => Http::response([
                'status' => true,
                'data'   => [
                    'authorization_url' => 'https://checkout.paystack.com/test123',
                    'reference'         => 'DEP-test',
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);
        $key  = (string) \Illuminate\Support\Str::uuid();

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/deposit', ['amount' => 500_000, 'gateway' => 'paystack']);

        $first->assertStatus(200);

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/deposit', ['amount' => 500_000, 'gateway' => 'paystack']);

        $second->assertStatus(200)
            ->assertHeader('X-Idempotent-Replayed', 'true')
            ->assertJson($first->json());

        // Only one deposit record was ever created
        expect(Deposit::where('user_id', $user->id)->count())->toBe(1);
    });

    it('creates a separate deposit when a different Idempotency-Key is used', function () {
        Http::fake([
            'api.paystack.co/*' => Http::response([
                'status' => true,
                'data'   => [
                    'authorization_url' => 'https://checkout.paystack.com/test123',
                    'reference'         => 'DEP-test',
                ],
            ], 200),
        ]);

        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/deposit', ['amount' => 500_000, 'gateway' => 'paystack'])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/deposit', ['amount' => 500_000, 'gateway' => 'paystack'])
            ->assertStatus(200);

        expect(Deposit::where('user_id', $user->id)->count())->toBe(2);
    });

    it('returns 401 for unauthenticated requests', function () {
        $this->postJson('/api/deposit', ['amount' => 500_000, 'gateway' => 'paystack'])
            ->assertStatus(401);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Paystack webhook (deposit: charge.success)
// ─────────────────────────────────────────────────────────────────────────────

describe('Paystack deposit webhook', function () {

    it('credits user balance on charge.success and marks deposit completed', function () {
        Notification::fake();

        config(['services.paystack.secret_key' => 'test-secret']);

        $user = User::factory()->create([
            'email_verified_at' => now(),
            'balance_kobo'      => 0,
        ]);

        $deposit = Deposit::create([
            'user_id'         => $user->id,
            'reference'       => 'DEP-webhook-001',
            'amount_kobo'     => 1_000_000,
            'transaction_fee' => 20_000,
            'total_kobo'      => 1_020_000,
            'gateway'         => 'paystack',
            'status'          => 'pending',
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data'  => [
                'reference' => 'DEP-webhook-001',
                'amount'    => 1_020_000,
                'status'    => 'success',
                'metadata'  => [],
            ],
        ]);

        $sig = paystackSig($payload);

        $response = $this->postJson('/api/paystack/webhook', json_decode($payload, true), [
            'x-paystack-signature' => $sig,
            'Content-Type'         => 'application/json',
        ]);

        // Webhook must always respond 200 to avoid Paystack retrying
        $response->assertStatus(200);

        // Deposit marked completed
        $this->assertDatabaseHas('deposits', [
            'id'     => $deposit->id,
            'status' => 'completed',
        ]);

        // User wallet credited with the net amount
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 1_000_000, // credited with the deposit amount (not the fee)
        ]);
    });

    it('is idempotent: a duplicate webhook does not double-credit', function () {
        Notification::fake();
        config(['services.paystack.secret_key' => 'test-secret']);

        $user = User::factory()->create(['email_verified_at' => now(), 'balance_kobo' => 0]);

        Deposit::create([
            'user_id'         => $user->id,
            'reference'       => 'DEP-idempotent-01',
            'amount_kobo'     => 500_000,
            'transaction_fee' => 10_000,
            'total_kobo'      => 510_000,
            'gateway'         => 'paystack',
            'status'          => 'completed',   // already processed
            'processed_at'    => now(),
        ]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data'  => [
                'reference' => 'DEP-idempotent-01',
                'amount'    => 510_000,
                'status'    => 'success',
                'metadata'  => [],
            ],
        ]);

        $sig = paystackSig($payload);

        $this->postJson('/api/paystack/webhook', json_decode($payload, true), [
            'x-paystack-signature' => $sig,
        ])->assertStatus(200);

        // Balance should remain 0 (no second credit)
        $this->assertDatabaseHas('users', ['id' => $user->id, 'balance_kobo' => 0]);
    });

    it('rejects a webhook with an invalid signature', function () {
        config(['services.paystack.secret_key' => 'real-secret']);

        $payload = json_encode([
            'event' => 'charge.success',
            'data'  => ['reference' => 'DEP-fake', 'amount' => 100, 'status' => 'success'],
        ]);

        $this->postJson('/api/paystack/webhook', json_decode($payload, true), [
            'x-paystack-signature' => 'bad-signature',
        ])->assertStatus(403);
    });

    it('returns 400 when the signature header is missing', function () {
        $payload = json_encode([
            'event' => 'charge.success',
            'data'  => ['reference' => 'DEP-nosig', 'amount' => 100, 'status' => 'success'],
        ]);

        $this->postJson('/api/paystack/webhook', json_decode($payload, true))
            ->assertStatus(400);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Deposit status verification endpoint
// ─────────────────────────────────────────────────────────────────────────────

describe('Deposit status endpoint', function () {

    it('returns deposit status for the authenticated owner', function () {
        $user = User::factory()->create(['email_verified_at' => now()]);

        $deposit = Deposit::create([
            'user_id'         => $user->id,
            'reference'       => 'DEP-status-check',
            'amount_kobo'     => 300_000,
            'transaction_fee' => 6_000,
            'total_kobo'      => 306_000,
            'gateway'         => 'paystack',
            'status'          => 'completed',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/deposit/verify/{$deposit->reference}")
            ->assertStatus(200)
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('reference', 'DEP-status-check');
    });

    it('returns 404 for a reference that belongs to another user', function () {
        $ownerUser = User::factory()->create(['email_verified_at' => now()]);
        $otherUser = User::factory()->create(['email_verified_at' => now()]);

        Deposit::create([
            'user_id'         => $ownerUser->id,
            'reference'       => 'DEP-other-user',
            'amount_kobo'     => 100_000,
            'transaction_fee' => 2_000,
            'total_kobo'      => 102_000,
            'gateway'         => 'paystack',
            'status'          => 'pending',
        ]);

        $this->actingAs($otherUser, 'api')
            ->getJson('/api/deposit/verify/DEP-other-user')
            ->assertStatus(404);
    });
});
