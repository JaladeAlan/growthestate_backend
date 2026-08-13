<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * permission_role had no timestamp at all — no record of when a
     * permission was granted to a role. Added for the same reason
     * role_user has assigned_at: compliance needs to answer "was this
     * permission active on date X". Only created_at is added (not
     * updated_at) because a change to this pivot is a delete+insert
     * via the (role_id, permission_id) unique constraint, never an
     * in-place update.
     */
    public function up(): void
    {
        Schema::table('permission_role', function (Blueprint $table) {
            $table->timestamp('created_at')->useCurrent()->after('permission_id');
        });
    }

    public function down(): void
    {
        Schema::table('permission_role', function (Blueprint $table) {
            $table->dropColumn('created_at');
        });
    }
};
