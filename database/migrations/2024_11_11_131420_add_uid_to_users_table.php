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
            $table->uuid('uid')->nullable()->after('id');
        });

        // Step 2: Fill in existing records with UUIDs
        $users = DB::table('users')->whereNull('uid')->get();

        foreach ($users as $user) {
            DB::table('users')
                ->where('id', $user->id)
                ->update(['uid' => Str::uuid()->toString()]);
        }

        // Step 3: Make the column non-nullable and unique
        Schema::table('users', function (Blueprint $table) {
            $table->uuid('uid')->unique()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['uid']);
            $table->dropColumn('uid');
        });
    }
}
