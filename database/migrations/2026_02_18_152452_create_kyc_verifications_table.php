<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kyc_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            
            // Personal Information
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('phone_number');
            $table->text('address');
            $table->string('city');
            $table->string('state');
            $table->string('country')->default('Nigeria');
            
            // ID Document
            $table->enum('id_type', ['nin', 'drivers_license', 'voters_card', 'passport', 'bvn']);
            $table->string('id_number');
            $table->string('id_front_path');
            $table->string('id_back_path')->nullable(); 
            
            // Selfie for verification
            $table->string('selfie_path');
            
            // Verification Status
            $table->enum('status', ['pending', 'approved', 'rejected', 'resubmit'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->foreignId('verified_by')->nullable()->constrained('users');
            
            $table->timestamps();
            
            // Indexes
            $table->index('user_id');
            $table->index('status');
            $table->index('id_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kyc_verifications');
    }
};