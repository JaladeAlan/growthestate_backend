<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {

            /**
             *  DO NOT use ->change() (breaks SQLite)
             *  Add column only if missing
             */
            if (!Schema::hasColumn('users', 'balance_kobo')) {
                $table->unsignedBigInteger('balance_kobo')->default(0);
            }

            /**
             *  Drop old balance ONLY if it exists
             */
            if (Schema::hasColumn('users', 'balance')) {
                $table->dropColumn('balance');
            }

            /**
             * Account suspension flag
             */
            if (!Schema::hasColumn('users', 'is_suspended')) {
                $table->boolean('is_suspended')
                    ->default(false)
                    ->after('is_admin');
            }

            /**
             * Last transaction timestamp
             */
            if (!Schema::hasColumn('users', 'last_transaction_at')) {
                $table->timestamp('last_transaction_at')
                    ->nullable()
                    ->after('updated_at');
            }

            /**
             * Bank verification status
             */
            if (!Schema::hasColumn('users', 'bank_verified')) {
                $table->boolean('bank_verified')
                    ->default(false)
                    ->after('account_name');
            }

            /**
             * Index (guarded — avoids duplicate index crash)
             */
            if (!Schema::hasIndex('users', ['account_number', 'bank_code'])) {
                $table->index(['account_number', 'bank_code']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {

            /**
             * Restore legacy balance only if missing
             */
            if (!Schema::hasColumn('users', 'balance')) {
                $table->decimal('balance', 15, 2)->default(0);
            }

            /**
             * Drop added columns safely
             */
            foreach (['is_suspended', 'last_transaction_at', 'bank_verified'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }

            /**
             * Drop index safely
             */
            if (Schema::hasIndex('users', ['account_number', 'bank_code'])) {
                $table->dropIndex(['account_number', 'bank_code']);
            }
        });
    }
};
