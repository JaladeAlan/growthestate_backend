<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('support_tickets', 'guest_name')) {
                $table->string('guest_name')->nullable()->after('user_id');
            }
            if (! Schema::hasColumn('support_tickets', 'guest_email')) {
                $table->string('guest_email')->nullable()->after('guest_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['guest_name', 'guest_email']);
        });
    }
};
