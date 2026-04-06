<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('waitlist', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('email')->unique();

            $table->enum('budget', ['5k_50k', '50k_500k', '500k_plus'])->nullable();
            $table->enum('city',   ['ogun', 'oyo', 'abuja', 'other'])->nullable();

            // Position in the queue — set on insert, bumped by referrals
            $table->unsignedInteger('position')->nullable();

            // Their own shareable referral code
            $table->string('referral_code', 12)->unique()->nullable();

            // Who referred them (code, not FK — referrer may not be a user yet)
            $table->string('referred_by_code', 12)->nullable()->index();

            // How many people they've referred
            $table->unsignedInteger('referral_count')->default(0);

            // Whether we've sent them their early-access invite
            $table->boolean('invited')->default(false);
            $table->timestamp('invited_at')->nullable();

            $table->timestamps();

            $table->index(['position']);
            $table->index(['referral_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist');
    }
};