<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers — prefixed with pf_ to avoid collisions with other test files' globals
// ─────────────────────────────────────────────────────────────────────────────

function pfUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'is_suspended'      => false,
        'screening_status'  => 'clear',
    ], $attrs));
}

function pfSnapshot(int $userId, string $date, int $investedKobo, int $valueKobo): void
{
    $profitLoss    = $valueKobo - $investedKobo;
    $profitLossPct = $investedKobo > 0 ? round(($profitLoss / $investedKobo) * 100, 4) : 0;

    DB::table('portfolio_daily_snapshots')->insert([
        'user_id'                    => $userId,
        'snapshot_date'              => $date,
        'total_units'                => 10,
        'total_invested_kobo'        => $investedKobo,
        'total_portfolio_value_kobo' => $valueKobo,
        'profit_loss_kobo'           => $profitLoss,
        'profit_loss_percent'        => $profitLossPct,
        'created_at'                 => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /portfolio/performance
//
// annualizedReturn extrapolates a holding period out to 365 days via
// pow(current/invested, 365/daysSinceFirst). A large early gain over a very
// short period can overflow to INF, which crashes json_encode (Laravel's
// JsonResponse throws rather than silently dropping it) instead of just
// returning a bad number.
// ─────────────────────────────────────────────────────────────────────────────

describe('Portfolio performance', function () {

    it('returns null data when the user has no snapshots yet', function () {
        $user = pfUser();

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/performance')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', null);
    });

    it('computes a normal annualized return for an established position', function () {
        $user = pfUser();

        pfSnapshot($user->id, now()->subDays(30)->toDateString(), 1_000_000, 1_000_000);
        pfSnapshot($user->id, now()->toDateString(), 1_000_000, 1_100_000); // +10% over 30 days

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/performance')
            ->assertStatus(200);

        expect($response->json('data.annualized_return_pct'))->not->toBeNull();
        expect($response->json('data.annualized_return_pct'))->toBeGreaterThan(0);
    });

    it('does not attempt to annualize a position held for only a few days', function () {
        $user = pfUser();

        // 2-day-old position with a big gain — extrapolating this to 365
        // days would previously produce a nonsensical (or INF) number.
        pfSnapshot($user->id, now()->subDays(2)->toDateString(), 100_000, 100_000);
        pfSnapshot($user->id, now()->toDateString(), 100_000, 300_000); // 3x in 2 days

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/performance')
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        // Under the 7-day floor — no extrapolation attempted.
        expect($response->json('data.annualized_return_pct'))->toBeNull();
    });

    it('does not crash or return non-finite JSON for an extreme early gain at the 7-day boundary', function () {
        $user = pfUser();

        // Ratio chosen so pow(current/invested, 365/7) genuinely overflows
        // to INF under the old, unguarded formula (verified: 1e7 ratio at
        // exponent 365/7 ≈ 52.1 exceeds PHP_FLOAT_MAX).
        pfSnapshot($user->id, now()->subDays(7)->toDateString(), 100_000, 100_000);
        pfSnapshot($user->id, now()->toDateString(), 100_000, 1_000_000_000_000); // 1e7x in 7 days

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/portfolio/performance');

        // The old code would throw when json_encode hit INF, surfacing as
        // a 500 with an unparseable/empty body. It must still respond
        // cleanly, whether that's a capped finite number or null.
        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $value = $response->json('data.annualized_return_pct');
        expect($value === null || is_finite($value))->toBeTrue();
    });
});
