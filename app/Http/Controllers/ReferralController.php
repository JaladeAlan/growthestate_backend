<?php

namespace App\Http\Controllers;

use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReferralController extends Controller
{
    private string $frontendUrl;

    public function __construct()
    {
        $this->frontendUrl = config('app.frontend_url');
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();

        $referrals = Referral::where('referrer_id', $user->id)
            ->with('referredUser:id,name,email,created_at')
            ->latest()
            ->get();

        $rewards = ReferralReward::where('user_id', $user->id)
            ->with('referral.referredUser:id,name')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'referral_code'          => $user->referral_code,
                'referral_link'          => "{$this->frontendUrl}/register?ref={$user->referral_code}",
                'total_referrals'        => $referrals->count(),
                'completed_referrals'    => $referrals->where('status', 'completed')->count(),
                'pending_referrals'      => $referrals->where('status', 'pending')->count(),
                'total_rewards_kobo'     => $rewards->sum('amount_kobo'),
                'unclaimed_rewards_kobo' => $rewards->where('claimed', false)->sum('amount_kobo'),
                'referrals'              => $referrals,
                'rewards'                => $rewards,
            ],
        ]);
    }

    public function validateCode(Request $request)
    {
        $request->validate(['code' => 'required|string']);

        $referrer = User::where('referral_code', $request->code)->first();

        if (! $referrer) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid referral code',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'code'          => $referrer->referral_code,
                'referrer_name' => $referrer->name,
            ],
        ]);
    }

    public function applyReferral(User $newUser, string $referralCode): ?Referral
    {
        $referrer = User::where('referral_code', $referralCode)->first();

        if (! $referrer) {
            return null;
        }

        $newUser->update(['referred_by' => $referrer->id]);

        return Referral::create([
            'referrer_id'      => $referrer->id,
            'referred_user_id' => $newUser->id,
            'status'           => 'pending',
        ]);
    }

    /**
     * Claim a pending reward.
     */
    public function claimReward(int $rewardId)
    {
        $reward = ReferralReward::where('id', $rewardId)
            ->where('user_id', auth()->id())
            ->firstOrFail();

        if ($reward->claimed) {
            return response()->json([
                'success' => false,
                'message' => 'Reward already claimed',
            ], 400);
        }

        DB::transaction(function () use ($reward) {
            $reward->claim();

            switch ($reward->reward_type) {

                case 'cashback':
                    if ($reward->amount_kobo > 0) {
                        $user = User::lockForUpdate()->find($reward->user_id);

                        // Credit the REWARDS wallet, not the main wallet
                        $user->creditRewards(
                            $reward->amount_kobo,
                            'REF-REWARD-' . $reward->id,
                            'Referral cashback reward'
                        );

                        Log::info('Referral cashback credited to rewards wallet', [
                            'user_id'              => $user->id,
                            'reward_id'            => $reward->id,
                            'amount_kobo'          => $reward->amount_kobo,
                            'rewards_balance_kobo' => $user->fresh()->rewards_balance_kobo,
                        ]);
                    }
                    break;

                case 'bonus_units':
                    Log::info('Bonus units reward claimed — manual processing required', [
                        'reward_id' => $reward->id,
                        'user_id'   => $reward->user_id,
                    ]);
                    break;

                case 'discount':
                    break;
            }
        });

        return response()->json([
            'success' => true,
            'message' => 'Reward claimed successfully. Your rewards balance has been updated.',
            'data'    => $reward->fresh(),
        ]);
    }

    public function availableRewards()
    {
        $rewards = ReferralReward::where('user_id', auth()->id())
            ->where('claimed', false)
            ->with('referral.referredUser:id,name')
            ->latest()
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $rewards,
        ]);
    }

    public function adminIndex(Request $request)
    {
        $this->authorizeAdmin();

        $query = Referral::with(['referrer:id,name,email', 'referredUser:id,name,email']);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        return response()->json([
            'success' => true,
            'data'    => $query->latest()->paginate(50),
        ]);
    }

   public function adminStats()
    {
        $this->authorizeAdmin();

        $topReferrers = User::select(['id', 'name', 'email', 'referral_code'])
            ->selectRaw('(SELECT COUNT(*) FROM referrals WHERE referrals.referrer_id = users.id) as referrals_count')
            ->whereRaw('(SELECT COUNT(*) FROM referrals WHERE referrals.referrer_id = users.id) > 0')
            ->orderByDesc('referrals_count')
            ->take(10)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_referrals'      => Referral::count(),
                'completed_referrals'  => Referral::completed()->count(),
                'pending_referrals'    => Referral::pending()->count(),
                'total_rewards_issued' => ReferralReward::sum('amount_kobo'),
                'unclaimed_rewards'    => ReferralReward::unclaimed()->sum('amount_kobo'),
                'top_referrers'        => $topReferrers,
            ],
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_admin, 403);
    }
}