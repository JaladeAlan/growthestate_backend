<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_transactions', function (Blueprint $table) {
            // ── Primary key ───────────────────────────────────────────────
            $table->id();

            // ── Foreign keys ──────────────────────────────────────────────
            $table->foreignId('listing_id')
                  ->nullable()
                  ->constrained('marketplace_listings')
                  ->nullOnDelete();

            $table->foreignId('offer_id')
                  ->nullable()
                  ->constrained('marketplace_offers')
                  ->nullOnDelete();

            $table->foreignId('land_id')
                  ->nullable()
                  ->constrained('lands')
                  ->nullOnDelete();

            $table->foreignId('buyer_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            $table->foreignId('seller_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // ── Trade details ─────────────────────────────────────────────
            $table->unsignedInteger('units');

            // All monetary values stored in kobo (integer) — no floats.
            $table->unsignedBigInteger('price_per_unit_kobo');
            $table->unsignedBigInteger('total_kobo');
            $table->unsignedBigInteger('platform_fee_kobo');
            $table->unsignedBigInteger('seller_receives_kobo');

            // ── Reference ─────────────────────────────────────────────────
            $table->string('reference')->unique();

            // ── Timestamps ────────────────────────────────────────────────
            $table->timestamp('completed_at');

            $table->timestamps();

            // ── Indexes ───────────────────────────────────────────────────
            $table->index('buyer_id');
            $table->index('seller_id');

            // land_id is filtered in marketplace analytics queries.
            $table->index('land_id');

            $table->index('completed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_transactions');
    }
};