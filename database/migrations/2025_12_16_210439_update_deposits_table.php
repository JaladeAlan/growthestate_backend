<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            Schema::hasTable('deposits') &&
            Schema::hasColumn('deposits', 'amount')
        ) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->renameColumn('amount', 'amount_kobo');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('deposits') &&
            Schema::hasColumn('deposits', 'amount_kobo')
        ) {
            Schema::table('deposits', function (Blueprint $table) {
                $table->renameColumn('amount_kobo', 'amount');
            });
        }
    }
};
