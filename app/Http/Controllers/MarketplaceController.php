<?php

namespace App\Http\Controllers;

use App\Models\Land;
use App\Models\MarketplaceEscrow;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceMessage;
use App\Models\MarketplaceOffer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MarketplaceController extends Controller
{
    // Platform fee percentage (1 %)
    const FEE_PCT = 1;

    // ─────────────────────────────────────────────────────────────────────────
    // LISTINGS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /marketplace
     * Public browseable list of active listings.
     */
    public function index(Request $request)
    {
        $request->validate([
            'land_id'  => 'nullable|integer|exists:lands,id',
            'min_price'=> 'nullable|integer|min:0',
            'max_price'=> 'nullable|integer|min:0',
            'sort'     => 'nullable|in:price_asc,price_desc,newest,units_desc',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $q = MarketplaceListing::active()
            ->with(['seller:id,name,email', 'land:id,title,location,lat,lng', 'land.images', 'land.latestPrice']);

        if ($request->land_id)  $q->where('land_id', $request->land_id);
        if ($request->min_price) $q->where('asking_price_kobo', '>=', $request->min_price);
        if ($request->max_price) $q->where('asking_price_kobo', '<=', $request->max_price);

        match ($request->sort ?? 'newest') {
            'price_asc'   => $q->orderBy('asking_price_kobo'),
            'price_desc'  => $q->orderByDesc('asking_price_kobo'),
            'units_desc'  => $q->orderByDesc('units_for_sale'),
            default       => $q->orderByDesc('created_at'),
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
            'seller:id,name,email',
            'land:id,title,location,size,total_units,available_units,lat,lng',
            'land.images',
            'land.latestPrice',
            'pendingOffers.buyer:id,name',
        ]);

        return response()->json(['success' => true, 'data' => $listing]);
    }

    /**
     * POST /marketplace
     * Seller creates a new listing.
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

        // Verify seller actually owns enough units of this land
        $owned = (int) $user->lands()
            ->wherePivot('land_id', $data['land_id'])
            ->sum('user_land.units');

        // Deduct units already listed in active/in_escrow listings
        $alreadyListed = MarketplaceListing::where('seller_id', $user->id)
            ->where('land_id', $data['land_id'])
            ->whereIn('status', ['active', 'in_escrow'])
            ->sum('units_for_sale');

        $available = $owned - $alreadyListed;

        if ($data['units_for_sale'] > $available) {
            throw ValidationException::withMessages([
                'units_for_sale' => "You only have {$available} available units to list for this property.",
            ]);
        }

        $listing = MarketplaceListing::create([
            ...$data,
            'seller_id' => $user->id,
            'status'    => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Listing created successfully.',
            'data'    => $listing->load('land:id,title,location'),
        ], 201);
    }

    /**
     * PATCH /marketplace/{listing}
     * Seller edits an active listing.
     */
    public function update(Request $request, MarketplaceListing $listing)
    {
        $this->authoriseSeller($listing, $request->user());

        if (! in_array($listing->status, ['active'])) {
            throw ValidationException::withMessages([
                'status' => 'Only active listings can be edited.',
            ]);
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
     * Seller cancels an active listing.
     */
    public function destroy(Request $request, MarketplaceListing $listing)
    {
        $this->authoriseSeller($listing, $request->user());

        if (! in_array($listing->status, ['active'])) {
            throw ValidationException::withMessages([
                'status' => 'Only active listings can be cancelled.',
            ]);
        }

        $listing->update(['status' => 'cancelled']);

        // Reject all pending offers
        $listing->pendingOffers()->update(['status' => 'rejected']);

        return response()->json(['success' => true, 'message' => 'Listing cancelled.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // OFFERS
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /marketplace/{listing}/offers
     * Buyer makes an offer.
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

        // One pending offer per buyer per listing
        $existing = MarketplaceOffer::where('listing_id', $listing->id)
            ->where('buyer_id', $buyer->id)
            ->where('status', 'pending')
            ->exists();

        if ($existing) {
            abort(422, 'You already have a pending offer on this listing. Withdraw it first.');
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
     * Seller accepts an offer → creates escrow.
     */
    public function acceptOffer(Request $request, MarketplaceListing $listing, MarketplaceOffer $offer)
    {
        $this->authoriseSeller($listing, $request->user());
        $this->ensureOfferBelongs($offer, $listing);

        if ($offer->status !== 'pending') {
            abort(422, 'This offer is no longer pending.');
        }

        if ($listing->status !== 'active') {
            abort(422, 'Listing is not active.');
        }

        return DB::transaction(function () use ($listing, $offer) {
            // Reject all other pending offers
            MarketplaceOffer::where('listing_id', $listing->id)
                ->where('id', '!=', $offer->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            $offer->update(['status' => 'accepted']);
            $listing->update(['status' => 'in_escrow']);

            $totalKobo      = $offer->offer_price_kobo * $offer->units;
            $feeKobo        = (int) round($totalKobo * self::FEE_PCT / 100);
            $sellerReceives = $totalKobo - $feeKobo;

            $escrow = MarketplaceEscrow::create([
                'listing_id'           => $listing->id,
                'offer_id'             => $offer->id,
                'buyer_id'             => $offer->buyer_id,
                'seller_id'            => $listing->seller_id,
                'land_id'              => $listing->land_id,
                'units'                => $offer->units,
                'price_per_unit_kobo'  => $offer->offer_price_kobo,
                'total_kobo'           => $totalKobo,
                'platform_fee_kobo'    => $feeKobo,
                'seller_receives_kobo' => $sellerReceives,
                'status'               => 'awaiting_payment',
                'expires_at'           => now()->addHours(24),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Offer accepted. Escrow created — buyer has 24 hours to pay.',
                'data'    => $escrow->load('buyer:id,name,email'),
            ]);
        });
    }

    /**
     * PATCH /marketplace/{listing}/offers/{offer}/reject
     * Seller rejects an offer.
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
     * Buyer withdraws their own offer.
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
    // ESCROW & PAYMENT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /marketplace/escrow/{escrow}
     */
    public function showEscrow(Request $request, MarketplaceEscrow $escrow)
    {
        $user = $request->user();
        if ($escrow->buyer_id !== $user->id && $escrow->seller_id !== $user->id) abort(403);

        return response()->json([
            'success' => true,
            'data'    => $escrow->load(['buyer:id,name', 'seller:id,name', 'land:id,title,location']),
        ]);
    }

    /**
     * POST /marketplace/escrow/{escrow}/pay
     * Buyer pays from wallet balance. Deducts buyer wallet, marks escrow paid.
     * Actual unit transfer happens in complete().
     */
    public function payEscrow(Request $request, MarketplaceEscrow $escrow)
    {
        $buyer = $request->user();

        if ($escrow->buyer_id !== $buyer->id) abort(403);
        if ($escrow->status !== 'awaiting_payment') abort(422, 'Escrow is not awaiting payment.');
        if ($escrow->expires_at && $escrow->expires_at->isPast()) {
            $this->expireEscrow($escrow);
            abort(422, 'Escrow has expired.');
        }

        $request->validate([
            'transaction_pin' => 'required|digits:4',
        ]);

        // Verify PIN
        if (! \Hash::check($request->transaction_pin, $buyer->transaction_pin)) {
            throw ValidationException::withMessages(['transaction_pin' => 'Incorrect PIN.']);
        }

        return DB::transaction(function () use ($buyer, $escrow) {
            // Check wallet balance
            if ($buyer->wallet_balance_kobo < $escrow->total_kobo) {
                throw ValidationException::withMessages([
                    'balance' => 'Insufficient wallet balance.',
                ]);
            }

            // Deduct from buyer wallet
            $buyer->decrement('wallet_balance_kobo', $escrow->total_kobo);

            $escrow->update([
                'status' => 'paid',
                'paid_at' => now(),
                'payment_reference' => 'MP-' . strtoupper(uniqid()),
            ]);

            // Auto-complete: transfer units immediately after payment
            return $this->completeEscrowTransfer($escrow);
        });
    }

    /**
     * POST /marketplace/escrow/{escrow}/complete  (admin override)
     * Admin manually completes a paid escrow (e.g. after dispute resolution).
     */
    public function completeEscrow(Request $request, MarketplaceEscrow $escrow)
    {
        if (! $request->user()->is_admin) abort(403);
        if (! in_array($escrow->status, ['paid', 'disputed'])) {
            abort(422, 'Escrow must be paid or disputed to complete.');
        }

        return $this->completeEscrowTransfer($escrow);
    }

    /**
     * POST /marketplace/escrow/{escrow}/dispute
     * Buyer raises a dispute on a paid escrow.
     */
    public function disputeEscrow(Request $request, MarketplaceEscrow $escrow)
    {
        if ($escrow->buyer_id !== $request->user()->id) abort(403);
        if ($escrow->status !== 'paid') abort(422, 'Only paid escrows can be disputed.');

        $request->validate(['reason' => 'required|string|max:1000']);

        $escrow->update(['status' => 'disputed']);

        // TODO: notify admin + create support ticket

        return response()->json(['success' => true, 'message' => 'Dispute raised. Our team will review within 24 hours.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // CHAT
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /marketplace/{listing}/messages
     * Returns the conversation between the auth user and the counterparty.
     */
    public function messages(Request $request, MarketplaceListing $listing)
    {
        $user = $request->user();
        $this->assertChatParticipant($listing, $user);

        // Determine the other party (seller sees buyer's messages, buyer sees seller's)
        $otherId = $user->id === $listing->seller_id
            ? $request->input('with') // seller must specify which buyer
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

        // Mark received messages as read
        MarketplaceMessage::where('listing_id', $listing->id)
            ->where('receiver_id', $user->id)
            ->where('sender_id', $otherId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['success' => true, 'data' => $messages]);
    }

    /**
     * POST /marketplace/{listing}/messages
     * Send a chat message.
     */
    public function sendMessage(Request $request, MarketplaceListing $listing)
    {
        $sender = $request->user();
        $this->assertChatParticipant($listing, $sender);

        $data = $request->validate(['body' => 'required|string|max:2000']);

        $receiverId = $sender->id === $listing->seller_id
            ? $request->input('receiver_id') // seller messages a specific buyer
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

    /**
     * GET /marketplace/my-listings
     * Authenticated user's own listings.
     */
    public function myListings(Request $request)
    {
        $listings = MarketplaceListing::where('seller_id', $request->user()->id)
            ->with(['land:id,title,location', 'land.images', 'pendingOffers'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $listings]);
    }

    /**
     * GET /marketplace/my-offers
     * Authenticated user's offers (as buyer).
     */
    public function myOffers(Request $request)
    {
        $offers = MarketplaceOffer::where('buyer_id', $request->user()->id)
            ->with(['listing.land:id,title,location', 'listing.seller:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $offers]);
    }

    /**
     * GET /marketplace/my-escrows
     * Escrows where auth user is buyer or seller.
     */
    public function myEscrows(Request $request)
    {
        $userId = $request->user()->id;

        $escrows = MarketplaceEscrow::where('buyer_id', $userId)
            ->orWhere('seller_id', $userId)
            ->with(['land:id,title,location', 'buyer:id,name', 'seller:id,name'])
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $escrows]);
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

    private function completeEscrowTransfer(MarketplaceEscrow $escrow)
    {
        return DB::transaction(function () use ($escrow) {
            $seller = $escrow->seller;
            $buyer  = $escrow->buyer;

            // Transfer units: decrement seller, increment buyer
            $seller->lands()->updateExistingPivot($escrow->land_id, [
                'units' => DB::raw("units - {$escrow->units}"),
            ]);

            // Attach or increment buyer's units
            $existing = $buyer->lands()->where('land_id', $escrow->land_id)->first();
            if ($existing) {
                $buyer->lands()->updateExistingPivot($escrow->land_id, [
                    'units' => DB::raw("units + {$escrow->units}"),
                ]);
            } else {
                $buyer->lands()->attach($escrow->land_id, ['units' => $escrow->units]);
            }

            // Credit seller wallet (minus platform fee)
            $seller->increment('wallet_balance_kobo', $escrow->seller_receives_kobo);

            // Update land available_units (no change — units just changed hands)
            // Mark escrow and listing complete
            $escrow->update([
                'status'       => 'completed',
                'completed_at' => now(),
            ]);

            $escrow->listing->update(['status' => 'sold']);

            return response()->json([
                'success' => true,
                'message' => 'Trade complete. Units transferred to buyer.',
                'data'    => $escrow->fresh(),
            ]);
        });
    }

    private function expireEscrow(MarketplaceEscrow $escrow): void
    {
        $escrow->update(['status' => 'cancelled']);
        $escrow->offer->update(['status' => 'expired']);
        $escrow->listing->update(['status' => 'active']); // re-open listing
    }
}