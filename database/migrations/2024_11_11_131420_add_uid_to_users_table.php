<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class AddUidToUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        // Step 1: Add the 'uid' column first, without the unique constraint
        Schema::table('users', function (Blueprint $table) {
            $table->string('uid')->nullable()->after('id');
        });

        // Step 2: Ensure all existing records have unique values in the 'uid' column
        DB::table('users')->whereNull('uid')->update([
            'uid' => DB::raw('UUID()') // Generate a unique UUID for users without a 'uid'
        ]);

        // Step 3: Apply the unique constraint to the 'uid' column
        Schema::table('users', function (Blueprint $table) {
            $table->unique('uid');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        // Drop the unique constraint and the 'uid' column in the 'down' method
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });
    }
}
