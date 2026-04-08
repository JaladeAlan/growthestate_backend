<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            // Admin who reviewed (approved or rejected) this withdrawal
            $table->unsignedBigInteger('reviewed_by')
                  ->nullable()
                  ->after('status');

            // When the review action was taken
            $table->timestamp('reviewed_at')
                  ->nullable()
                  ->after('reviewed_by');

            // Populated only on rejection — shown to the user
            $table->string('rejection_reason', 500)
                  ->nullable()
                  ->after('reviewed_at');

            // Index so the admin queue query (WHERE status = 'pending') is fast
            $table->index('status', 'withdrawals_status_idx');

            $table->foreign('reviewed_by')
                  ->references('id')
                  ->on('users')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            $table->dropForeign(['reviewed_by']);
            $table->dropIndex('withdrawals_status_idx');
            $table->dropColumn(['reviewed_by', 'reviewed_at', 'rejection_reason']);
        });
    }
};
