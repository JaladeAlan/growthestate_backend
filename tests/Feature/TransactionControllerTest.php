<?php

use App\Models\Deposit;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed with tx_ to avoid collisions with other test files' globals
// ─────────────────────────────────────────────────────────────────────────────

function txUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'is_suspended'      => false,
        'screening_status'  => 'clear',
    ], $attrs));
}

function txLandId(): int
{
    return DB::table('lands')->insertGetId([
        'title'           => 'Transaction Test Plot',
        'location'        => 'Lagos',
        'size'            => 500.0,
        'total_units'     => 1000,
        'available_units' => 1000,
        'is_available'    => true,
        'description'     => 'A test land parcel.',
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /transactions/user
//
// Previously fetched every transaction/deposit/withdrawal the user ever
// made, merged and sorted them all in PHP, then sliced out one page —
// meaning every page request cost the same as fetching the full history.
// Now paginated at the DB level via UNION ALL.
// ─────────────────────────────────────────────────────────────────────────────

describe('User transaction history', function () {

    it('merges land transactions, deposits, and withdrawals into one feed, newest first', function () {
        $user   = txUser();
        $landId = txLandId();

        Transaction::create([
            'user_id'          => $user->id,
            'land_id'          => $landId,
            'type'             => 'purchase',
            'units'            => 5,
            'amount_kobo'      => 500_000,
            'status'           => 'completed',
            'reference'        => 'PUR-' . Str::uuid(),
            'transaction_date' => now()->subDays(3),
        ]);

        Deposit::create([
            'user_id'      => $user->id,
            'reference'    => 'DEP-' . Str::uuid(),
            'amount_kobo'  => 1_000_000,
            'total_kobo'   => 1_000_000,
            'status'       => 'completed',
            'gateway'      => 'paystack',
            'created_at'   => now()->subDays(2),
            'updated_at'   => now()->subDays(2),
        ]);

        Withdrawal::create([
            'user_id'     => $user->id,
            'amount_kobo' => 200_000,
            'status'      => 'completed',
            'reference'   => 'WD-' . Str::uuid(),
            'created_at'  => now()->subDay(),
            'updated_at'  => now()->subDay(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions/user')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $types = collect($response->json('data'))->pluck('type');
        expect($types)->toContain('Purchase', 'Deposit', 'Withdrawal');

        // Newest first: withdrawal (1 day ago) before deposit (2 days ago)
        // before the purchase (3 days ago).
        expect($types->take(3)->all())->toBe(['Withdrawal', 'Deposit', 'Purchase']);
    });

    it('only returns this user\'s history, never another user\'s', function () {
        $user       = txUser();
        $otherUser  = txUser();

        Deposit::create([
            'user_id'     => $otherUser->id,
            'reference'   => 'DEP-' . Str::uuid(),
            'amount_kobo' => 999_999,
            'total_kobo'  => 999_999,
            'status'      => 'completed',
            'gateway'     => 'paystack',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions/user')
            ->assertStatus(200);

        expect($response->json('data'))->toBeEmpty();
    });

    it('paginates at the DB level with correct meta (total, last_page)', function () {
        $user = txUser();

        // 25 deposits — more than one page at 20/page.
        for ($i = 0; $i < 25; $i++) {
            Deposit::create([
                'user_id'     => $user->id,
                'reference'   => 'DEP-' . Str::uuid(),
                'amount_kobo' => 10_000,
                'total_kobo'  => 10_000,
                'status'      => 'completed',
                'gateway'     => 'paystack',
                'created_at'  => now()->subMinutes($i),
                'updated_at'  => now()->subMinutes($i),
            ]);
        }

        $page1 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions/user')
            ->assertStatus(200);

        expect($page1->json('data'))->toHaveCount(20);
        expect($page1->json('meta.total'))->toBe(25);
        expect($page1->json('meta.last_page'))->toBe(2);
        expect($page1->json('meta.page'))->toBe(1);

        $page2 = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions/user?page=2')
            ->assertStatus(200);

        expect($page2->json('data'))->toHaveCount(5);
        expect($page2->json('meta.page'))->toBe(2);

        // No overlap between the two pages.
        $page1Refs = collect($page1->json('data'))->pluck('date');
        $page2Refs = collect($page2->json('data'))->pluck('date');
        expect($page1Refs->intersect($page2Refs))->toBeEmpty();
    });

    it('returns an empty feed with correct meta for a user with no history', function () {
        $user = txUser();

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/transactions/user')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.last_page', 1);

        expect($response->json('data'))->toBeEmpty();
    });

    it('returns 401 when unauthenticated', function () {
        $this->getJson('/api/transactions/user')->assertStatus(401);
    });
});
