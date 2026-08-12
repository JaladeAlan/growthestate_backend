<?php

use App\Models\LedgerEntry;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

// ─────────────────────────────────────────────────────────────────────────────
// Helper
// ─────────────────────────────────────────────────────────────────────────────

function kycApprovedUser(array $attrs = []): User
{
    $user = User::factory()->create(array_merge([
        'email_verified_at'  => now(),
        'balance_kobo'       => 20_000_000,
        'transaction_pin'    => Hash::make('1234'),
        'account_number'     => '0123456789',
        'bank_code'          => '058',
        'account_name'       => 'Test User',
        'screening_status'   => 'clear',
        'is_suspended'       => false,
    ], $attrs));

    // Ensure the KYC record exists so is_kyc_verified computed attribute returns true
    \App\Models\KycVerification::create([
        'user_id'      => $user->id,
        'full_name'    => $user->name,
        'date_of_birth' => '1990-01-01',
        'phone_number' => '08012345678',
        'address'      => '1 Test Street',
        'city'         => 'Lagos',
        'state'        => 'Lagos',
        'id_type'      => 'nin',
        'id_number'    => '12345678901',
        'selfie_path'  => 'kyc/selfies/test.jpg',
        'status'       => 'approved',
        'verified_at'  => now(),
    ]);

    return $user;
}

// ─────────────────────────────────────────────────────────────────────────────
// Withdrawal request (user)
// ─────────────────────────────────────────────────────────────────────────────

describe('Withdrawal request', function () {

    it('creates a pending withdrawal and debits user balance', function () {
        $user = kycApprovedUser(['balance_kobo' => 10_000_000]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000, // ₦50,000
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['message', 'reference']);

        // Balance debited immediately
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 5_000_000,
        ]);

        // Withdrawal record created as pending
        $this->assertDatabaseHas('withdrawals', [
            'user_id'    => $user->id,
            'amount_kobo' => 5_000_000,
            'status'     => 'pending',
        ]);

        // Immutable ledger entry written
        $this->assertDatabaseHas('ledger_entries', [
            'uid'  => $user->id,
            'type' => 'withdrawal',
        ]);
    });

    it('does not double-debit when the same Idempotency-Key is replayed', function () {
        $user = kycApprovedUser(['balance_kobo' => 10_000_000]);
        $key  = (string) \Illuminate\Support\Str::uuid();

        $first = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000,
                'transaction_pin' => '1234',
            ]);

        $first->assertStatus(200);

        $second = $this->actingAs($user, 'sanctum')
            ->withHeader('Idempotency-Key', $key)
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000,
                'transaction_pin' => '1234',
            ]);

        $second->assertStatus(200)
            ->assertHeader('X-Idempotent-Replayed', 'true')
            ->assertJson($first->json());

        // Balance debited only once, not twice
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 5_000_000,
        ]);

        expect(Withdrawal::where('user_id', $user->id)->count())->toBe(1);
    });

    it('rejects when balance is insufficient', function () {
        $user = kycApprovedUser(['balance_kobo' => 500_000]); // ₦5,000

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000,
                'transaction_pin' => '1234',
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('withdrawals', ['user_id' => $user->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'balance_kobo' => 500_000]);
    });

    it('rejects a request below the minimum withdrawal amount (₦5,000)', function () {
        $user = kycApprovedUser();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/withdraw', [
                'amount'          => 100_000, // ₦1,000 — below ₦5,000 min
                'transaction_pin' => '1234',
            ])
            ->assertStatus(422);
    });

    it('rejects when KYC is not verified', function () {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'balance_kobo'      => 10_000_000,
            'transaction_pin'   => Hash::make('1234'),
            'account_number'    => '0123456789',
            'bank_code'         => '058',
            'screening_status'  => 'clear',
        ]);
        // No KYC record

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000,
                'transaction_pin' => '1234',
            ])
            ->assertStatus(403);
    });

    it('rejects when bank details are missing', function () {
        $user = kycApprovedUser(['account_number' => null, 'bank_code' => null]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000,
                'transaction_pin' => '1234',
            ])
            ->assertStatus(400);
    });

    it('enforces the daily withdrawal limit', function () {
        $user = kycApprovedUser([
            'balance_kobo'               => 100_000_000,
            'withdrawal_daily_total_kobo' => 50_000_000, // already at limit
            'withdrawal_day'             => now()->toDateString(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/withdraw', [
                'amount'          => 5_000_000,
                'transaction_pin' => '1234',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Daily withdrawal limit of ₦500,000.00 reached. Remaining today: ₦0.00.']);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Admin withdrawal approval
// ─────────────────────────────────────────────────────────────────────────────

describe('Admin withdrawal approval', function () {

    beforeEach(function () {
        Http::fake([
            'api.paystack.co/transferrecipient' => Http::response([
                'status' => true,
                'data'   => ['recipient_code' => 'RCP_test123'],
            ], 200),
            'api.paystack.co/transfer' => Http::response([
                'status' => true,
                'data'   => ['transfer_code' => 'TRF_test456'],
            ], 200),
        ]);
    });

    it('approves a pending withdrawal and initiates a Paystack transfer', function () {
        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin'          => true,
        ]);

        $user = kycApprovedUser(['balance_kobo' => 0]);

        $withdrawal = Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 5_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-APPROVE-001',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('withdrawals', [
            'id'     => $withdrawal->id,
            'status' => 'processing',
        ]);

        Http::assertSent(fn ($req) =>
            str_contains($req->url(), 'paystack.co/transfer')
        );
    });

    it('marks the withdrawal as failed (not pending) when the Paystack transfer call errors, so it cannot be silently re-approved', function () {
        Http::fake([
            'api.paystack.co/transferrecipient' => Http::response([
                'status' => true,
                'data'   => ['recipient_code' => 'RCP_test123'],
            ], 200),
            'api.paystack.co/transfer' => Http::response([
                'status'  => false,
                'message' => 'Insufficient balance in Paystack account.',
            ], 400),
        ]);

        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin'          => true,
        ]);

        $user = kycApprovedUser(['balance_kobo' => 0]);

        $withdrawal = Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 5_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-FAIL-001',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve");

        $response->assertStatus(502);

        // The withdrawal must land on 'failed', never bounce back to
        // 'pending' — a row stuck at 'pending' after a Paystack error is
        // eligible for a second approval click, which could double-transfer
        // funds if the first call actually reached Paystack before failing.
        $this->assertDatabaseHas('withdrawals', [
            'id'     => $withdrawal->id,
            'status' => 'failed',
        ]);

        $this->assertDatabaseMissing('withdrawals', [
            'id'     => $withdrawal->id,
            'status' => 'pending',
        ]);
    });

    it('rejects a pending withdrawal and refunds the user balance', function () {
        Notification::fake();

        $admin = User::factory()->create([
            'email_verified_at' => now(),
            'is_admin'          => true,
        ]);

        $user = kycApprovedUser(['balance_kobo' => 0]);

        $withdrawal = Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 3_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-REJECT-001',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/reject", [
                'reason' => 'Suspicious activity detected.',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('withdrawals', [
            'id'     => $withdrawal->id,
            'status' => 'rejected',
        ]);

        // Balance restored
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 3_000_000,
        ]);

        // Reversal ledger entry
        $this->assertDatabaseHas('ledger_entries', [
            'uid'  => $user->id,
            'type' => 'withdrawal_reversal',
        ]);
    });

    it('cannot approve a withdrawal that is not pending', function () {
        $admin = User::factory()->create(['email_verified_at' => now(), 'is_admin' => true]);
        $user  = kycApprovedUser(['balance_kobo' => 0]);

        $withdrawal = Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 5_000_000,
            'status'      => 'completed',
            'reference'   => 'WD-COMP-001',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(422);
    });

    it('blocks a non-admin from accessing the approval endpoint', function () {
        $regularUser = User::factory()->create(['email_verified_at' => now(), 'is_admin' => false]);
        $withdrawal  = Withdrawal::create([
            'user_id'     => $regularUser->id,
            'amount_kobo' => 5_000_000,
            'status'      => 'pending',
            'reference'   => 'WD-NON-ADMIN-001',
        ]);

        $this->actingAs($regularUser, 'api')
            ->postJson("/api/admin/withdrawals/{$withdrawal->id}/approve")
            ->assertStatus(403);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Paystack transfer webhook (withdrawal completion)
// ─────────────────────────────────────────────────────────────────────────────

describe('Paystack withdrawal webhook', function () {

    it('marks a processing withdrawal as completed on transfer.success', function () {
        Notification::fake();
        config(['services.paystack.secret_key' => 'test-secret']);

        $user = kycApprovedUser(['balance_kobo' => 0]);

        $withdrawal = Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 5_000_000,
            'status'      => 'processing',
            'reference'   => 'WD-WEBHOOK-001',
        ]);

        $payload = json_encode([
            'event' => 'transfer.success',
            'data'  => [
                'reference' => 'WD-WEBHOOK-001',
                'status'    => 'success',
            ],
        ]);
        $sig = hash_hmac('sha512', $payload, 'test-secret');

        $this->postJson('/api/paystack/webhook', json_decode($payload, true), [
            'x-paystack-signature' => $sig,
        ])->assertStatus(200);

        $this->assertDatabaseHas('withdrawals', [
            'id'     => $withdrawal->id,
            'status' => 'completed',
        ]);
    });

    it('refunds the user on transfer.failed', function () {
        Notification::fake();
        config(['services.paystack.secret_key' => 'test-secret']);

        $user = kycApprovedUser(['balance_kobo' => 0]);

        $withdrawal = Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 5_000_000,
            'status'      => 'processing',
            'reference'   => 'WD-FAIL-WEBHOOK-001',
        ]);

        $payload = json_encode([
            'event' => 'transfer.failed',
            'data'  => [
                'reference' => 'WD-FAIL-WEBHOOK-001',
                'status'    => 'failed',
            ],
        ]);
        $sig = hash_hmac('sha512', $payload, 'test-secret');

        $this->postJson('/api/paystack/webhook', json_decode($payload, true), [
            'x-paystack-signature' => $sig,
        ])->assertStatus(200);

        $this->assertDatabaseHas('withdrawals', [
            'id'     => $withdrawal->id,
            'status' => 'rejected',
        ]);

        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 5_000_000, // refunded
        ]);
    });
});
