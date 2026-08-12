<?php

use App\Jobs\GenerateDailyPortfolioSnapshot;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed with snap_ to avoid global function name collisions
// with other test files (e.g. PurchaseTest's makeLand/makeVerifiedUser)
// ─────────────────────────────────────────────────────────────────────────────

function snapUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'is_suspended'      => false,
        'screening_status'  => 'clear',
    ], $attrs));
}

function snapLand(array $attrs = []): int
{
    return DB::table('lands')->insertGetId(array_merge([
        'title'           => 'Snapshot Test Plot',
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

function snapPrice(int $landId, int $priceKobo, string $date): void
{
    DB::table('land_price_history')->insert([
        'land_id'             => $landId,
        'price_per_unit_kobo' => $priceKobo,
        'price_date'          => $date,
        'created_at'          => now(),
    ]);
}

function snapHolding(int $userId, int $landId, int $units): void
{
    DB::table('user_land')->insert([
        'user_id'    => $userId,
        'land_id'    => $landId,
        'units'      => $units,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function snapPurchase(int $userId, int $landId, int $units, int $paidKobo, string $purchaseDate): void
{
    DB::table('purchases')->insert([
        'user_id'                    => $userId,
        'land_id'                    => $landId,
        'units'                      => $units,
        'units_sold'                 => 0,
        'total_amount_paid_kobo'     => $paidKobo,
        'total_amount_received_kobo' => 0,
        'status'                     => 'active',
        'purchase_date'              => $purchaseDate,
        'reference'                  => 'SNAP-SEED-' . $userId . '-' . $landId,
        'created_at'                 => now(),
        'updated_at'                 => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GenerateDailyPortfolioSnapshot job
// ─────────────────────────────────────────────────────────────────────────────

describe('GenerateDailyPortfolioSnapshot job', function () {

    it('creates asset and daily snapshot rows with correct valuation and profit/loss', function () {
        $user   = snapUser();
        $landId = snapLand();
        $date   = now()->toDateString();

        snapPrice($landId, 200_000, $date); // ₦2,000/unit
        snapHolding($user->id, $landId, 10);
        snapPurchase($user->id, $landId, 10, 1_500_000, $date); // paid ₦15,000 for 10 units

        GenerateDailyPortfolioSnapshot::dispatchSync($date);

        $this->assertDatabaseHas('portfolio_asset_snapshots', [
            'user_id'       => $user->id,
            'land_id'       => $landId,
            'snapshot_date' => $date,
            'value_kobo'    => 2_000_000, // 10 * 200,000
        ]);

        $daily = DB::table('portfolio_daily_snapshots')
            ->where('user_id', $user->id)
            ->where('snapshot_date', $date)
            ->first();

        expect($daily)->not->toBeNull();
        expect((int) $daily->total_units)->toBe(10);
        expect((int) $daily->total_invested_kobo)->toBe(1_500_000);
        expect((int) $daily->total_portfolio_value_kobo)->toBe(2_000_000);
        expect((int) $daily->profit_loss_kobo)->toBe(500_000); // 2,000,000 - 1,500,000
        expect((float) $daily->profit_loss_percent)->toBeGreaterThan(33.0)
            ->toBeLessThan(33.4); // 500,000 / 1,500,000 ≈ 33.33%
    });

    it('is idempotent: running twice for the same date does not duplicate rows', function () {
        $user   = snapUser();
        $landId = snapLand();
        $date   = now()->toDateString();

        snapPrice($landId, 200_000, $date);
        snapHolding($user->id, $landId, 10);
        snapPurchase($user->id, $landId, 10, 1_500_000, $date);

        GenerateDailyPortfolioSnapshot::dispatchSync($date);
        GenerateDailyPortfolioSnapshot::dispatchSync($date);

        expect(DB::table('portfolio_daily_snapshots')
            ->where('user_id', $user->id)
            ->where('snapshot_date', $date)
            ->count())->toBe(1);

        expect(DB::table('portfolio_asset_snapshots')
            ->where('user_id', $user->id)
            ->where('snapshot_date', $date)
            ->count())->toBe(1);
    });

    it('skips a holding when no price exists for that land on or before the snapshot date', function () {
        $user   = snapUser();
        $landId = snapLand();
        $date   = now()->toDateString();

        // No snapPrice() call — land has no price history at all.
        snapHolding($user->id, $landId, 10);
        snapPurchase($user->id, $landId, 10, 1_500_000, $date);

        GenerateDailyPortfolioSnapshot::dispatchSync($date);

        $this->assertDatabaseMissing('portfolio_asset_snapshots', [
            'user_id' => $user->id,
            'land_id' => $landId,
        ]);

        $this->assertDatabaseMissing('portfolio_daily_snapshots', [
            'user_id' => $user->id,
        ]);
    });

    it('produces nothing when there are no holdings at all', function () {
        $date = now()->toDateString();

        GenerateDailyPortfolioSnapshot::dispatchSync($date);

        expect(DB::table('portfolio_daily_snapshots')->where('snapshot_date', $date)->count())->toBe(0);
        expect(DB::table('portfolio_asset_snapshots')->where('snapshot_date', $date)->count())->toBe(0);
    });

    it('uses the most recent price on or before the snapshot date, not a later one', function () {
        $user   = snapUser();
        $landId = snapLand();
        $today  = now()->toDateString();
        $future = now()->addDays(5)->toDateString();

        snapPrice($landId, 200_000, $today);
        snapPrice($landId, 999_999, $future); // should NOT be picked up for today's snapshot
        snapHolding($user->id, $landId, 10);
        snapPurchase($user->id, $landId, 10, 1_500_000, $today);

        GenerateDailyPortfolioSnapshot::dispatchSync($today);

        $this->assertDatabaseHas('portfolio_asset_snapshots', [
            'user_id'       => $user->id,
            'land_id'       => $landId,
            'snapshot_date' => $today,
            'value_kobo'    => 2_000_000, // 10 * 200,000, not the future price
        ]);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// GET /portfolio/chart
// ─────────────────────────────────────────────────────────────────────────────

describe('Portfolio chart endpoint', function () {

    it('returns only the requesting user\'s snapshots within the requested date range', function () {
        $user   = snapUser();
        $other  = snapUser();
        $landId = snapLand();

        $inRange    = now()->subDays(5)->toDateString();
        $outOfRange = now()->subDays(60)->toDateString();

        DB::table('portfolio_daily_snapshots')->insert([
            [
                'user_id'                    => $user->id,
                'snapshot_date'               => $inRange,
                'total_units'                 => 10,
                'total_invested_kobo'         => 1_000_000,
                'total_portfolio_value_kobo'  => 1_200_000,
                'profit_loss_kobo'            => 200_000,
                'profit_loss_percent'         => 20.0,
                'created_at'                  => now(),
            ],
            [
                'user_id'                    => $user->id,
                'snapshot_date'               => $outOfRange,
                'total_units'                 => 10,
                'total_invested_kobo'         => 1_000_000,
                'total_portfolio_value_kobo'  => 900_000,
                'profit_loss_kobo'            => -100_000,
                'profit_loss_percent'         => -10.0,
                'created_at'                  => now(),
            ],
            [
                'user_id'                    => $other->id,
                'snapshot_date'               => $inRange,
                'total_units'                 => 5,
                'total_invested_kobo'         => 500_000,
                'total_portfolio_value_kobo'  => 600_000,
                'profit_loss_kobo'            => 100_000,
                'profit_loss_percent'         => 20.0,
                'created_at'                  => now(),
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/chart?days=30');

        $response->assertStatus(200)->assertJsonPath('success', true);

        $dates = collect($response->json('data'))->pluck('snapshot_date');
        expect($dates->contains(fn ($d) => str_starts_with($d, $inRange)))->toBeTrue();
        expect($dates->contains(fn ($d) => str_starts_with($d, $outOfRange)))->toBeFalse();
        expect(count($response->json('data')))->toBe(1);
    });

    it('returns 401 for unauthenticated requests', function () {
        $this->getJson('/api/portfolio/chart')->assertStatus(401);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// GET /portfolio/performance
// ─────────────────────────────────────────────────────────────────────────────

describe('Portfolio performance endpoint', function () {

    it('returns data: null when the user has no snapshots yet', function () {
        $user = snapUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/performance')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    });

    it('computes total profit/loss from the oldest and latest snapshots', function () {
        $user = snapUser();

        DB::table('portfolio_daily_snapshots')->insert([
            [
                'user_id'                    => $user->id,
                'snapshot_date'               => now()->subDays(30)->toDateString(),
                'total_units'                 => 10,
                'total_invested_kobo'         => 1_000_000,
                'total_portfolio_value_kobo'  => 1_000_000,
                'profit_loss_kobo'            => 0,
                'profit_loss_percent'         => 0.0,
                'created_at'                  => now(),
            ],
            [
                'user_id'                    => $user->id,
                'snapshot_date'               => now()->toDateString(),
                'total_units'                 => 10,
                'total_invested_kobo'         => 1_000_000,
                'total_portfolio_value_kobo'  => 1_300_000,
                'profit_loss_kobo'            => 300_000,
                'profit_loss_percent'         => 30.0,
                'created_at'                  => now(),
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/performance');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_invested_kobo', 1_000_000)
            ->assertJsonPath('data.current_value_kobo', 1_300_000)
            ->assertJsonPath('data.total_profit_loss_kobo', 300_000)
            ->assertJsonPath('data.total_roi_percent', '30.0000');
    });
});
