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
            $table->unsignedInteger('units_for_sale');
            $table->unsignedBigInteger('asking_price_kobo');    // per unit
            $table->text('description')->nullable();
            $table->enum('status', [
                'active',       // visible, accepting offers
                'sold',         // all units sold
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
            $table->unsignedInteger('units');
            $table->unsignedBigInteger('offer_price_kobo');     // per unit
            $table->enum('status', [
                'pending',      // awaiting seller response
                'accepted',     // seller accepted — trade completed immediately
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

        // ── Completed trade record (replaces escrow) ──────────────────────────
        // One row per completed trade — immutable audit trail.
        // Written atomically with the wallet debit/credit and unit transfer.
        Schema::create('marketplace_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                  ->constrained('marketplace_listings')
                  ->cascadeOnDelete();
            $table->foreignId('offer_id')
                  ->constrained('marketplace_offers')
                  ->cascadeOnDelete();
            $table->foreignId('buyer_id') ->constrained('users');
            $table->foreignId('seller_id')->constrained('users');
            $table->foreignId('land_id')  ->constrained('lands');

            $table->unsignedInteger('units');
            $table->unsignedBigInteger('price_per_unit_kobo');
            $table->unsignedBigInteger('total_kobo');
            $table->unsignedBigInteger('platform_fee_kobo')->default(0);
            $table->unsignedBigInteger('seller_receives_kobo');

            $table->string('reference')->unique();
            $table->timestamp('completed_at');
            $table->timestamps();

            $table->index(['buyer_id',  'completed_at']);
            $table->index(['seller_id', 'completed_at']);
            $table->index('land_id');
        });

        // ── P2P chat messages ─────────────────────────────────────────────────
        Schema::create('marketplace_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')
                  ->constrained('marketplace_listings')
                  ->cascadeOnDelete();
            $table->foreignId('sender_id')  ->constrained('users')->cascadeOnDelete();
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
        Schema::dropIfExists('marketplace_transactions');
        Schema::dropIfExists('marketplace_offers');
        Schema::dropIfExists('marketplace_listings');
    }
};