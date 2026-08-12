<?php

use App\Models\MarketplaceListing;
use App\Models\MarketplaceOffer;
use App\Models\MarketplaceTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

function marketplaceUser(array $attrs = []): User
{
    return User::factory()->create(array_merge([
        'email_verified_at'  => now(),
        'balance_kobo'       => 20_000_000,
        'rewards_balance_kobo' => 0,
        'transaction_pin'    => Hash::make('1234'),
        'is_suspended'       => false,
        'screening_status'   => 'clear',
    ], $attrs));
}

function marketplaceLandId(array $attrs = []): int
{
    return DB::table('lands')->insertGetId(array_merge([
        'title'           => 'Marketplace Plot',
        'location'        => 'Abuja',
        'size'            => 1000.0,
        'total_units'     => 500,
        'available_units' => 500,
        'is_available'    => true,
        'description'     => 'A marketplace test land.',
        'created_at'      => now(),
        'updated_at'      => now(),
    ], $attrs));
}

function giveUnits(int $userId, int $landId, int $units): void
{
    DB::table('user_land')->insertOrIgnore([
        'user_id'    => $userId,
        'land_id'    => $landId,
        'units'      => $units,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('purchases')->insertOrIgnore([
        'user_id'                    => $userId,
        'land_id'                    => $landId,
        'units'                      => $units,
        'units_sold'                 => 0,
        'total_amount_paid_kobo'     => 0,
        'total_amount_received_kobo' => 0,
        'status'                     => 'active',
        'purchase_date'              => now(),
        'reference'                  => 'MKT-SEED-' . $userId . '-' . $landId,
        'created_at'                 => now(),
        'updated_at'                 => now(),
    ]);
}

// ─────────────────────────────────────────────────────────────────────────────
// Listing creation
// ─────────────────────────────────────────────────────────────────────────────

describe('Marketplace listing creation', function () {

    it('creates an active listing when the seller has sufficient units', function () {
        $seller = marketplaceUser();
        $landId = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        DB::table('land_price_history')->insert([
            'land_id'            => $landId,
            'price_per_unit_kobo' => 500_000,
            'price_date'         => now()->toDateString(),
            'created_at'         => now(),
        ]);

        $response = $this->actingAs($seller, 'api')
            ->postJson('/api/marketplace', [
                'land_id'           => $landId,
                'units_for_sale'    => 5,
                'asking_price_kobo' => 600_000, // ₦6,000 per unit
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.units_for_sale', 5);

        $this->assertDatabaseHas('marketplace_listings', [
            'seller_id'      => $seller->id,
            'land_id'        => $landId,
            'units_for_sale' => 5,
            'status'         => 'active',
        ]);
    });

    it('rejects a listing when the seller does not own the units', function () {
        $seller = marketplaceUser();
        $landId = marketplaceLandId();
        // No user_land row — seller owns 0 units

        $this->actingAs($seller, 'api')
            ->postJson('/api/marketplace', [
                'land_id'           => $landId,
                'units_for_sale'    => 5,
                'asking_price_kobo' => 600_000,
            ])
            ->assertStatus(422);
    });

    it('seller can delete their own active listing', function () {
        $seller  = marketplaceUser();
        $landId  = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 5,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $this->actingAs($seller, 'api')
            ->deleteJson("/api/marketplace/{$listing->id}")
            ->assertStatus(200);

        $this->assertDatabaseHas('marketplace_listings', [
            'id'     => $listing->id,
            'status' => 'cancelled',
        ]);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Making an offer
// ─────────────────────────────────────────────────────────────────────────────

describe('Marketplace offer submission', function () {

    it('buyer can make an offer on an active listing', function () {
        $seller  = marketplaceUser();
        $buyer   = marketplaceUser(['balance_kobo' => 10_000_000]);
        $landId  = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $response = $this->actingAs($buyer, 'api')
            ->postJson("/api/marketplace/{$listing->id}/offers", [
                'units'            => 3,
                'offer_price_kobo' => 480_000,
                'message'          => 'Offering a slightly lower price.',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'pending');

        $this->assertDatabaseHas('marketplace_offers', [
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 3,
            'offer_price_kobo' => 480_000,
            'status'          => 'pending',
        ]);
    });

    it('seller cannot make an offer on their own listing', function () {
        $seller  = marketplaceUser();
        $landId  = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $this->actingAs($seller, 'api')
            ->postJson("/api/marketplace/{$listing->id}/offers", [
                'units'            => 2,
                'offer_price_kobo' => 500_000,
            ])
            ->assertStatus(422);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Accepting an offer (trade execution via MarketplaceTradeService)
// ─────────────────────────────────────────────────────────────────────────────

describe('Accept offer trade execution', function () {

    it('executes the trade: transfers units, debits buyer, credits seller, writes records', function () {
        Notification::fake();

        $seller = marketplaceUser(['balance_kobo' => 0]);
        $buyer  = marketplaceUser(['balance_kobo' => 10_000_000]);
        $landId = marketplaceLandId();

        // Seller owns 10 units
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $offer = MarketplaceOffer::create([
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 5,
            'offer_price_kobo' => 500_000,
            'status'          => 'pending',
        ]);

        $response = $this->actingAs($seller, 'api')
            ->patchJson("/api/marketplace/{$listing->id}/offers/{$offer->id}/accept", [
                'transaction_pin' => '1234',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        // Offer accepted
        $this->assertDatabaseHas('marketplace_offers', [
            'id'     => $offer->id,
            'status' => 'accepted',
        ]);

        // Trade record created
        $trade = MarketplaceTransaction::where('offer_id', $offer->id)->first();
        expect($trade)->not->toBeNull();
        expect($trade->units)->toBe(5);

        // 1% platform fee: 5 × 500,000 = 2,500,000; fee = 25,000; seller gets 2,475,000
        expect($trade->platform_fee_kobo)->toBe(25_000);
        expect($trade->seller_receives_kobo)->toBe(2_475_000);

        // Buyer debited
        $this->assertDatabaseHas('users', [
            'id'           => $buyer->id,
            'balance_kobo' => 10_000_000 - 2_500_000,
        ]);

        // Seller credited
        $this->assertDatabaseHas('users', [
            'id'           => $seller->id,
            'balance_kobo' => 2_475_000,
        ]);

        // Units transferred: buyer gains 5, seller loses 5
        $this->assertDatabaseHas('user_land', [
            'user_id' => $buyer->id,
            'land_id' => $landId,
            'units'   => 5,
        ]);

        $this->assertDatabaseHas('user_land', [
            'user_id' => $seller->id,
            'land_id' => $landId,
            'units'   => 5,
        ]);

        // Listing units_for_sale reduced
        $this->assertDatabaseHas('marketplace_listings', [
            'id'             => $listing->id,
            'units_for_sale' => 5,
        ]);
    });

    it('rejects acceptance when buyer has insufficient balance', function () {
        $seller = marketplaceUser(['balance_kobo' => 0]);
        $buyer  = marketplaceUser(['balance_kobo' => 100]); // almost nothing
        $landId = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $offer = MarketplaceOffer::create([
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 5,
            'offer_price_kobo' => 500_000,
            'status'          => 'pending',
        ]);

        $this->actingAs($seller, 'api')
            ->patchJson("/api/marketplace/{$listing->id}/offers/{$offer->id}/accept")
            ->assertStatus(422);

        // Offer still pending, no money moved
        $this->assertDatabaseHas('marketplace_offers', ['id' => $offer->id, 'status' => 'pending']);
        $this->assertDatabaseHas('users', ['id' => $buyer->id, 'balance_kobo' => 100]);
    });

    it('prevents a non-seller from accepting an offer', function () {
        $seller   = marketplaceUser(['balance_kobo' => 0]);
        $buyer    = marketplaceUser(['balance_kobo' => 10_000_000]);
        $impostor = marketplaceUser(['balance_kobo' => 0]);
        $landId   = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $offer = MarketplaceOffer::create([
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 3,
            'offer_price_kobo' => 500_000,
            'status'          => 'pending',
        ]);

        $this->actingAs($impostor, 'api')
            ->patchJson("/api/marketplace/{$listing->id}/offers/{$offer->id}/accept")
            ->assertStatus(403);
    });

    it('cannot accept an already-accepted offer (idempotency guard)', function () {
        $seller = marketplaceUser();
        $buyer  = marketplaceUser(['balance_kobo' => 10_000_000]);
        $landId = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $offer = MarketplaceOffer::create([
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 5,
            'offer_price_kobo' => 500_000,
            'status'          => 'accepted', // already accepted
        ]);

        $this->actingAs($seller, 'api')
            ->patchJson("/api/marketplace/{$listing->id}/offers/{$offer->id}/accept")
            ->assertStatus(422);
    });
});

// ─────────────────────────────────────────────────────────────────────────────
// Offer withdrawal / rejection
// ─────────────────────────────────────────────────────────────────────────────

describe('Offer withdrawal and rejection', function () {

    it('buyer can withdraw their own pending offer', function () {
        $seller  = marketplaceUser();
        $buyer   = marketplaceUser();
        $landId  = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $offer = MarketplaceOffer::create([
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 3,
            'offer_price_kobo' => 480_000,
            'status'          => 'pending',
        ]);

        $this->actingAs($buyer, 'api')
            ->patchJson("/api/marketplace/{$listing->id}/offers/{$offer->id}/withdraw")
            ->assertStatus(200);

        $this->assertDatabaseHas('marketplace_offers', [
            'id'     => $offer->id,
            'status' => 'withdrawn',
        ]);
    });

    it('seller can reject a pending offer', function () {
        $seller  = marketplaceUser();
        $buyer   = marketplaceUser();
        $landId  = marketplaceLandId();
        giveUnits($seller->id, $landId, 10);

        $listing = MarketplaceListing::create([
            'seller_id'         => $seller->id,
            'land_id'           => $landId,
            'units_for_sale'    => 10,
            'asking_price_kobo' => 500_000,
            'status'            => 'active',
        ]);

        $offer = MarketplaceOffer::create([
            'listing_id'      => $listing->id,
            'buyer_id'        => $buyer->id,
            'units'           => 3,
            'offer_price_kobo' => 480_000,
            'status'          => 'pending',
        ]);

        $this->actingAs($seller, 'api')
            ->patchJson("/api/marketplace/{$listing->id}/offers/{$offer->id}/reject")
            ->assertStatus(200);

        $this->assertDatabaseHas('marketplace_offers', [
            'id'     => $offer->id,
            'status' => 'rejected',
        ]);
    });
});
