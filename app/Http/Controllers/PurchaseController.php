<?php

namespace App\Http\Controllers;

use App\Events\LandUnitsPurchased;
use App\Events\LandUnitsSold;
use App\Models\Land;
use App\Models\LedgerEntry;
use App\Models\Purchase;
use App\Models\Referral;
use App\Models\ReferralReward;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserLand;
use App\Services\RewardsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tymon\JWTAuth\Facades\JWTAuth;
use Illuminate\Validation\ValidationException;

class PurchaseController extends Controller
{
    public function purchase(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units'       => ['required', 'integer', 'min:1'],
            'use_rewards' => ['sometimes', 'boolean'],
        ]);

        $useRewards = $request->boolean('use_rewards', true);

        try {
            return DB::transaction(function () use ($request, $landId, $user, $useRewards) {

                $land = Land::with('latestPrice')->lockForUpdate()->findOrFail($landId);

                if (! $land->is_available || $land->available_units < $request->units) {
                    throw ValidationException::withMessages([
                        'units' => 'Insufficient units available.',
                    ]);
                }

                $pricePerUnit = $land->current_price_per_unit_kobo;

                if ($pricePerUnit <= 0) {
                    throw ValidationException::withMessages([
                        'land' => 'This property has no price set. Please contact support.',
                    ]);
                }

                $totalCost = $pricePerUnit * $request->units;

                // ── Step 1: Apply discount reward if available ────────────────
                $discountApplied    = 0;
                $discountRewardUsed = null;

                if ($useRewards) {
                    $discountReward = ReferralReward::where('user_id', $user->id)
                        ->where('reward_type', 'discount')
                        ->where('claimed', false)
                        ->whereNotNull('discount_percentage')
                        ->lockForUpdate()
                        ->first();

                    if ($discountReward) {
                        $discountApplied    = (int) floor($totalCost * ($discountReward->discount_percentage / 100));
                        $discountRewardUsed = $discountReward;
                    }
                }

                $afterDiscount = $totalCost - $discountApplied;

                // ── Step 2: Apply rewards balance ─────────────────────────────
                $rewardsUsed = 0;
                $mainUsed    = $afterDiscount;

                if ($useRewards && $user->rewards_balance_kobo > 0) {
                    $rewardsUsed = min($user->rewards_balance_kobo, $afterDiscount);
                    $mainUsed    = $afterDiscount - $rewardsUsed;
                }

                // ── Step 3: Check main wallet covers remainder ────────────────
                if ($user->balance_kobo < $mainUsed) {
                    throw ValidationException::withMessages([
                        'wallet' => 'Insufficient wallet balance.',
                    ]);
                }

                $reference = 'PUR-' . Str::uuid();

                // ── Mark discount reward as claimed ───────────────────────────
                if ($discountRewardUsed) {
                    $discountRewardUsed->update(['claimed' => true, 'claimed_at' => now()]);
                    Log::info('Discount reward applied at checkout', [
                        'user_id'          => $user->id,
                        'reward_id'        => $discountRewardUsed->id,
                        'discount_kobo'    => $discountApplied,
                        'discount_percent' => $discountRewardUsed->discount_percentage,
                        'reference'        => $reference,
                    ]);
                }

                // ── Debit rewards wallet ──────────────────────────────────────
                if ($rewardsUsed > 0) {
                    RewardsService::spend($user, $rewardsUsed, $reference, "Purchase: {$land->title}");
                }

                // ── Debit main wallet ─────────────────────────────────────────
                if ($mainUsed > 0) {
                    $user->decrement('balance_kobo', $mainUsed);
                    $balanceAfter = $user->fresh()->balance_kobo;

                    LedgerEntry::create([
                        'uid'           => $user->id,
                        'type'          => 'purchase',
                        'amount_kobo'   => $mainUsed,
                        'balance_after' => $balanceAfter,
                        'reference'     => $reference,
                    ]);
                }

                // ── Update land ───────────────────────────────────────────────
                $land->decrement('available_units', $request->units);
                $land->is_available = $land->available_units > 0;
                $land->save();

                $isFirstPurchase = ! Purchase::where('user_id', $user->id)->exists();

                $actualAmountPaid = $rewardsUsed + $mainUsed;

                DB::statement("
                    INSERT INTO purchases
                        (user_id, land_id, units, units_sold,
                         total_amount_paid_kobo, total_amount_received_kobo,
                         status, purchase_date, reference, created_at, updated_at)
                    VALUES (?, ?, ?, 0, ?, 0, 'active', ?, ?, NOW(), NOW())
                    ON CONFLICT (user_id, land_id)
                    DO UPDATE SET
                        units                    = purchases.units + EXCLUDED.units,
                        total_amount_paid_kobo   = purchases.total_amount_paid_kobo + EXCLUDED.total_amount_paid_kobo,
                        reference                = EXCLUDED.reference,
                        purchase_date            = EXCLUDED.purchase_date,
                        updated_at               = NOW()
                ", [
                    $user->id,
                    $land->id,
                    $request->units,
                    $actualAmountPaid,
                    now()->toDateTimeString(),
                    $reference,
                ]);

                // ── Portfolio ─────────────────────────────────────────────────
                DB::statement("
                    INSERT INTO user_land (user_id, land_id, units, created_at, updated_at)
                    VALUES (?, ?, ?, NOW(), NOW())
                    ON CONFLICT (user_id, land_id)
                    DO UPDATE SET
                        units      = user_land.units + EXCLUDED.units,
                        updated_at = NOW()
                ", [$user->id, $land->id, $request->units]);

                // ── Transaction log ───────────────────────────────────────────
                Transaction::create([
                    'user_id'          => $user->id,
                    'land_id'          => $land->id,
                    'type'             => 'purchase',
                    'units'            => $request->units,
                    'amount_kobo'      => $totalCost,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                ]);

                if ($isFirstPurchase) {
                    $this->completeReferral($user);
                }

                event(new LandUnitsPurchased($user->id, $land->id, $request->units, $pricePerUnit, $totalCost));

                return response()->json([
                    'message'                   => 'Purchase successful.',
                    'reference'                 => $reference,
                    'original_cost_kobo'        => $totalCost,
                    'discount_applied_kobo'     => $discountApplied,
                    'discount_percent'          => $discountRewardUsed?->discount_percentage ?? 0,
                    'paid_from_rewards_kobo'    => $rewardsUsed,
                    'paid_from_wallet_kobo'     => $mainUsed,
                    'total_paid_kobo'           => $actualAmountPaid,
                    'remaining_units'           => $land->fresh()->available_units,
                ]);
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Purchase failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $user->id,
                'land_id' => $landId,
            ]);
            return response()->json(['error' => 'Purchase failed. Please try again.'], 500);
        }
    }

    /**
     * SELL LAND UNITS
     * Sale proceeds go to the main wallet.
     */
    public function sellUnits(Request $request, $landId)
    {
        $user = JWTAuth::parseToken()->authenticate();

        $request->validate([
            'units' => ['required', 'integer', 'min:1'],
        ]);

        try {
            return DB::transaction(function () use ($request, $landId, $user) {

                $purchase = Purchase::where('user_id', $user->id)
                    ->where('land_id', $landId)
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($purchase->units < $request->units) {
                    throw ValidationException::withMessages(['units' => 'Not enough units to sell.']);
                }

                $land = Land::with('latestPrice')->lockForUpdate()->findOrFail($landId);

                $pricePerUnit = $land->current_price_per_unit_kobo;

                if ($pricePerUnit <= 0) {
                    throw ValidationException::withMessages([
                        'land' => 'This property has no price set. Please contact support.',
                    ]);
                }

                $totalReceived  = $pricePerUnit * $request->units;
                $reference      = 'SALE-' . Str::uuid();
                $remainingUnits = $purchase->units - $request->units;

                $purchase->update([
                    'units'                      => $remainingUnits,
                    'units_sold'                 => $purchase->units_sold + $request->units,
                    'total_amount_received_kobo' => $purchase->total_amount_received_kobo + $totalReceived,
                    'sell_date'                  => now(),
                    'status'                     => $remainingUnits === 0 ? 'sold_out' : 'partially_sold',
                    'reference'                  => $reference,
                ]);

                // ── Update land ───────────────────────────────────────────────
                $land->increment('available_units', $request->units);
                $land->is_available = true;
                $land->save();

                // ── Credit main wallet ────────────────────────────────────────
                $user->increment('balance_kobo', $totalReceived);
                $balanceAfter = $user->fresh()->balance_kobo;

                LedgerEntry::create([
                    'uid'           => $user->id,
                    'type'          => 'sale',
                    'amount_kobo'   => $totalReceived,
                    'balance_after' => $balanceAfter,
                    'reference'     => $reference,
                ]);

                // ── Update portfolio ──────────────────────────────────────────
                UserLand::where('user_id', $user->id)
                    ->where('land_id', $land->id)
                    ->decrement('units', $request->units);

                // ── Transaction log ───────────────────────────────────────────
                Transaction::create([
                    'user_id'          => $user->id,
                    'land_id'          => $land->id,
                    'type'             => 'sale',
                    'units'            => $request->units,
                    'amount_kobo'      => $totalReceived,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                ]);

                event(new LandUnitsSold($user->id, $land->id, $request->units, $pricePerUnit, $totalReceived));

                return response()->json([
                    'message'               => 'Units sold successfully.',
                    'reference'             => $reference,
                    'amount_received_kobo'  => $totalReceived,
                    'amount_received_naira' => $totalReceived / 100,
                    'available_units'       => $land->available_units,
                ]);
            });

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Sale failed', [
                'error'   => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'user_id' => $user->id,
            ]);
            return response()->json(['error' => 'Sale failed. Please try again.'], 500);
        }
    }

    /**
     * Complete a referral when the referred user makes their first purchase.
     */
    private function completeReferral($referredUser): void
    {
        $referral = Referral::where('referred_user_id', $referredUser->id)
            ->where('status', 'pending')
            ->first();

        if (! $referral) return;

        try {
            DB::transaction(function () use ($referral) {
                $referral->markCompleted();

                ReferralReward::create([
                    'referral_id' => $referral->id,
                    'user_id'     => $referral->referrer_id,
                    'reward_type' => 'cashback',
                    'amount_kobo' => (int) config('rewards.referral_cashback_kobo', 5000),
                    'claimed'     => false,
                ]);

                ReferralReward::create([
                    'referral_id'         => $referral->id,
                    'user_id'             => $referral->referred_user_id,
                    'reward_type'         => 'discount',
                    'discount_percentage' => (int) config('rewards.referral_discount_percent', 10),
                    'claimed'             => false,
                ]);
            });
        } catch (\Exception $e) {
            Log::error('Failed to complete referral', [
                'referral_id' => $referral->id,
                'error'       => $e->getMessage(),
            ]);
        }
    }
}