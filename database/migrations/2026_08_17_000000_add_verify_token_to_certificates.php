<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Decouples public certificate verification from `cert_number`.
     *
     * `cert_number` (e.g. CERT-2026-L0014-00003) is sequential and was
     * being used directly as the lookup key for the public, unauthenticated
     * /verify endpoint — making every issued certificate's owner name and
     * investment amount enumerable by simply incrementing the sequence.
     *
     * `verify_token` is a random, unguessable value used for public lookups
     * instead. `cert_number` remains as-is for display purposes (it's
     * printed on the certificate and only ever meaningful to someone who
     * already holds a copy).
     */
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->string('verify_token', 40)->nullable()->after('cert_number');
        });

        // Backfill existing rows with a random token before enforcing uniqueness.
        DB::table('certificates')->whereNull('verify_token')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('certificates')
                    ->where('id', $row->id)
                    ->update(['verify_token' => Str::random(32)]);
            }
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->string('verify_token', 40)->nullable(false)->change();
            $table->unique('verify_token');
        });
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropUnique(['verify_token']);
            $table->dropColumn('verify_token');
        });
    }
};
