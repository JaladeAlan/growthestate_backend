<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('referred_user_id')->constrained('users')->onDelete('cascade');
            $table->enum('status', ['pending', 'completed'])->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('referrer_id');
            $table->index('referred_user_id');
            $table->index('status');
            
            // Prevent duplicate referrals
            $table->unique('referred_user_id');
        });

        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->enum('reward_type', ['bonus_units', 'cashback', 'discount']);
            $table->integer('amount_kobo')->nullable(); // For cashback
            $table->integer('units')->nullable(); // For bonus units
            $table->integer('discount_percentage')->nullable(); // For discounts
            $table->boolean('claimed')->default(false);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('referral_id');
            $table->index('claimed');
        });

        // Add referral code to users table
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 10)->unique()->after('email')->nullable();
            $table->foreignId('referred_by')->nullable()->after('referral_code')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['referred_by']);
            $table->dropColumn(['referral_code', 'referred_by']);
        });
        
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referrals');
    }
};