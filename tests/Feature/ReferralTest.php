<?php

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function refUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at' => now(),
        'is_suspended'      => false,
        'screening_status'  => 'clear',
    ], $attrs));
}

function makeReferral(int $referrerId, int $referredId, string $status = 'completed'): Referral
{
    return Referral::create([
        'referrer_id'      => $referrerId,
        'referred_user_id' => $referredId,
        'status'           => $status,
    ]);
}

function makeCashbackReward(int $userId, int $referralId, int $amountKobo = 500_000, bool $claimed = false): ReferralReward
{
    return ReferralReward::create([
        'user_id'     => $userId,
        'referral_id' => $referralId,
        'reward_type' => 'cashback',
        'amount_kobo' => $amountKobo,
        'claimed'     => $claimed,
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// GET /referrals/dashboard
// ─────────────────────────────────────────────────────────────────────────────

describe('Referral dashboard', function () {

    it('returns referral stats and lists for the authenticated user', function () {
        $referrer = refUser();
        $referred = refUser();

        $referral = makeReferral($referrer->id, $referred->id);
        makeCashbackReward($referrer->id, $referral->id, 500_000);

        $this->actingAs($referrer, 'sanctum')
            ->getJson('/api/referrals/dashboard')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_referrals', 1)
            ->assertJsonPath('data.completed_referrals', 1)
            ->assertJsonPath('data.total_rewards_kobo', 500_000)
            ->assertJsonPath('data.unclaimed_rewards_kobo', 500_000);
    });

    it('does not expose the referred user\'s email address to the referrer', function () {
        $referrer = refUser();
        $referred = refUser(['email' => 'referred@secret.com']);

        makeReferral($referrer->id, $referred->id);

        $response = $this->actingAs($referrer, 'sanctum')
            ->getJson('/api/referrals/dashboard')
            ->assertStatus(200);

        $referrals = $response->json('data.referrals');
        foreach ($referrals as $r) {
            expect(isset($r['referred_user']['email']))->toBeFalse();
        }
    });

    it('only returns this user\'s referrals, never another user\'s', function () {
        $referrer      = refUser();
        $otherReferrer = refUser();
        $referred      = refUser();

        makeReferral($otherReferrer->id, $referred->id);

        $response = $this->actingAs($referrer, 'sanctum')
            ->getJson('/api/referrals/dashboard')
            ->assertStatus(200);

        expect($response->json('data.total_referrals'))->toBe(0);
        expect($response->json('data.referrals'))->toBeEmpty();
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /referrals/validate  (public)
// ─────────────────────────────────────────────────────────────────────────────

describe('Referral code validation', function () {

    it('confirms a valid code exists without returning the referrer\'s name', function () {
        $referrer = refUser(['referral_code' => 'TESTCODE1']);

        $response = $this->postJson('/api/referrals/validate', ['code' => 'TESTCODE1'])
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'TESTCODE1');

        // referrer_name was previously returned to unauthenticated callers —
        // that's PII leaked to anyone who iterates the code space.
        expect(array_key_exists('referrer_name', $response->json('data')))->toBeFalse();
    });

    it('returns 404 for an invalid referral code', function () {
        $this->postJson('/api/referrals/validate', ['code' => 'BADCODE99'])
            ->assertStatus(404)
            ->assertJsonPath('success', false);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// POST /referrals/rewards/{id}/claim
// ─────────────────────────────────────────────────────────────────────────────

describe('Referral reward claiming', function () {

    it('credits the rewards wallet when a cashback reward is claimed', function () {
        $user    = refUser(['rewards_balance_kobo' => 0]);
        $referral = makeReferral($user->id, refUser()->id);
        $reward  = makeCashbackReward($user->id, $referral->id, 500_000);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/referrals/rewards/{$reward->id}/claim")
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        expect($user->fresh()->rewards_balance_kobo)->toBe(500_000);
        expect($reward->fresh()->claimed)->toBeTrue();
    });

    it('returns 400 and does not double-credit when a reward is already claimed', function () {
        $user    = refUser(['rewards_balance_kobo' => 0]);
        $referral = makeReferral($user->id, refUser()->id);
        $reward  = makeCashbackReward($user->id, $referral->id, 500_000, claimed: true);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/referrals/rewards/{$reward->id}/claim")
            ->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Reward already claimed');

        // Balance must not have changed.
        expect($user->fresh()->rewards_balance_kobo)->toBe(0);
    });

    it('returns 404 when the reward belongs to a different user', function () {
        $owner   = refUser();
        $other   = refUser();
        $referral = makeReferral($owner->id, refUser()->id);
        $reward  = makeCashbackReward($owner->id, $referral->id);

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/referrals/rewards/{$reward->id}/claim")
            ->assertStatus(404);
    });
});
