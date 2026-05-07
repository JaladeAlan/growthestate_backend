<?php

namespace App\Services;

use App\Models\LedgerEntry;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceTransaction;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\UserLand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceTradeService
{
    const FEE_PCT = 1;

    /**
     * Execute a marketplace trade for an accepted offer.
     *
     * Locks both user rows in a consistent order to prevent deadlocks,
     * then performs all balance, unit, purchase, ledger, and status
     * updates atomically inside a single transaction.
     *
     * @return array  Trade summary (reference, amounts, parties)
     * @throws ValidationException
     */
    public function execute(MarketplaceListing $listing, MarketplaceOffer $offer): array
    {
        return DB::transaction(function () use ($listing, $offer) {

            // ── 0. Re-fetch and lock the offer inside the transaction ─────
            $offer = MarketplaceOffer::where('id', $offer->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($offer->status !== 'pending') {
                throw ValidationException::withMessages([
                    'offer' => 'This offer is no longer pending.',
                ]);
            }

            // ── 0b. Re-check listing still has enough units ───────────────
            $listing = MarketplaceListing::where('id', $listing->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($listing->status !== 'active') {
                throw ValidationException::withMessages([
                    'listing' => 'This listing is no longer active.',
                ]);
            }

            if ($offer->units > $listing->units_for_sale) {
                throw ValidationException::withMessages([
                    'units' => 'Listing no longer has enough units available for this offer.',
                ]);
            }

            // ── 1. Lock both user rows (lower ID first to avoid deadlocks) ─
            $buyerId  = $offer->buyer_id;
            $sellerId = $listing->seller_id;

            [$firstId, $secondId] = $buyerId < $sellerId
                ? [$buyerId,  $sellerId]
                : [$sellerId, $buyerId];

            $users = \App\Models\User::whereIn('id', [$firstId, $secondId])
                ->lockForUpdate()
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $buyer  = $users[$buyerId];
            $seller = $users[$sellerId];

            // ── 2. Derive amounts ─────────────────────────────────────────
            $totalKobo  = $offer->offer_price_kobo * $offer->units;
            $feeKobo    = (int) round($totalKobo * self::FEE_PCT / 100);
            $sellerGets = $totalKobo - $feeKobo;
            $reference  = 'MKT-' . Str::uuid();

            // ── 3. Validate buyer balance ─────────────────────────────────
            if ($buyer->balance_kobo < $totalKobo) {
                throw ValidationException::withMessages([
                    'balance' => 'Buyer has insufficient wallet balance to complete this trade.',
                ]);
            }

            // ── 4. Validate seller still owns enough units ────────────────
            $sellerHolding = UserLand::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->lockForUpdate()
                ->first();

            $sellerUnits = $sellerHolding ? (int) $sellerHolding->units : 0;

            if ($sellerUnits < $offer->units) {
                throw ValidationException::withMessages([
                    'units' => 'Seller no longer has enough units to complete this trade.',
                ]);
            }

            // ── 5. Debit buyer wallet ─────────────────────────────────────
            $buyer->balance_kobo -= $totalKobo;
            $buyer->save();

            LedgerEntry::create([
                'user_id'       => $buyer->id,
                'type'          => 'marketplace_purchase',
                'amount_kobo'   => $totalKobo,
                'balance_after' => $buyer->balance_kobo,
                'reference'     => $reference,
            ]);

            // ── 6. Credit seller wallet (minus fee) ───────────────────────
            $seller->balance_kobo += $sellerGets;
            $seller->save();

            LedgerEntry::create([
                'user_id'       => $seller->id,
                'type'          => 'marketplace_sale',
                'amount_kobo'   => $sellerGets,
                'balance_after' => $seller->balance_kobo,
                'reference'     => $reference,
            ]);

            // ── 7. Transfer units: decrement seller ───────────────────────
            UserLand::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->decrement('units', $offer->units);

            // Clean up zero-unit rows
            UserLand::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->where('units', '<=', 0)
                ->delete();

            // ── 8. Transfer units: upsert buyer holding ──────
            UserLand::upsert(
                [[
                    'user_id'    => $buyer->id,
                    'land_id'    => $listing->land_id,
                    'units'      => $offer->units,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]],
                uniqueBy: ['user_id', 'land_id'],
                update:   ['units' => DB::raw("user_land.units + {$offer->units}"), 'updated_at' => now()],
            );

            // ── 9. Update seller Purchase record ──────────────────────────
            $sellerPurchase = Purchase::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->lockForUpdate()
                ->first();

            if (! $sellerPurchase || $sellerPurchase->units < $offer->units) {
                throw ValidationException::withMessages([
                    'units' => 'Purchase record inconsistency detected. Please contact support.',
                ]);
            }

            $sellerPurchase->decrement('units', $offer->units);

            // Mark seller purchase inactive if all units sold
            if ($sellerPurchase->fresh()->units <= 0) {
                $sellerPurchase->update(['status' => 'sold']);
            }

            // ── 10. Upsert buyer Purchase record (PostgreSQL) ─────────────
            Purchase::upsert(
                [[
                    'user_id'                    => $buyer->id,
                    'land_id'                    => $listing->land_id,
                    'units'                      => $offer->units,
                    'units_sold'                 => 0,
                    'total_amount_paid_kobo'     => $totalKobo,
                    'total_amount_received_kobo' => 0,
                    'status'                     => 'active',
                    'purchase_date'              => now(),
                    'reference'                  => $reference,
                    'created_at'                 => now(),
                    'updated_at'                 => now(),
                ]],
                uniqueBy: ['user_id', 'land_id'],
                update: [
                    'units'                  => DB::raw("purchases.units + {$offer->units}"),
                    'total_amount_paid_kobo' => DB::raw("purchases.total_amount_paid_kobo + {$totalKobo}"),
                    'reference'              => $reference,
                    'purchase_date'          => now(),
                    'updated_at'             => now(),
                ],
            );

            // ── 11. Log Transaction rows for both parties ─────────────────
            Transaction::insert([
                [
                    'user_id'          => $buyer->id,
                    'land_id'          => $listing->land_id,
                    'type'             => 'marketplace_purchase',
                    'units'            => $offer->units,
                    'amount_kobo'      => $totalKobo,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
                [
                    'user_id'          => $seller->id,
                    'land_id'          => $listing->land_id,
                    'type'             => 'marketplace_sale',
                    'units'            => $offer->units,
                    'amount_kobo'      => $sellerGets,
                    'status'           => 'completed',
                    'reference'        => $reference,
                    'transaction_date' => now(),
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
            ]);

            // ── 12. Reject other pending offers, update listing status ─────
            MarketplaceOffer::where('listing_id', $listing->id)
                ->where('id', '!=', $offer->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            $offer->update(['status' => 'accepted']);

            $remainingUnits = $listing->units_for_sale - $offer->units;
            $listing->update([
                'status'         => $remainingUnits > 0 ? 'active' : 'sold',
                'units_for_sale' => $remainingUnits,
            ]);

            // ── 13. Record the MarketplaceTransaction ─────────────────────
            MarketplaceTransaction::create([
                'listing_id'           => $listing->id,
                'offer_id'             => $offer->id,
                'buyer_id'             => $buyer->id,
                'seller_id'            => $seller->id,
                'land_id'              => $listing->land_id,
                'units'                => $offer->units,
                'price_per_unit_kobo'  => $offer->offer_price_kobo,
                'total_kobo'           => $totalKobo,
                'platform_fee_kobo'    => $feeKobo,
                'seller_receives_kobo' => $sellerGets,
                'reference'            => $reference,
                'completed_at'         => now(),
            ]);

            return [
                'reference'            => $reference,
                'units'                => $offer->units,
                'total_kobo'           => $totalKobo,
                'platform_fee_kobo'    => $feeKobo,
                'seller_receives_kobo' => $sellerGets,
                'buyer'                => $buyer->only('id', 'name'),
                'seller'               => $seller->only('id', 'name'),
            ];
        });
    }
}