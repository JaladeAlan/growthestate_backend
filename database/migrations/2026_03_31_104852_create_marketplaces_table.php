<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Marketplace listings ──────────────────────────────────────────────
        Schema::create('marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('land_id')->constrained('lands')->cascadeOnDelete();
            $table->unsignedInteger('units_for_sale');          // units being listed
            $table->unsignedBigInteger('asking_price_kobo');    // per unit, in kobo
            $table->text('description')->nullable();
            $table->enum('status', [
                'active',       // visible to buyers
                'in_escrow',    // offer accepted, awaiting payment
                'sold',         // completed
                'cancelled',    // cancelled by seller
                'expired',      // past expiry date
            ])->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'land_id']);
            $table->index('seller_id');
        });

        // ── Offers on listings ────────────────────────────────────────────────
        Schema::create('marketplace_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                  ->constrained('marketplace_listings')
                  ->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('units');                   // units buyer wants
            $table->unsignedBigInteger('offer_price_kobo');     // per unit offered
            $table->enum('status', [
                'pending',      // awaiting seller response
                'accepted',     // seller accepted — escrow created
                'rejected',     // seller rejected
                'withdrawn',    // buyer withdrew
                'expired',      // auto-expired
            ])->default('pending');
            $table->text('message')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['listing_id', 'status']);
            $table->index('buyer_id');
        });

        // ── Escrow / trade transactions ───────────────────────────────────────
        Schema::create('marketplace_escrows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                  ->constrained('marketplace_listings')
                  ->cascadeOnDelete();
            $table->foreignId('offer_id')
                  ->constrained('marketplace_offers')
                  ->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('seller_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('land_id')->constrained('lands')->cascadeOnDelete();
            $table->unsignedInteger('units');
            $table->unsignedBigInteger('price_per_unit_kobo');
            $table->unsignedBigInteger('total_kobo');           // units × price
            $table->unsignedBigInteger('platform_fee_kobo')->default(0); // e.g. 1%
            $table->unsignedBigInteger('seller_receives_kobo'); // total − fee
            $table->enum('status', [
                'awaiting_payment',  // buyer needs to pay
                'paid',              // buyer paid, units not yet transferred
                'completed',         // units transferred to buyer
                'disputed',          // dispute raised
                'refunded',          // refund issued to buyer
                'cancelled',         // cancelled before payment
            ])->default('awaiting_payment');
            $table->string('payment_reference')->nullable()->unique();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at')->nullable();        // payment deadline
            $table->timestamps();

            $table->index(['status', 'buyer_id']);
            $table->index('seller_id');
        });

        // ── P2P chat messages ─────────────────────────────────────────────────
        Schema::create('marketplace_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                  ->constrained('marketplace_listings')
                  ->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('receiver_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_read')->default(false);
            $table->timestamps();

            $table->index(['listing_id', 'sender_id', 'receiver_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_messages');
        Schema::dropIfExists('marketplace_escrows');
        Schema::dropIfExists('marketplace_offers');
        Schema::dropIfExists('marketplace_listings');
    }
};