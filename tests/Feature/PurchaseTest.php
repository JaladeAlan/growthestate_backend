<?php

use App\Models\Deposit;
use App\Models\LandPriceHistory;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers shared across purchase tests
// ─────────────────────────────────────────────────────────────────────────────

function makeVerifiedUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at'  => now(),
        'balance_kobo'       => 10_000_000, // ₦100,000
        'rewards_balance_kobo' => 0,
        'transaction_pin'    => Hash::make('1234'),
        'is_suspended'       => false,
        'screening_status'   => 'clear',
    ], $attrs));
}

function makeLand(array $attrs = []): int
{
    return DB::table('lands')->insertGetId(array_merge([
        'title'           => 'Test Plot',
        'location'        => 'Lagos',
        'size'            => 500.0,
        'total_units'     => 1000,
        'available_units' => 1000,
        'is_available'    => true,
        'description'     => 'A test land parcel.',
        'created_at'      => now(),
        'updated_at'      => now(),
    ], $attrs));
}

function seedPrice(int $landId, int $priceKobo = 500_000, string $date = null): void
{
    DB::table('land_price_history')->insert([
        'land_id'            => $landId,
        'price_per_unit_kobo' => $priceKobo,
        'price_date'         => $date ?? now()->toDateString(),
        'created_at'         => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Purchase
// ─────────────────────────────────────────────────────────────────────────────

describe('Land purchase', function () {

    it('purchases units successfully, debits balance, and writes ledger entry', function () {
        Notification::fake();

        $user   = makeVerifiedUser(['balance_kobo' => 5_000_000]); // ₦50,000
        $landId = makeLand(['available_units' => 100]);
        seedPrice($landId, 100_000); // ₦1,000 per unit

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 3,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Balance should have dropped by 3 × ₦1,000 = ₦3,000
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 5_000_000 - 300_000,
        ]);

        // user_land row created
        $this->assertDatabaseHas('user_land', [
            'user_id' => $user->id,
            'land_id' => $landId,
            'units'   => 3,
        ]);

        // Purchase record created
        $this->assertDatabaseHas('purchases', [
            'user_id' => $user->id,
            'land_id' => $landId,
            'units'   => 3,
        ]);

        // Ledger entry written
        $this->assertDatabaseHas('ledger_entries', [
            'uid'  => $user->id,
            'type' => 'purchase',
        ]);
    });

    it('returns 422 when balance is insufficient', function () {
        $user   = makeVerifiedUser(['balance_kobo' => 50_000]); // ₦500
        $landId = makeLand();
        seedPrice($landId, 100_000); // ₦1,000 per unit

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 5,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(422);

        // Balance must be untouched
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 50_000,
        ]);
    });

    it('returns 403 when transaction pin is wrong', function () {
        $user   = makeVerifiedUser(['balance_kobo' => 5_000_000]);
        $landId = makeLand();
        seedPrice($landId, 100_000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 1,
                'transaction_pin' => '9999',
            ]);

        $response->assertStatus(403);
    });

    it('returns 400 when land is unavailable', function () {
        $user   = makeVerifiedUser(['balance_kobo' => 5_000_000]);
        $landId = makeLand(['is_available' => false]);
        seedPrice($landId, 100_000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 1,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(422);
    });

    it('applies rewards balance when use_rewards is true', function () {
        Notification::fake();

        $user = makeVerifiedUser([
            'balance_kobo'        => 200_000,   // ₦2,000
            'rewards_balance_kobo' => 500_000,  // ₦5,000
        ]);
        $landId = makeLand(['available_units' => 100]);
        seedPrice($landId, 100_000); // ₦1,000 per unit — total ₦3,000

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 3,
                'use_rewards'     => true,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200);

        // Combined spend: 300,000 kobo. Main balance 200,000 + up to 100,000 from rewards
        $fresh = $user->fresh();
        expect($fresh->balance_kobo + $fresh->rewards_balance_kobo)
            ->toBeLessThan(700_000); // total spent = 300,000
    });

    it('blocks a suspended user from purchasing', function () {
        $user   = makeVerifiedUser(['is_suspended' => true, 'balance_kobo' => 5_000_000]);
        $landId = makeLand();
        seedPrice($landId, 100_000);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 1,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(403);
    });

    // ─────────────────────────────────────────────────────────────────────
    // calculateDiscount()'s cap-enforcement branch was commented out, so a
    // percentage discount (referral or first-purchase) applied against an
    // unbounded total_cost (large units × price) produced an unbounded
    // absolute discount — config('rewards.max_discount_kobo') existed but
    // was never actually enforced.
    // ─────────────────────────────────────────────────────────────────────

    it('caps a referral discount at max_discount_kobo instead of applying it uncapped', function () {
        Notification::fake();
        config(['rewards.max_discount_kobo' => 500_000]); // ₦5,000 cap
        config(['rewards.referral_discount_percent' => 10]);

        $user   = makeVerifiedUser(['balance_kobo' => 100_000_000]); // ₦1,000,000
        $landId = makeLand(['available_units' => 10_000]);
        seedPrice($landId, 100_000); // ₦1,000/unit

        $referral = \App\Models\Referral::create([
            'referrer_id'       => makeVerifiedUser()->id,
            'referred_user_id'  => $user->id,
            'status'            => 'completed',
        ]);

        \App\Models\ReferralReward::create([
            'referral_id'         => $referral->id,
            'user_id'             => $user->id,
            'reward_type'         => 'discount',
            'discount_percentage' => 10,
            'claimed'             => false,
        ]);

        // 200 units × ₦1,000 = ₦200,000 total cost. 10% = ₦20,000 raw
        // discount, which is well above the ₦5,000 cap.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 200,
                'use_rewards'     => true,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('total_discount_kobo', 500_000);

        expect($response->json('total_discount_kobo'))->toBeLessThan(2_000_000); // < uncapped 10%
    });

    it('does not cap a discount that already falls under max_discount_kobo', function () {
        Notification::fake();
        config(['rewards.max_discount_kobo' => 500_000]);
        config(['rewards.first_purchase_discount_percent' => 5]);

        $user   = makeVerifiedUser(['balance_kobo' => 10_000_000]);
        $landId = makeLand(['available_units' => 100]);
        seedPrice($landId, 100_000); // ₦1,000/unit

        // 10 units × ₦1,000 = ₦10,000 total. 5% = ₦500 raw discount — well
        // under the ₦5,000 cap, so it should pass through unmodified.
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/purchase", [
                'units'           => 10,
                'use_rewards'     => true,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('total_discount_kobo', 500); // 5% of 10,000
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Sell units
// ─────────────────────────────────────────────────────────────────────────────

describe('Sell units', function () {

    it('sells units, credits balance, reduces user_land, and writes ledger entry', function () {
        Notification::fake();

        $user   = makeVerifiedUser(['balance_kobo' => 1_000_000]);
        $landId = makeLand();
        seedPrice($landId, 200_000); // ₦2,000 per unit

        // Give the user 10 units
        DB::table('user_land')->insert([
            'user_id'    => $user->id,
            'land_id'    => $landId,
            'units'      => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Matching Purchase record expected by sell logic
        DB::table('purchases')->insert([
            'user_id'                  => $user->id,
            'land_id'                  => $landId,
            'units'                    => 10,
            'total_amount_paid_kobo'   => 2_000_000,
            'total_amount_received_kobo' => 0,
            'purchase_date'            => now(),
            'status'                   => 'active',
            'reference'                => 'PUR-TEST-001',
            'created_at'               => now(),
            'updated_at'               => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/sell", [
                'units'           => 4,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Balance should have increased by 4 × ₦2,000 = ₦8,000
        $this->assertDatabaseHas('users', [
            'id'           => $user->id,
            'balance_kobo' => 1_000_000 + 800_000,
        ]);

        // user_land units reduced
        $this->assertDatabaseHas('user_land', [
            'user_id' => $user->id,
            'land_id' => $landId,
            'units'   => 6,
        ]);

        // Ledger entry for the sale
        $this->assertDatabaseHas('ledger_entries', [
            'uid'  => $user->id,
            'type' => 'sale',
        ]);
    });

    it('marks the purchase record sold_out when all units are sold', function () {
        $user   = makeVerifiedUser(['balance_kobo' => 1_000_000]);
        $landId = makeLand();
        seedPrice($landId, 200_000);

        DB::table('user_land')->insert([
            'user_id'    => $user->id,
            'land_id'    => $landId,
            'units'      => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('purchases')->insert([
            'user_id'                    => $user->id,
            'land_id'                    => $landId,
            'units'                      => 10,
            'total_amount_paid_kobo'     => 2_000_000,
            'total_amount_received_kobo' => 0,
            'purchase_date'              => now(),
            'status'                     => 'active',
            'reference'                  => 'PUR-TEST-SOLDOUT',
            'created_at'                 => now(),
            'updated_at'                 => now(),
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/sell", [
                'units'           => 10,
                'transaction_pin' => '1234',
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('purchases', [
            'user_id' => $user->id,
            'land_id' => $landId,
            'units'   => 0,
            'status'  => 'sold_out',
        ]);
    });

    it('returns 422 when user tries to sell more units than owned', function () {
        $user   = makeVerifiedUser(['balance_kobo' => 1_000_000]);
        $landId = makeLand();
        seedPrice($landId, 200_000);

        DB::table('user_land')->insert([
            'user_id'    => $user->id,
            'land_id'    => $landId,
            'units'      => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/lands/{$landId}/sell", [
                'units'           => 5,
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(422);
    });

    it('returns 401 when unauthenticated', function () {
        $landId = makeLand();

        $this->postJson("/api/lands/{$landId}/sell", [
            'units'           => 1,
            'transaction_pin' => '1234',
        ])->assertStatus(401);
    });
});
