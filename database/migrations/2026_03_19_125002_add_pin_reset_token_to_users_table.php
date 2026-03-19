<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'pin_reset_token')) {
                $table->string('pin_reset_token')->nullable()->after('pin_reset_expires_at');
            }
            if (!Schema::hasColumn('users', 'pin_reset_token_expires_at')) {
                $table->timestamp('pin_reset_token_expires_at')->nullable()->after('pin_reset_token');
            }
        });
    }
    
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'pin_reset_token_expires_at')) {
                $table->dropColumn('pin_reset_token_expires_at');
            }
            if (Schema::hasColumn('users', 'pin_reset_token')) {
                $table->dropColumn('pin_reset_token');
            }
        });
    }
};
