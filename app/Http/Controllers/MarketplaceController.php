<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\LedgerEntry;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceTransaction;
use App\Models\Purchase;
use App\Models\Transaction;
use App\Models\UserLand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
    // Platform fee percentage (1%)
    const FEE_PCT = 1;

    // ─────────────────────────────────────────────────────────────────────────
    // LISTINGS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /marketplace
     */
    public function index(Request $request)
    {
        $request->validate([
            'land_id'   => 'nullable|integer|exists:lands,id',
            'min_price' => 'nullable|integer|min:0',
            'max_price' => 'nullable|integer|min:0',
            'sort'      => 'nullable|in:price_asc,price_desc,newest,units_desc',
            'per_page'  => 'nullable|integer|min:1|max:50',
        ]);

        $q = MarketplaceListing::active()
            ->with([
                'seller:id,name',
                'land:id,title,location,lat,lng',
                'land.images',
                'land.latestPrice',
            ]);

        if ($request->land_id)   $q->where('land_id', $request->land_id);
        if ($request->min_price) $q->where('asking_price_kobo', '>=', $request->min_price);
        if ($request->max_price) $q->where('asking_price_kobo', '<=', $request->max_price);

        match ($request->sort ?? 'newest') {
            'price_asc'  => $q->orderBy('asking_price_kobo'),
            'price_desc' => $q->orderByDesc('asking_price_kobo'),
            'units_desc' => $q->orderByDesc('units_for_sale'),
            default      => $q->orderByDesc('created_at'),
        };

        return response()->json([
            'success' => true,
            'data'    => $q->paginate($request->per_page ?? 20),
        ]);
    }

    /**
     * GET /marketplace/{listing}
     */
    public function show(MarketplaceListing $listing)
    {
        $listing->load([
            'seller:id,name',
            'land:id,title,location,size,total_units,available_units,lat,lng',
            'land.images',
            'land.latestPrice',
            'pendingOffers.buyer:id,name',
        ]);

        return response()->json(['success' => true, 'data' => $listing]);
    }

    /**
     * POST /marketplace
     * Seller creates a listing.
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'land_id'           => 'required|integer|exists:lands,id',
            'units_for_sale'    => 'required|integer|min:1',
            'asking_price_kobo' => 'required|integer|min:1',
            'description'       => 'nullable|string|max:1000',
            'expires_at'        => 'nullable|date|after:now',
        ]);

        $listing = DB::transaction(function () use ($user, $data) {
            $holding = UserLand::where('user_id', $user->id)
                ->where('land_id', $data['land_id'])
                ->lockForUpdate()
                ->first();

            $owned = $holding ? (int) $holding->units : 0;

            $alreadyListed = MarketplaceListing::where('seller_id', $user->id)
                ->where('land_id', $data['land_id'])
                ->where('status', 'active')
                ->sum('units_for_sale');

            $available = $owned - $alreadyListed;

            if ($data['units_for_sale'] > $available) {
                throw ValidationException::withMessages([
                    'units_for_sale' => "You only have {$available} unlisted units available for this property.",
                ]);
            }

            return MarketplaceListing::create([
                ...$data,
                'seller_id' => $user->id,
                'status'    => 'active',
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Listing created.',
            'data'    => $listing->load('land:id,title,location'),
        ], 201);
    }

    /**
     * PATCH /marketplace/{listing}
     */
    public function update(Request $request, MarketplaceListing $listing)
    {
        $this->authoriseSeller($listing, $request->user());

        if ($listing->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'Only active listings can be edited.']);
        }

        $data = $request->validate([
            'asking_price_kobo' => 'sometimes|integer|min:1',
            'description'       => 'sometimes|nullable|string|max:1000',
            'expires_at'        => 'sometimes|nullable|date|after:now',
        ]);

        $listing->update($data);

        return response()->json(['success' => true, 'data' => $listing->fresh()]);
    }

    /**
     * DELETE /marketplace/{listing}
     */
    public function destroy(Request $request, MarketplaceListing $listing)
    {
        $this->authoriseSeller($listing, $request->user());

        if ($listing->status !== 'active') {
            throw ValidationException::withMessages(['status' => 'Only active listings can be cancelled.']);
        }

        DB::transaction(function () use ($listing) {
            $listing->pendingOffers()->update(['status' => 'rejected']);
            $listing->update(['status' => 'cancelled']);
        });

        return response()->json(['success' => true, 'message' => 'Listing cancelled.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OFFERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /marketplace/{listing}/offers
     */
    public function makeOffer(Request $request, MarketplaceListing $listing)
    {
        $buyer = $request->user();

        if ($listing->seller_id === $buyer->id) {
            abort(422, 'You cannot make an offer on your own listing.');
        }

        if ($listing->status !== 'active' || $listing->is_expired) {
            abort(422, 'This listing is no longer active.');
        }

        if (MarketplaceOffer::where('listing_id', $listing->id)
            ->where('buyer_id', $buyer->id)
            ->where('status', 'pending')
            ->exists()) {
            abort(422, 'You already have a pending offer. Withdraw it first.');
        }

        $data = $request->validate([
            'units'            => 'required|integer|min:1|max:' . $listing->units_for_sale,
            'offer_price_kobo' => 'required|integer|min:1',
            'message'          => 'nullable|string|max:500',
            'expires_at'       => 'nullable|date|after:now',
        ]);

        $offer = MarketplaceOffer::create([
            ...$data,
            'listing_id' => $listing->id,
            'buyer_id'   => $buyer->id,
            'status'     => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Offer submitted.',
            'data'    => $offer->load('buyer:id,name'),
        ], 201);
    }

    /**
     * PATCH /marketplace/{listing}/offers/{offer}/accept
     */
    public function acceptOffer(Request $request, MarketplaceListing $listing, MarketplaceOffer $offer)
    {
        $seller = $request->user();

        $this->authoriseSeller($listing, $seller);
        $this->ensureOfferBelongs($offer, $listing);

        if ($offer->status !== 'pending') {
            abort(422, 'This offer is no longer pending.');
        }

        if ($listing->status !== 'active') {
            abort(422, 'Listing is not active.');
        }

        $request->validate([
            'transaction_pin' => 'required|digits:4',
        ]);

        if (! \Hash::check($request->transaction_pin, $seller->transaction_pin)) {
            throw ValidationException::withMessages(['transaction_pin' => 'Incorrect PIN.']);
        }

        return DB::transaction(function () use ($listing, $offer, $seller) {

            $buyerId  = $offer->buyer_id;
            $sellerId = $seller->id;

            [$firstId, $secondId] = $buyerId < $sellerId
                ? [$buyerId, $sellerId]
                : [$sellerId, $buyerId];

            $lockedUsers = \App\Models\User::whereIn('id', [$firstId, $secondId])
                ->lockForUpdate()
                ->orderBy('id')
                ->get()
                ->keyBy('id');

            $buyer  = $lockedUsers[$buyerId];
            $seller = $lockedUsers[$sellerId];

            $totalKobo  = $offer->offer_price_kobo * $offer->units;
            $feeKobo    = (int) round($totalKobo * self::FEE_PCT / 100);
            $sellerGets = $totalKobo - $feeKobo;
            $reference  = 'MKT-' . Str::uuid();

            // ── 1. Check buyer has enough balance ─────────────────────────
            if ($buyer->balance_kobo < $totalKobo) {
                throw ValidationException::withMessages([
                    'balance' => 'Buyer has insufficient wallet balance to complete this trade.',
                ]);
            }

            // ── 2. Check seller still owns enough units ───────────────────
            $sellerUnits = (int) UserLand::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->lockForUpdate()
                ->value('units');

            if ($sellerUnits < $offer->units) {
                throw ValidationException::withMessages([
                    'units' => 'You no longer have enough units to complete this trade.',
                ]);
            }

            // ── 3. Debit buyer wallet ─────────────────────────────────────
            $buyer->balance_kobo -= $totalKobo;
            $buyer->save();

            LedgerEntry::create([
                'uid'           => $buyer->id,
                'type'          => 'marketplace_purchase',
                'amount_kobo'   => $totalKobo,
                'balance_after' => $buyer->balance_kobo,
                'reference'     => $reference,
            ]);

            // ── 4. Credit seller wallet (minus fee) ───────────────────────
            $seller->balance_kobo += $sellerGets;
            $seller->save();

            LedgerEntry::create([
                'uid'           => $seller->id,
                'type'          => 'marketplace_sale',
                'amount_kobo'   => $sellerGets,
                'balance_after' => $seller->balance_kobo,
                'reference'     => $reference,
            ]);

            // ── 5. Transfer units: decrement seller ───────────────────────
            UserLand::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->decrement('units', $offer->units);

            // Remove row if seller now holds zero units
            UserLand::where('user_id', $seller->id)
                ->where('land_id', $listing->land_id)
                ->where('units', '<=', 0)
                ->delete();

            // ── 6. Transfer units: increment buyer ────────────────────────
            DB::statement("
                INSERT INTO user_land (user_id, land_id, units, created_at, updated_at)
                VALUES (?, ?, ?, NOW(), NOW())
                ON CONFLICT (user_id, land_id)
                DO UPDATE SET units = user_land.units + EXCLUDED.units, updated_at = NOW()
            ", [$buyer->id, $listing->land_id, $offer->units]);

            // ── 7. Update Purchase records ────────────────────────────────
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

            // Upsert buyer's purchase record
            DB::statement("
                INSERT INTO purchases
                    (user_id, land_id, units, units_sold, total_amount_paid_kobo,
                     total_amount_received_kobo, status, purchase_date, reference, created_at, updated_at)
                VALUES (?, ?, ?, 0, ?, 0, 'active', ?, ?, NOW(), NOW())
                ON CONFLICT (user_id, land_id)
                DO UPDATE SET
                    units                  = purchases.units + EXCLUDED.units,
                    total_amount_paid_kobo = purchases.total_amount_paid_kobo + EXCLUDED.total_amount_paid_kobo,
                    reference              = EXCLUDED.reference,
                    purchase_date          = EXCLUDED.purchase_date,
                    updated_at             = NOW()
            ", [
                $buyer->id,
                $listing->land_id,
                $offer->units,
                $totalKobo,
                now()->toDateTimeString(),
                $reference,
            ]);

            // ── 8. Log transaction for both parties ───────────────────────
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

            // ── 9. Reject remaining offers, close listing ─────────────────
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

            // ── 10. Log the marketplace transaction ───────────────────────
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

            return response()->json([
                'success' => true,
                'message' => 'Trade complete. Units transferred immediately.',
                'data'    => [
                    'reference'            => $reference,
                    'units'                => $offer->units,
                    'total_kobo'           => $totalKobo,
                    'platform_fee_kobo'    => $feeKobo,
                    'seller_receives_kobo' => $sellerGets,
                    'buyer'                => $buyer->only('id', 'name'),
                    'seller'               => $seller->only('id', 'name'),
                ],
            ]);
        });
    }

    /**
     * PATCH /marketplace/{listing}/offers/{offer}/reject
     */
    public function rejectOffer(Request $request, MarketplaceListing $listing, MarketplaceOffer $offer)
    {
        $this->authoriseSeller($listing, $request->user());
        $this->ensureOfferBelongs($offer, $listing);

        if ($offer->status !== 'pending') abort(422, 'Offer is not pending.');

        $offer->update(['status' => 'rejected']);

        return response()->json(['success' => true, 'message' => 'Offer rejected.']);
    }

    /**
     * PATCH /marketplace/{listing}/offers/{offer}/withdraw
     */
    public function withdrawOffer(Request $request, MarketplaceListing $listing, MarketplaceOffer $offer)
    {
        if ($offer->buyer_id !== $request->user()->id) abort(403);
        $this->ensureOfferBelongs($offer, $listing);

        if ($offer->status !== 'pending') abort(422, 'Offer cannot be withdrawn.');

        $offer->update(['status' => 'withdrawn']);

        return response()->json(['success' => true, 'message' => 'Offer withdrawn.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHAT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /marketplace/{listing}/messages
     */
    public function messages(Request $request, MarketplaceListing $listing)
    {
        $user = $request->user();
        $this->assertChatParticipant($listing, $user);

        $otherId = $user->id === $listing->seller_id
            ? (int) $request->input('with')
            : $listing->seller_id;

        $messages = MarketplaceMessage::where('listing_id', $listing->id)
            ->where(fn ($q) =>
                $q->where(fn ($q) =>
                    $q->where('sender_id', $user->id)->where('receiver_id', $otherId)
                )->orWhere(fn ($q) =>
                    $q->where('sender_id', $otherId)->where('receiver_id', $user->id)
                )
            )
            ->orderBy('created_at')
            ->get();

        MarketplaceMessage::where('listing_id', $listing->id)
            ->where('receiver_id', $user->id)
            ->where('sender_id', $otherId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    /**
     * POST /marketplace/{listing}/messages
     */
    public function sendMessage(Request $request, MarketplaceListing $listing)
    {
        $sender = $request->user();
        $this->assertChatParticipant($listing, $sender);

        $data = $request->validate(['body' => 'required|string|max:2000']);

        $receiverId = $sender->id === $listing->seller_id
            ? (int) $request->input('receiver_id')
            : $listing->seller_id;

        if (! $receiverId) abort(422, 'receiver_id is required when seller sends a message.');

        $message = MarketplaceMessage::create([
            'listing_id'  => $listing->id,
            'sender_id'   => $sender->id,
            'receiver_id' => $receiverId,
            'body'        => $data['body'],
        ]);

        return response()->json([
            'success' => true,
            'data'    => $message->load('sender:id,name'),
        ], 201);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // MY ACTIVITY
    // ─────────────────────────────────────────────────────────────────────────

    public function myListings(Request $request)
    {
        $listings = MarketplaceListing::where('seller_id', $request->user()->id)
            ->with(['land:id,title,location', 'land.images', 'pendingOffers'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $listings]);
    }

    public function myOffers(Request $request)
    {
        $offers = MarketplaceOffer::where('buyer_id', $request->user()->id)
            ->with(['listing.land:id,title,location', 'listing.seller:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $offers]);
    }

    public function myTransactions(Request $request)
    {
        $userId = $request->user()->id;

        $txs = MarketplaceTransaction::where('buyer_id', $userId)
            ->orWhere('seller_id', $userId)
            ->with(['land:id,title,location', 'buyer:id,name', 'seller:id,name'])
            ->orderByDesc('completed_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $txs]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────────

    private function authoriseSeller(MarketplaceListing $listing, $user): void
    {
        if ($listing->seller_id !== $user->id) abort(403, 'Not your listing.');
    }

    private function ensureOfferBelongs(MarketplaceOffer $offer, MarketplaceListing $listing): void
    {
        if ($offer->listing_id !== $listing->id) abort(404);
    }

    private function assertChatParticipant(MarketplaceListing $listing, $user): void
    {
        $isSeller = $listing->seller_id === $user->id;
        $isBuyer  = MarketplaceOffer::where('listing_id', $listing->id)
            ->where('buyer_id', $user->id)
            ->exists();

        if (! $isSeller && ! $isBuyer) {
            abort(403, 'You must have an offer on this listing to chat.');
        }
    }
}